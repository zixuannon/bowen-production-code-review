<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Read-only gate for a reviewed SQL import into an isolated disposable database. */
final class ProductionRestoreGuard
{
    /** @param list<string> $tenantDatabases @param list<string> $existingDatabases */
    public function assertTarget(string $target, string $centralDatabase, array $tenantDatabases, array $existingDatabases): void
    {
        if (!preg_match('/\Aeschool_incident_disposable_[A-Za-z0-9_]+\z/D', $target)) {
            throw new RuntimeException('Restore target must be an explicitly named incident disposable database.');
        }

        $protected = array_merge([$centralDatabase], $tenantDatabases);
        if (in_array($target, $protected, true)) {
            throw new RuntimeException('Restore target is an active or registered Production database.');
        }
        if (!in_array($target, $existingDatabases, true)) {
            throw new RuntimeException('Restore target does not exist; no database will be created by this guard.');
        }
    }

    public function assertEmptyTarget(int $tableCount): void
    {
        if ($tableCount !== 0) {
            throw new RuntimeException('Disposable restore target is not empty; existing data will not be overwritten.');
        }
    }

    /** @param list<string> $protectedDatabases */
    public function assertSqlFile(string $path, array $protectedDatabases): void
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) === 0) {
            throw new RuntimeException('Restore SQL is missing or unreadable.');
        }

        $compressed = str_ends_with(strtolower($path), '.gz');
        if ($compressed) {
            $integrity = new Process(['gzip', '-t', '--', $path]);
            $integrity->setTimeout(300);
            $integrity->run();
            if (!$integrity->isSuccessful()) {
                throw new RuntimeException('Compressed restore SQL failed gzip integrity verification.');
            }
        }
        $handle = $compressed ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Restore SQL could not be opened.');
        }

        try {
            $chunk = 0;
            $window = '';
            while (($sql = $compressed ? gzgets($handle, 8192) : fgets($handle, 8192)) !== false) {
                $chunk++;
                $window = substr($window, -256).$sql;
                // Fail closed even for comments/versioned MySQL directives: a reviewed
                // dump for a disposable import has no reason to select another DB.
                if (str_contains($window, "\0") || preg_match('/\bUSE\b|\b(?:CREATE|DROP|ALTER)\s+DATABASE\b|\bSQL_LOG_BIN\b|\b(?:PREPARE|EXECUTE|CALL)\b|\bLOAD\s+DATA\b|\bINTO\s+OUTFILE\b/i', $window)) {
                    throw new RuntimeException("Unsafe database directive in restore SQL at chunk {$chunk}.");
                }
                foreach ($protectedDatabases as $database) {
                    if ($database !== '' && preg_match('/(?<![A-Za-z0-9_])`?'.preg_quote($database, '/').'`?\s*\./i', $window)) {
                        throw new RuntimeException("Restore SQL references a protected database at chunk {$chunk}.");
                    }
                }
            }
            if ($compressed && !gzeof($handle)) {
                throw new RuntimeException('Compressed restore SQL is truncated or unreadable.');
            }
            if (!$compressed && !feof($handle)) {
                throw new RuntimeException('Restore SQL could not be read to EOF.');
            }
        } finally {
            $compressed ? gzclose($handle) : fclose($handle);
        }
    }
}
