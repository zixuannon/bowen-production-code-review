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
            $structuralWindow = '';
            $inQuotedValue = false;
            $escaped = false;
            $inLineComment = false;
            $inBlockComment = false;
            $lexerPending = '';
            while (($sql = $compressed ? gzgets($handle, 8192) : fgets($handle, 8192)) !== false) {
                $chunk++;
                $window = substr($window, -256).$sql;
                $combined = $lexerPending.$sql;
                $processable = substr($combined, 0, max(0, strlen($combined) - 3));
                $lexerPending = substr($combined, -3);
                if ($processable !== '') {
                    $structuralWindow = substr($structuralWindow, -256)
                        .$this->maskSingleQuotedValues($processable, $inQuotedValue, $escaped, $inLineComment, $inBlockComment);
                }
                $this->assertSafeWindow($window, $structuralWindow, $protectedDatabases, $chunk);
            }
            $structuralWindow = substr($structuralWindow, -256)
                .$this->maskSingleQuotedValues($lexerPending, $inQuotedValue, $escaped, $inLineComment, $inBlockComment);
            $this->assertSafeWindow($window, $structuralWindow, $protectedDatabases, $chunk + 1);
            if ($compressed && !gzeof($handle)) {
                throw new RuntimeException('Compressed restore SQL is truncated or unreadable.');
            }
            if (!$compressed && !feof($handle)) {
                throw new RuntimeException('Restore SQL could not be read to EOF.');
            }
            if ($inQuotedValue || $inBlockComment) {
                throw new RuntimeException('Restore SQL ends inside a quoted value or block comment.');
            }
        } finally {
            $compressed ? gzclose($handle) : fclose($handle);
        }
    }

    /** @param list<string> $protectedDatabases */
    private function assertSafeWindow(string $raw, string $structural, array $protectedDatabases, int $chunk): void
    {
        // Versioned MySQL comments remain visible; quoted historical values do not
        // become SQL directives merely because their text contains a protected name.
        if (str_contains($raw, "\0") || preg_match('/\bUSE\b|\b(?:CREATE|DROP|ALTER)\s+DATABASE\b|\bSQL_LOG_BIN\b|\b(?:PREPARE|EXECUTE|CALL)\b|\bLOAD\s+DATA\b|\bINTO\s+OUTFILE\b/i', $structural)) {
            throw new RuntimeException("Unsafe database directive in restore SQL at chunk {$chunk}.");
        }
        foreach ($protectedDatabases as $database) {
            if ($database !== '' && preg_match('/(?<![A-Za-z0-9_])`?'.preg_quote($database, '/').'`?\s*\./i', $structural)) {
                throw new RuntimeException("Restore SQL references a protected database at chunk {$chunk}.");
            }
        }
    }

    private function maskSingleQuotedValues(
        string $sql,
        bool &$inValue,
        bool &$escaped,
        bool &$inLineComment,
        bool &$inBlockComment,
    ): string
    {
        $masked = '';
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            if ($inLineComment) {
                $masked .= $char;
                if ($char === "\n") {
                    $inLineComment = false;
                }
            } elseif ($inBlockComment) {
                $masked .= $char;
                if ($char === '*' && $index + 1 < $length && $sql[$index + 1] === '/') {
                    $masked .= '/';
                    $index++;
                    $inBlockComment = false;
                }
            } elseif ($inValue) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === "'") {
                    if ($index + 1 < $length && $sql[$index + 1] === "'") {
                        $masked .= ' ';
                        $index++;
                    } else {
                        $inValue = false;
                    }
                }
                $masked .= ' ';
            } elseif ($char === '#' || ($char === '-' && substr($sql, $index, 2) === '--'
                && $index + 2 < $length && ctype_space($sql[$index + 2]))) {
                $inLineComment = true;
                $masked .= $char;
            } elseif ($char === '/' && substr($sql, $index, 2) === '/*'
                && substr($sql, $index, 3) !== '/*!') {
                $inBlockComment = true;
                $masked .= $char;
            } elseif ($char === "'") {
                $inValue = true;
                $masked .= ' ';
            } else {
                $masked .= $char;
            }
        }

        return $masked;
    }
}
