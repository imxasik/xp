<?php
declare(strict_types=1);

function store_find(string $file, callable $pred): ?array
{
    foreach (xp_read($file, []) as $row) {
        if ($pred($row)) {
            return $row;
        }
    }
    return null;
}

function store_push(string $file, array $row): array
{
    $all = xp_read($file, []);
    $all[] = $row;
    xp_write($file, $all);
    return $row;
}

function store_update(string $file, string $id, callable $mut): ?array
{
    $all = xp_read($file, []);
    $found = null;
    foreach ($all as $i => $row) {
        if (($row['id'] ?? '') === $id) {
            $all[$i] = $mut($row);
            $found = $all[$i];
            break;
        }
    }
    if ($found) {
        xp_write($file, $all);
    }
    return $found;
}

function store_delete(string $file, string $id): bool
{
    $all = xp_read($file, []);
    $n = count($all);
    $all = array_values(array_filter($all, fn($r) => ($r['id'] ?? '') !== $id));
    xp_write($file, $all);
    return count($all) < $n;
}

function store_replace(string $file, array $rows): void
{
    xp_write($file, array_values($rows));
}
