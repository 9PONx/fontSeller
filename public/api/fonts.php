<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';

header('Cache-Control: no-store');

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q, 'UTF-8') > 100) {
    json_response(['error' => 'Query too long'], 400);
}
$limit = min(max((int) ($_GET['limit'] ?? 500), 1), 1000);

/**
 * Active TTF/OTF font names matching the query (same rules as public/index.php).
 *
 * @return list<string> natural-sorted display names
 */
function api_font_names(string $query): array
{
    $needle = $query === '' ? '' : mb_strtolower($query, 'UTF-8');
    $names = [];

    foreach (storage_read('fonts.json') as $row) {
        if (!($row['is_active'] ?? false)) {
            continue;
        }
        $ext = strtolower((string) ($row['extension'] ?? ''));
        if (!in_array($ext, ['ttf', 'otf'], true)) {
            continue;
        }

        $display = trim((string) ($row['display_name'] ?? ''));
        $file = (string) ($row['file_name'] ?? '');

        if ($needle !== '') {
            $haystack = mb_strtolower($display . "\n" . $file, 'UTF-8');
            if (mb_strpos($haystack, $needle) === false) {
                continue;
            }
        }

        $names[] = $display !== '' ? $display : $file;
    }

    usort($names, 'strnatcasecmp');
    return $names;
}

$all = api_font_names($q);
$total = count($all);
$names = array_slice($all, 0, $limit);

json_response([
    'q' => $q,
    'total' => $total,
    'returned' => count($names),
    'truncated' => $total > count($names),
    'names' => $names,
]);
