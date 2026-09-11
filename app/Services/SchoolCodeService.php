<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Canonical-only School identity resolver and concurrency-safe allocator. */
final class SchoolCodeService
{
    public const PREFIX = 'MMBOWEN';
    public const CANONICAL_PATTERN = '/^MMBOWEN[0-9]{2,}$/';

    public function resolveCanonical(?string $code): ?School
    {
        $code = $this->normalize($code);
        if ($code === '') {
            return null;
        }

        return School::on('mysql')->whereCanonicalCode($code)->first();
    }

    public function matchesCanonical(School $school, ?string $code): bool
    {
        return (int) ($this->resolveCanonical($code)?->id ?? 0) === (int) $school->id;
    }

    /** @param array<string,string> $trustedCodeToDatabase @return array<string,School>|null */
    public function resolveTrustedRegistry(array $trustedCodeToDatabase): ?array
    {
        $resolved = [];
        $schoolIds = [];
        foreach ($trustedCodeToDatabase as $code => $database) {
            $school = $this->resolveCanonical($code);
            if (!$school || $school->trashed() || !$school->database_name || !hash_equals($database, (string) $school->database_name) || isset($schoolIds[(int) $school->id])) {
                return null;
            }
            $resolved[$code] = $school;
            $schoolIds[(int) $school->id] = true;
        }

        return $resolved;
    }

    /** Display-only next value. Allocation is repeated under a row lock at write time. */
    public function previewNextCode(): string
    {
        $next = 1;
        if (Schema::connection('mysql')->hasTable('school_code_sequences')) {
            $next = max(1, (int) DB::connection('mysql')->table('school_code_sequences')->where('prefix', self::PREFIX)->value('next_number'));
        }

        return $this->format($next);
    }

    /** Must be called inside the same central transaction that creates the School. */
    public function allocateNextCode(): string
    {
        [$connection, $sequence] = $this->lockedSequence();

        $next = max(1, (int) $sequence->next_number);
        do {
            $code = $this->format($next++);
        } while ($this->isReserved($code));

        $this->advance($connection, $next);

        return $code;
    }

    /** Reserve a Super Admin-selected canonical code under the same sequence lock. */
    public function claimRequestedCode(?string $requestedCode): string
    {
        $code = $this->normalize($requestedCode);
        if (!preg_match(self::CANONICAL_PATTERN, $code)) {
            throw new RuntimeException('School Code must use the MMBOWEN## canonical format.');
        }

        [$connection, $sequence] = $this->lockedSequence();
        if ($this->isReserved($code)) {
            throw new RuntimeException('School Code is already in use or reserved by audited history.');
        }

        $number = (int) substr($code, strlen(self::PREFIX));
        $this->advance($connection, max((int) $sequence->next_number, $number + 1));

        return $code;
    }

    public function normalize(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    private function format(int $number): string
    {
        return self::PREFIX.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
    }

    /** @return array{0:\Illuminate\Database\ConnectionInterface,1:object} */
    private function lockedSequence(): array
    {
        if (!Schema::connection('mysql')->hasTable('school_code_sequences') || !Schema::connection('mysql')->hasTable('school_code_history')) {
            throw new RuntimeException('School Code identity schema is incomplete; canonical code allocation is disabled.');
        }

        $connection = DB::connection('mysql');
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Canonical School Code allocation requires a central database transaction.');
        }
        $sequence = $connection->table('school_code_sequences')->where('prefix', self::PREFIX)->lockForUpdate()->first();
        if (!$sequence) {
            throw new RuntimeException('School Code sequence is missing; canonical code allocation is disabled.');
        }

        return [$connection, $sequence];
    }

    private function isReserved(string $code): bool
    {
        if (School::on('mysql')->withTrashed()->whereCanonicalCode($code)->exists()) {
            return true;
        }

        return DB::connection('mysql')->table('school_code_history')->where('canonical_code', $code)->exists();
    }

    private function advance(\Illuminate\Database\ConnectionInterface $connection, int $next): void
    {
        $connection->table('school_code_sequences')->where('prefix', self::PREFIX)->update([
            'next_number' => $next,
            'updated_at' => now(),
        ]);
    }
}
