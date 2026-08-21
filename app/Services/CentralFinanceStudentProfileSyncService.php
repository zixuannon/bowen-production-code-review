<?php

namespace App\Services;

use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceSyncEvent;
use App\Models\School;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Central-side ingestion for the first Central Finance contract checkpoint.
 * This service writes central reference projections only; it has no access to
 * tenant Finance tables and cannot create financial documents or balances.
 */
final class CentralFinanceStudentProfileSyncService
{
    public const SOURCE_TYPE = 'student_financial_profile';

    public function __construct(private readonly CentralFinanceStudentProfileSource $source) {}

    /** @return list<array{result:string,profile_id:int|null,event_id:int}> */
    public function syncSchool(School $school): array
    {
        // The source re-resolves the model from the trusted central registry.
        return array_map(fn (CentralFinanceStudentProfilePayload $payload): array => $this->synchronize($payload), $this->source->forSchool($school));
    }

    /** @return array{result:string,profile_id:int|null,event_id:int} */
    public function syncStudent(School $school, int $tenantStudentId): array
    {
        return $this->synchronize($this->source->forStudent($school, $tenantStudentId));
    }

    /** @return array{result:string,profile_id:int|null,event_id:int} */
    public function synchronize(CentralFinanceStudentProfilePayload $payload): array
    {
        $this->trustedSchool($payload->schoolId);
        $idempotencyKey = $this->idempotencyKey($payload);
        $payloadHash = hash('sha256', json_encode($payload->canonical(), JSON_THROW_ON_ERROR));

        return DB::connection('mysql')->transaction(function () use ($payload, $idempotencyKey, $payloadHash): array {
            $event = CentralFinanceSyncEvent::on('mysql')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($event !== null && $event->status !== 'failed') {
                if ($event->payload_hash !== $payloadHash) {
                    throw new RuntimeException('A Central Finance idempotency key cannot identify different source payloads.');
                }

                $event->increment('attempts');
                return ['result' => 'duplicate', 'profile_id' => null, 'event_id' => (int) $event->id];
            }

            if ($event !== null) {
                if ($event->payload_hash !== $payloadHash) {
                    throw new RuntimeException('A Central Finance idempotency key cannot identify different source payloads.');
                }
                $event->update(['status' => 'processing', 'error_code' => null, 'attempts' => $event->attempts + 1]);
            } else {
                $event = CentralFinanceSyncEvent::on('mysql')->create([
                    'school_id' => $payload->schoolId,
                    'source_type' => self::SOURCE_TYPE,
                    'source_uuid' => strtolower($payload->sourceUuid),
                    'source_version' => $payload->sourceVersion(),
                    'idempotency_key' => $idempotencyKey,
                    'correlation_id' => $payload->correlationId ?: (string) Str::uuid(),
                    'payload_hash' => $payloadHash,
                    'status' => 'processing',
                ]);
            }

            $byUuid = CentralFinanceStudentProfile::on('mysql')
                ->where('school_id', $payload->schoolId)->where('source_uuid', strtolower($payload->sourceUuid))->lockForUpdate()->first();
            $byTenantId = CentralFinanceStudentProfile::on('mysql')
                ->where('school_id', $payload->schoolId)->where('tenant_student_id', $payload->tenantStudentId)->lockForUpdate()->first();

            if (($byUuid && $byUuid->tenant_student_id !== $payload->tenantStudentId)
                || ($byTenantId && strtolower($byTenantId->source_uuid) !== strtolower($payload->sourceUuid))) {
                throw new RuntimeException('Central Finance rejected conflicting tenant student identities.');
            }

            $profile = $byUuid ?: $byTenantId;
            if ($profile !== null && $profile->source_updated_at !== null
                && CarbonImmutable::parse($profile->source_updated_at)->greaterThan($payload->sourceUpdatedAt)) {
                $event->update(['status' => 'ignored_stale', 'processed_at' => now()]);
                return ['result' => 'ignored_stale', 'profile_id' => (int) $profile->id, 'event_id' => (int) $event->id];
            }

            $attributes = [
                'school_id' => $payload->schoolId,
                'tenant_student_id' => $payload->tenantStudentId,
                'source_uuid' => strtolower($payload->sourceUuid),
                'class_id' => $payload->classId,
                'class_section_id' => $payload->classSectionId,
                'class_name' => $payload->className,
                'section_name' => $payload->sectionName,
                'admission_no' => $payload->admissionNo,
                'student_name' => $payload->studentName,
                'guardian_name' => $payload->guardianName,
                'guardian_email' => $payload->guardianEmail,
                'guardian_mobile' => $payload->guardianMobile,
                'enrollment_status' => $payload->enrollmentStatus,
                'tenant_user_status' => $payload->tenantUserStatus,
                'source_updated_at' => $payload->sourceUpdatedAt,
                'source_deleted_at' => $payload->sourceDeletedAt,
                'last_synced_at' => now(),
            ];
            if ($profile === null) {
                $profile = CentralFinanceStudentProfile::on('mysql')->create($attributes);
                $result = 'created';
            } else {
                $profile->fill($attributes)->save();
                $result = 'updated';
            }

            $event->update(['status' => 'processed', 'processed_at' => now()]);
            return ['result' => $result, 'profile_id' => (int) $profile->id, 'event_id' => (int) $event->id];
        });
    }

    /**
     * Delivery adapters may record a bounded failure after receiving a valid
     * payload but before central processing completes. A later call to
     * synchronize() retries the same idempotency key instead of creating a
     * second profile. This method deliberately stores no source payload.
     */
    public function recordDeliveryFailure(CentralFinanceStudentProfilePayload $payload, string $errorCode): void
    {
        $this->trustedSchool($payload->schoolId);
        if (!preg_match('/^[a-z0-9_.-]{1,80}$/i', $errorCode)) {
            throw new RuntimeException('Central Finance sync error codes must be bounded non-PII identifiers.');
        }

        $idempotencyKey = $this->idempotencyKey($payload);
        $payloadHash = hash('sha256', json_encode($payload->canonical(), JSON_THROW_ON_ERROR));
        DB::connection('mysql')->transaction(function () use ($payload, $idempotencyKey, $payloadHash, $errorCode): void {
            $event = CentralFinanceSyncEvent::on('mysql')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($event !== null) {
                if ($event->payload_hash !== $payloadHash || $event->status === 'processed') {
                    throw new RuntimeException('A completed or conflicting Central Finance event cannot be marked failed.');
                }
                $event->update(['status' => 'failed', 'error_code' => $errorCode, 'attempts' => $event->attempts + 1]);
                return;
            }

            CentralFinanceSyncEvent::on('mysql')->create([
                'school_id' => $payload->schoolId,
                'source_type' => self::SOURCE_TYPE,
                'source_uuid' => strtolower($payload->sourceUuid),
                'source_version' => $payload->sourceVersion(),
                'idempotency_key' => $idempotencyKey,
                'correlation_id' => $payload->correlationId ?: (string) Str::uuid(),
                'payload_hash' => $payloadHash,
                'status' => 'failed',
                'error_code' => $errorCode,
            ]);
        });
    }

    /** @return array{central_count:int,source_count:int,missing_in_central:list<string>,missing_in_source:list<string>,stale_in_central:list<string>,mismatched:list<string>} */
    public function reconcileSchool(School $school): array
    {
        $this->trustedSchool((int) $school->id);
        $source = $this->source->forSchool($school);
        $sourceByUuid = [];
        foreach ($source as $payload) {
            $sourceByUuid[strtolower($payload->sourceUuid)] = $payload;
        }
        $central = CentralFinanceStudentProfile::on('mysql')->where('school_id', $school->id)->get();
        $centralByUuid = $central->keyBy(static fn (CentralFinanceStudentProfile $profile): string => strtolower($profile->source_uuid));
        $sourceUuids = array_keys($sourceByUuid);
        $centralUuids = $centralByUuid->keys()->all();
        $stale = [];
        $mismatched = [];
        foreach (array_intersect($sourceUuids, $centralUuids) as $uuid) {
            $payload = $sourceByUuid[$uuid];
            /** @var CentralFinanceStudentProfile $profile */
            $profile = $centralByUuid[$uuid];
            if ($profile->source_updated_at !== null && CarbonImmutable::parse($profile->source_updated_at)->lessThan($payload->sourceUpdatedAt)) {
                $stale[] = $uuid;
            }
            if (!$this->profileMatchesPayload($profile, $payload)) {
                $mismatched[] = $uuid;
            }
        }

        return [
            'central_count' => count($centralUuids),
            'source_count' => count($sourceUuids),
            'missing_in_central' => array_values(array_diff($sourceUuids, $centralUuids)),
            'missing_in_source' => array_values(array_diff($centralUuids, $sourceUuids)),
            'stale_in_central' => $stale,
            'mismatched' => $mismatched,
        ];
    }

    private function profileMatchesPayload(CentralFinanceStudentProfile $profile, CentralFinanceStudentProfilePayload $payload): bool
    {
        return (int) $profile->tenant_student_id === $payload->tenantStudentId
            && (int) $profile->class_id === $payload->classId
            && (int) $profile->class_section_id === $payload->classSectionId
            && $profile->class_name === $payload->className
            && $profile->section_name === $payload->sectionName
            && $profile->admission_no === $payload->admissionNo
            && $profile->student_name === $payload->studentName
            && $profile->guardian_name === $payload->guardianName
            && $profile->guardian_email === $payload->guardianEmail
            && $profile->guardian_mobile === $payload->guardianMobile
            && $profile->enrollment_status === $payload->enrollmentStatus
            && $profile->tenant_user_status === $payload->tenantUserStatus
            && (($profile->source_deleted_at?->utc()->format('Y-m-d\\TH:i:s.u\\Z')) === ($payload->sourceDeletedAt?->utc()->format('Y-m-d\\TH:i:s.u\\Z')));
    }

    private function idempotencyKey(CentralFinanceStudentProfilePayload $payload): string
    {
        return hash('sha256', implode('|', [$payload->schoolId, self::SOURCE_TYPE, strtolower($payload->sourceUuid), $payload->sourceVersion()]));
    }

    private function trustedSchool(int $schoolId): School
    {
        return School::on('mysql')->findOrFail($schoolId);
    }
}
