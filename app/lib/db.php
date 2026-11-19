<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) $c['port'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // Keep NOW()/CURDATE() in the resort's timezone, matching PHP.
        $pdo->exec("SET time_zone = '+08:00'");
        $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
    }
    return $pdo;
}

/** Prepared statement helper. Never interpolate user input into $sql. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/**
 * Runs $fn inside a transaction; commits on success, rolls back on any throwable.
 * Nested calls join the outer transaction.
 */
function tx(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn($pdo);
    }
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function is_duplicate_key(PDOException $e, ?string $keyName = null): bool
{
    $info = $e->errorInfo;
    if (!is_array($info) || (int) ($info[1] ?? 0) !== 1062) {
        return false;
    }
    return $keyName === null || str_contains((string) ($info[2] ?? ''), $keyName);
}

/** A business-rule failure whose message is safe to show to the user. */
class UserError extends RuntimeException
{
}
