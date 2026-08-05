<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Exception;

use Weline\Framework\Database\Connection\Adapter\Pgsql\PgsqlIndexName;

final class UniqueConstraintViolationDetector
{
    public function matches(\Throwable $throwable, string $constraint, string $table = '', string $column = ''): bool
    {
        $diagnostics = [];
        do {
            $diagnostics[] = $throwable->getMessage();
            $diagnostics[] = (string)$throwable->getCode();
            if ($throwable instanceof \PDOException && is_array($throwable->errorInfo ?? null)) {
                $diagnostics[] = implode(' ', array_map('strval', $throwable->errorInfo));
            }
            $throwable = $throwable->getPrevious();
        } while ($throwable instanceof \Throwable);

        $diagnostic = strtolower(implode(' ', $diagnostics));
        $isUnique = str_contains($diagnostic, '23505')
            || str_contains($diagnostic, '23000')
            || str_contains($diagnostic, '1062')
            || str_contains($diagnostic, 'duplicate entry')
            || str_contains($diagnostic, 'duplicate key value violates unique constraint')
            || str_contains($diagnostic, 'unique constraint failed');
        if (!$isUnique) {
            return false;
        }

        $constraint = strtolower(trim($constraint));
        $explicitPostgresConstraints = [];
        if (preg_match_all(
            '/violates[ \t]+unique[ \t]+constraint[ \t]+"([^"\r\n]+)"/i',
            $diagnostic,
            $postgresMatches,
        ) > 0) {
            $explicitPostgresConstraints = array_map('strtolower', $postgresMatches[1]);
        }
        if ($explicitPostgresConstraints !== []) {
            $acceptedConstraints = [$constraint];
            if ($constraint !== '' && trim($table) !== '') {
                $acceptedConstraints = array_merge(
                    $acceptedConstraints,
                    PgsqlIndexName::candidates($table, $constraint),
                );
            }
            $acceptedConstraints = array_values(array_unique(array_map('strtolower', $acceptedConstraints)));
            return array_intersect($explicitPostgresConstraints, $acceptedConstraints) !== [];
        }
        if ($constraint !== '' && preg_match(
            '/(?<![a-z0-9_])' . preg_quote($constraint, '/') . '(?![a-z0-9_])/i',
            $diagnostic,
        ) === 1) {
            return true;
        }
        $table = strtolower(trim($table, "`\" "));
        $column = strtolower(trim($column, "`\" "));
        if ($table === '' || $column === '') {
            return false;
        }
        if (preg_match(
            '/unique constraint failed:\s*[`"]?' . preg_quote($table, '/')
            . '[`"]?\.[`"]?' . preg_quote($column, '/') . '[`"]?(?![a-z0-9_])/i',
            $diagnostic,
        ) === 1) {
            return true;
        }
        if (preg_match_all('/key\s*\(([^)]*)\)\s*=\s*\(/i', $diagnostic, $keyMatches) < 1) {
            return false;
        }
        foreach ($keyMatches[1] as $keyColumns) {
            $columns = array_map(
                static fn(string $value): string => strtolower(trim($value, "`\" \t\n\r\0\x0B")),
                preg_split('/\s*,\s*/', (string)$keyColumns) ?: [],
            );
            if (in_array($column, $columns, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match an unnamed-diagnostic fallback only when the complete unique-key
     * column list is the expected list. Named constraints keep their exact
     * identity semantics from matches().
     *
     * @param non-empty-list<string> $columns
     */
    public function matchesExactColumns(
        \Throwable $throwable,
        string $constraint,
        string $table,
        array $columns,
    ): bool {
        $columns = array_values(array_map(
            static fn(string $column): string => strtolower(trim($column, "`\" \t\n\r\0\x0B")),
            $columns,
        ));
        if ($columns === [] || in_array('', $columns, true)) {
            throw new \InvalidArgumentException('Expected unique-key columns must not be empty.');
        }
        if (!$this->matches($throwable, $constraint, $table, $columns[0])) {
            return false;
        }

        $diagnostics = [];
        do {
            $diagnostics[] = $throwable->getMessage();
            $diagnostics[] = (string)$throwable->getCode();
            if ($throwable instanceof \PDOException && is_array($throwable->errorInfo ?? null)) {
                $diagnostics[] = implode(' ', array_map('strval', $throwable->errorInfo));
            }
            $throwable = $throwable->getPrevious();
        } while ($throwable instanceof \Throwable);
        $diagnostic = strtolower(implode("\n", $diagnostics));

        // PostgreSQL exposes the authoritative physical constraint name.
        if (preg_match('/violates[ \t]+unique[ \t]+constraint[ \t]+"[^"\r\n]+"/i', $diagnostic) === 1) {
            return true;
        }
        // MySQL and compatible drivers normally expose the declared/logical
        // key name. matches() has already bounded this token.
        $constraint = strtolower(trim($constraint));
        if ($constraint !== '' && preg_match(
            '/(?<![a-z0-9_])' . preg_quote($constraint, '/') . '(?![a-z0-9_])/i',
            $diagnostic,
        ) === 1) {
            return true;
        }

        if (preg_match_all('/unique constraint failed:\s*([^\r\n]+)/i', $diagnostic, $sqliteMatches) > 0) {
            foreach ($sqliteMatches[1] as $columnList) {
                $actual = array_map(
                    static function (string $value): string {
                        $value = strtolower(trim($value, "`\" \t\n\r\0\x0B"));
                        $parts = preg_split('/\s*\.\s*/', $value) ?: [];
                        return trim((string)end($parts), "`\" \t\n\r\0\x0B");
                    },
                    preg_split('/\s*,\s*/', trim((string)$columnList)) ?: [],
                );
                if ($actual === $columns) {
                    return true;
                }
            }
        }

        if (preg_match_all('/key\s*\(([^)]*)\)\s*=\s*\(/i', $diagnostic, $keyMatches) > 0) {
            foreach ($keyMatches[1] as $keyColumns) {
                $actual = array_map(
                    static fn(string $value): string => strtolower(trim($value, "`\" \t\n\r\0\x0B")),
                    preg_split('/\s*,\s*/', (string)$keyColumns) ?: [],
                );
                if ($actual === $columns) {
                    return true;
                }
            }
        }
        return false;
    }
}
