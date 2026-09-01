<?php

namespace App\Services;

use App\Models\CentralFinanceImportBatch;
use App\Models\CentralFinanceImportRowReservation;
use App\Models\CentralFinanceUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Shared Central Finance import workflow. Concrete importers own parsing and
 * source-document creation; this service owns preview metadata, authorization,
 * replay protection, confirm-time revalidation, and transaction boundaries.
 *
 * Ledger, Bank Transfer, and Fund Handover are deliberately not import types.
 */
final class CentralFinanceImportBatchService
{
    public const ALLOWED_TYPES = ['expense', 'payment', 'other_income'];
    private const EXPIRY_MINUTES = 30;

    public function __construct(private readonly CentralFinanceWorkspaceService $workspace, private readonly CentralFinanceDocumentAuditService $audits) {}

    /** Discard only an unconfirmed preview. Confirmed/failed rows remain immutable audit history. */
    public function discard(CentralFinanceUser $actor, string $token, string $reason): CentralFinanceImportBatch
    {
        $reason = trim($reason);
        if ($reason === '') throw new InvalidArgumentException('An import discard reason is required.');
        return DB::connection('mysql')->transaction(function () use ($actor, $token, $reason): CentralFinanceImportBatch {
            $batch = CentralFinanceImportBatch::on('mysql')->where('token', $token)->lockForUpdate()->firstOrFail();
            $this->workspace->assertCanOperateSchool($actor, (int) $batch->school_id);
            if ((int) $batch->uploaded_by !== $actor->id || $batch->status !== CentralFinanceImportBatch::STATUS_PENDING) {
                throw new AuthorizationException('Only the uploader may discard a pending Central Finance import preview.');
            }
            $before = $batch->only(['status', 'total_rows', 'valid_rows', 'error_rows', 'file_hash']);
            $batch->update(['status' => CentralFinanceImportBatch::STATUS_DISCARDED, 'failure_reason' => $reason]);
            $this->releaseRows($batch);
            $this->audits->record($actor, $batch, 'import_batch', 'discarded', $reason, $before, $batch->only(['status', 'failure_reason']));
            return $batch->fresh();
        });
    }

    /**
     * @param list<array{idempotency_key:string,data:array<string,mixed>,errors?:list<string>}> $rows
     */
    public function preview(CentralFinanceUser $actor, int $schoolId, string $importType, string $templateVersion, string $fileName, string $fileHash, array $rows): CentralFinanceImportBatch
    {
        $school = $this->workspace->assertCanOperateSchool($actor, $schoolId);
        $this->assertImportDefinition($importType, $templateVersion, $fileName, $fileHash, $rows);

        try {
            return DB::connection('mysql')->transaction(function () use ($actor, $school, $importType, $templateVersion, $fileName, $fileHash, $rows): CentralFinanceImportBatch {
                $preview = $this->normaliseRows($rows, $importType, $school->id);
                $batch = CentralFinanceImportBatch::on('mysql')->create([
                'import_type' => $importType,
                'template_version' => $templateVersion,
                'school_id' => $school->id,
                'uploaded_by' => $actor->id,
                'file_name' => $fileName,
                'file_hash' => strtolower($fileHash),
                'preview_data' => $preview,
                'summary' => $this->summary($preview),
                'status' => CentralFinanceImportBatch::STATUS_PENDING,
                'total_rows' => count($preview),
                'valid_rows' => collect($preview)->where('status', 'valid')->count(),
                'error_rows' => collect($preview)->where('status', 'error')->count(),
                'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            ]);

                foreach ($preview as $row) {
                    if ($row['status'] !== 'valid') {
                        continue;
                    }
                    CentralFinanceImportRowReservation::on('mysql')->create([
                        'batch_id' => $batch->id, 'school_id' => $school->id,
                        'import_type' => $importType, 'row_number' => $row['row_number'],
                        'idempotency_key' => $row['idempotency_key'],
                        'status' => CentralFinanceImportRowReservation::STATUS_RESERVED,
                    ]);
                }

                return $batch;
            });
        } catch (QueryException $exception) {
            throw new InvalidArgumentException('This Central Finance file or row has already been submitted and cannot be replayed.', previous: $exception);
        }
    }

    /**
     * @param callable(array<string,mixed>):list<string> $revalidate returns row errors
     * @param callable(list<array<string,mixed>>,CentralFinanceImportBatch):void $confirm performs one concrete document transaction
     */
    public function confirm(CentralFinanceUser $actor, string $token, callable $revalidate, callable $confirm): CentralFinanceImportBatch
    {
        $confirmationStarted = false;
        try {
            return DB::connection('mysql')->transaction(function () use ($actor, $token, $revalidate, $confirm, &$confirmationStarted): CentralFinanceImportBatch {
                $batch = CentralFinanceImportBatch::on('mysql')->where('token', $token)->lockForUpdate()->firstOrFail();
                $this->workspace->assertCanOperateSchool($actor, (int) $batch->school_id);
                if ($batch->uploaded_by !== $actor->id || $batch->status !== CentralFinanceImportBatch::STATUS_PENDING) {
                    throw new AuthorizationException('This Central Finance import batch cannot be confirmed by this actor.');
                }
                if ($batch->expires_at && now()->greaterThan($batch->expires_at)) {
                    $batch->update(['status' => CentralFinanceImportBatch::STATUS_EXPIRED]);
                    $this->releaseRows($batch);
                    throw new InvalidArgumentException('This Central Finance import preview has expired.');
                }
                if ($batch->error_rows > 0) {
                    throw new InvalidArgumentException('Correct every invalid import row before confirmation.');
                }

                // Only an owner that passed both scope checks may turn a
                // retryable preview into a failed audit record.
                $confirmationStarted = true;
                $batch->update(['status' => CentralFinanceImportBatch::STATUS_PROCESSING]);
                $rows = $batch->preview_data ?? [];
                foreach ($rows as $row) {
                    $errors = array_values($revalidate($row));
                    if ($errors !== []) {
                        throw new InvalidArgumentException('Row '.($row['row_number'] ?? '?').': '.implode('; ', $errors));
                    }
                }

                $confirm($rows, $batch);
                CentralFinanceImportRowReservation::on('mysql')->where('batch_id', $batch->id)->where('status', CentralFinanceImportRowReservation::STATUS_RESERVED)->update(['status' => CentralFinanceImportRowReservation::STATUS_CONFIRMED]);
                $batch->update(['status' => CentralFinanceImportBatch::STATUS_COMPLETED, 'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'failure_reason' => null]);

                return $batch->fresh();
            });
        } catch (\Throwable $exception) {
            if ($confirmationStarted) {
                $this->markFailed($token, $actor->id, $exception);
            }
            throw $exception;
        }
    }

    private function assertImportDefinition(string $importType, string $templateVersion, string $fileName, string $fileHash, array $rows): void
    {
        if (!in_array($importType, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('Central Ledger, Transfer, and Handover cannot be imported.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $templateVersion) || trim($fileName) === '' || !preg_match('/^[a-f0-9]{64}$/i', $fileHash)) {
            throw new InvalidArgumentException('The Central Finance import definition is invalid.');
        }
        if ($rows === [] || count($rows) > 500) {
            throw new InvalidArgumentException('A Central Finance import must contain between 1 and 500 rows.');
        }
    }

    /** @param list<array{idempotency_key:string,data:array<string,mixed>,errors?:list<string>}> $rows @return list<array<string,mixed>> */
    private function normaliseRows(array $rows, string $importType, int $schoolId): array
    {
        $keys = [];
        return array_map(function (array $row, int $offset) use (&$keys, $importType, $schoolId): array {
            $key = trim((string) ($row['idempotency_key'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $key)) {
                throw new InvalidArgumentException('Each Central Finance import row requires a stable idempotency key.');
            }
            if (isset($keys[$key])) {
                throw new InvalidArgumentException('Duplicate idempotency key in the uploaded file.');
            }
            $keys[$key] = true;
            if (CentralFinanceImportRowReservation::on('mysql')->where(['school_id' => $schoolId, 'import_type' => $importType, 'idempotency_key' => $key])->whereIn('status', [CentralFinanceImportRowReservation::STATUS_RESERVED, CentralFinanceImportRowReservation::STATUS_CONFIRMED])->exists()) {
                throw new InvalidArgumentException('A Central Finance import row has already been submitted or confirmed.');
            }
            $errors = array_values(array_filter(array_map('strval', $row['errors'] ?? []), static fn (string $error): bool => trim($error) !== ''));
            return ['row_number' => $offset + 2, 'idempotency_key' => $key, 'status' => $errors === [] ? 'valid' : 'error', 'errors' => $errors, 'data' => $row['data'] ?? []];
        }, $rows, array_keys($rows));
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    private function summary(array $rows): array
    {
        $valid = collect($rows)->where('status', 'valid')->count();
        return ['total' => count($rows), 'valid' => $valid, 'error' => count($rows) - $valid];
    }

    private function releaseRows(CentralFinanceImportBatch $batch): void
    {
        CentralFinanceImportRowReservation::on('mysql')->where('batch_id', $batch->id)->where('status', CentralFinanceImportRowReservation::STATUS_RESERVED)->update(['status' => CentralFinanceImportRowReservation::STATUS_RELEASED]);
    }

    private function markFailed(string $token, int $actorId, \Throwable $exception): void
    {
        DB::connection('mysql')->transaction(function () use ($token, $actorId, $exception): void {
            $batch = CentralFinanceImportBatch::on('mysql')->where('token', $token)->where('uploaded_by', $actorId)->lockForUpdate()->first();
            if ($batch === null || !in_array($batch->status, [CentralFinanceImportBatch::STATUS_PENDING, CentralFinanceImportBatch::STATUS_PROCESSING], true)) {
                return;
            }
            $batch->update(['status' => CentralFinanceImportBatch::STATUS_FAILED, 'failure_reason' => Str::limit($exception->getMessage(), 2000)]);
            $this->releaseRows($batch);
        });
    }
}
