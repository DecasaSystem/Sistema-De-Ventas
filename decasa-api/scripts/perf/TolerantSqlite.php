<?php
// Arnés desechable de rendimiento: conexión SQLite que salta el SQL exclusivo
// de MySQL de las migraciones (ALTER ... MODIFY, ENUM, etc.). Solo para medir.

use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;

class TolerantSqliteConnection extends SQLiteConnection
{
    public static array $saltadas = [];

    private function mysqlOnly(string $sql): bool
    {
        return (bool) preg_match('/\bMODIFY\b|\bCHANGE\s+COLUMN\b|ENUM\s*\(|INFORMATION_SCHEMA|^\s*SHOW\b|CONVERT_TZ|DATE_FORMAT|\bAFTER\s+`|ON UPDATE CURRENT|\bUNSIGNED\b|DROP\s+INDEX|DROP\s+FOREIGN|ADD\s+CONSTRAINT|FULLTEXT|JSON_|\bIF\s*\(|SUBSTRING_INDEX|GROUP_CONCAT|STR_TO_DATE|DATE_SUB|NOW\(\)\s*-|INTERVAL\s+\d/i', $sql);
    }

    private function tolerar(callable $fn, string $sql, $default)
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            if ($this->mysqlOnly($sql) || str_contains($e->getMessage(), 'syntax error') || str_contains($e->getMessage(), 'no such function') || str_contains($e->getMessage(), 'unrecognized token') || str_contains($e->getMessage(), 'near "') || (getenv('PERF_MIGRANDO') && preg_match('/constraint failed|no such column|no such table|already exists|duplicate column/', $e->getMessage()))) {
                self::$saltadas[] = substr(preg_replace('/\s+/', ' ', $sql), 0, 110);
                return $default;
            }
            throw $e;
        }
    }

    public function statement($query, $bindings = [])
    {
        return $this->tolerar(fn () => parent::statement($query, $bindings), $query, true);
    }

    public function affectingStatement($query, $bindings = [])
    {
        return $this->tolerar(fn () => parent::affectingStatement($query, $bindings), $query, 0);
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if (! getenv('PERF_MIGRANDO')) return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
        return $this->tolerar(fn () => parent::select($query, $bindings, $useReadPdo, $fetchUsing), $query, []);
    }

    public function unprepared($query)
    {
        return $this->tolerar(fn () => parent::unprepared($query), $query, true);
    }
}
