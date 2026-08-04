<?php
declare(strict_types=1);

/**
 * Simple JSON file storage helpers.
 */

function storage_path(string $name): string
{
    $base = rtrim((string) config('STORAGE_PATH', dirname(__DIR__) . '/storage/data'), '/\\');
    return $base . '/' . $name;
}

function storage_read(string $name): array
{
    $file = storage_path($name);
    if (!file_exists($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $data = json_decode($raw === false ? '' : $raw, true);
    return is_array($data) ? array_values($data) : [];
}

function storage_write(string $name, array $rows): void
{
    $file = storage_path($name);
    @mkdir(dirname($file), 0777, true);

    $json = json_encode(
        array_values($rows),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $tmp = $file . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, $json, LOCK_EX);
    rename($tmp, $file);
}

function storage_find(string $name, callable $predicate): ?array
{
    foreach (storage_read($name) as $row) {
        if ($predicate($row)) {
            return $row;
        }
    }
    return null;
}

function storage_insert(string $name, array $record): int
{
    $rows = storage_read($name);

    $nextId = 1;
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) >= $nextId) {
            $nextId = (int) $row['id'] + 1;
        }
    }

    $record['id'] = $nextId;
    $rows[] = $record;
    storage_write($name, $rows);

    return $nextId;
}

function storage_update(string $name, int $id, callable $mutator): bool
{
    $rows = storage_read($name);
    foreach ($rows as $i => $row) {
        if ((int) ($row['id'] ?? 0) === $id) {
            $rows[$i] = $mutator($row);
            storage_write($name, $rows);
            return true;
        }
    }
    return false;
}

function storage_delete_where(string $name, callable $predicate): int
{
    $rows = storage_read($name);
    $kept = [];
    $count = 0;

    foreach ($rows as $row) {
        if ($predicate($row)) {
            $count++;
        } else {
            $kept[] = $row;
        }
    }

    if ($count > 0) {
        storage_write($name, $kept);
    }
    return $count;
}

function storage_update_where(string $name, callable $predicate, callable $mutator): int
{
    $rows = storage_read($name);
    $count = 0;

    foreach ($rows as $i => $row) {
        if ($predicate($row)) {
            $rows[$i] = $mutator($row);
            $count++;
        }
    }

    if ($count > 0) {
        storage_write($name, $rows);
    }
    return $count;
}
