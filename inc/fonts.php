<?php
declare(strict_types=1);

const FONT_ALLOWED_EXTENSIONS = ['ttf', 'otf', 'ttc', 'fon'];
const FONT_SUPPORTED_EXTENSIONS = ['ttf', 'otf'];

/** Thai script block: U+0E01..U+0E5B (consonants, vowels, tone marks). */
const FONT_THAI_BLOCK_START = 0x0E01;
const FONT_THAI_BLOCK_END = 0x0E5B;

/**
 * Search active fonts with pagination (24 per page default).
 * @return array{rows: list<array>, total: int, pages: int}
 */
function fonts_search(string $query, int $page = 1, int $perPage = 24, bool $thaiOnly = false): array
{
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $needle = mb_strtolower(trim($query), 'UTF-8');

    $rows = array_values(array_filter(storage_read('fonts.json'), function (array $r) use ($needle, $thaiOnly): bool {
        if (!($r['is_active'] ?? false)) {
            return false;
        }
        if ($thaiOnly && !($r['thai_supported'] ?? false)) {
            return false;
        }
        if ($needle === '') {
            return true;
        }
        return mb_stripos($r['file_name'] ?? '', $needle, 0, 'UTF-8') !== false
            || mb_stripos($r['display_name'] ?? '', $needle, 0, 'UTF-8') !== false;
    }));

    usort($rows, function (array $a, array $b): int {
        $cmp = strcmp($a['display_name'] ?? '', $b['display_name'] ?? '');
        return $cmp !== 0 ? $cmp : ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
    });

    $total = count($rows);
    $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

    return [
        'rows' => $slice,
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $perPage)),
    ];
}

function fonts_find_active(int $id): ?array
{
    return storage_find('fonts.json', fn (array $r) => (int) ($r['id'] ?? 0) === $id && ($r['is_active'] ?? false));
}

/**
 * Scan a directory once (read once, write once) and rebuild the catalog.
 * @return array{seen:int,inserted:int,updated:int,deactivated:int,supported:int,unsupported:int,errors:int}
 */
function fonts_scan(string $sourceRoot): array
{
    $root = realpath($sourceRoot);
    if ($root === false || !is_dir($root) || !is_readable($root)) {
        throw new InvalidArgumentException('Font source is not a readable directory: ' . $sourceRoot);
    }

    $rows = storage_read('fonts.json');
    $byName = [];
    foreach ($rows as $row) {
        $byName[$row['file_name']] = $row;
    }

    $report = ['seen' => 0, 'inserted' => 0, 'updated' => 0, 'deactivated' => 0, 'supported' => 0, 'unsupported' => 0, 'errors' => 0];
    $seenNames = [];
    $now = now_utc();

    $iterator = new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS);

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }

        $fileName = $file->getFilename();
        $extension = strtolower($file->getExtension());

        if (!in_array($extension, FONT_ALLOWED_EXTENSIONS, true)) {
            continue;
        }

        $report['seen']++;
        $seenNames[$fileName] = true;

        $status = in_array($extension, FONT_SUPPORTED_EXTENSIONS, true) ? 'supported' : 'unsupported';
        $modifiedAt = date('Y-m-d H:i:s', $file->getMTime());
        $displayName = pathinfo($fileName, PATHINFO_FILENAME);

        // Does the font contain Thai glyphs? (null = unknown / not previewable)
        $thaiSupported = $status === 'supported'
            ? font_supports_thai($file->getPathname())
            : null;

        if (isset($byName[$fileName])) {
            $byName[$fileName]['display_name'] = $displayName;
            $byName[$fileName]['extension'] = $extension;
            $byName[$fileName]['file_size'] = $file->getSize();
            $byName[$fileName]['modified_at'] = $modifiedAt;
            $byName[$fileName]['preview_status'] = $status;
            $byName[$fileName]['thai_supported'] = $thaiSupported;
            $byName[$fileName]['is_active'] = true;
            $byName[$fileName]['updated_at'] = $now;
            $report['updated']++;
        } else {
            $byName[$fileName] = [
                'id' => 0, // assigned below
                'file_name' => $fileName,
                'display_name' => $displayName,
                'extension' => $extension,
                'file_size' => $file->getSize(),
                'modified_at' => $modifiedAt,
                'preview_status' => $status,
                'thai_supported' => $thaiSupported,
                'last_error' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $report['inserted']++;
        }

        $report[$status === 'supported' ? 'supported' : 'unsupported']++;
    }

    // Assign ids to new rows and deactivate missing
    $nextId = 1;
    foreach ($byName as &$row) {
        if ((int) ($row['id'] ?? 0) === 0) {
            $row['id'] = $nextId++;
        }
    }
    unset($row);

    foreach ($byName as &$row) {
        if (($row['is_active'] ?? false) && !isset($seenNames[$row['file_name']])) {
            $row['is_active'] = false;
            $row['updated_at'] = $now;
            $report['deactivated']++;
        }
    }
    unset($row);

    storage_write('fonts.json', array_values($byName));

    return $report;
}

/**
 * Locate the best Unicode cmap subtable in a TTF/OTF font.
 *
 * @return array{0:int,1:int,2:string}|null [subtable format, absolute subtable offset, file bytes]
 */
function font_cmap_parse(string $fontPath): ?array
{
    $data = @file_get_contents($fontPath);
    if ($data === false || strlen($data) < 12 || substr($data, 0, 4) === 'ttcf') {
        return null; // TTC collections are not previewable in this app anyway
    }

    $numTables = unpack('n', substr($data, 4, 2))[1];
    $cmapOffset = null;
    for ($i = 0; $i < $numTables; $i++) {
        $entry = 12 + $i * 16;
        if ($entry + 16 > strlen($data)) {
            break;
        }
        if (substr($data, $entry, 4) === 'cmap') {
            $cmapOffset = unpack('Noffset', substr($data, $entry + 8, 4))['offset'];
            break;
        }
    }
    if ($cmapOffset === null) {
        return null;
    }

    $numSub = unpack('n', substr($data, $cmapOffset + 2, 2))[1];
    $best = null;
    $bestScore = -1;

    for ($i = 0; $i < $numSub; $i++) {
        $rec = $cmapOffset + 4 + $i * 8;
        $platform = unpack('n', substr($data, $rec, 2))[1];
        $encoding = unpack('n', substr($data, $rec + 2, 2))[1];
        $subOffset = unpack('Noffset', substr($data, $rec + 4, 4))['offset'];
        $sub = $cmapOffset + $subOffset;
        if ($sub + 2 > strlen($data)) {
            continue;
        }
        $format = unpack('n', substr($data, $sub, 2))[1];

        $score = -1;
        if ($platform === 3 && $encoding === 10) {
            $score = 100; // Windows UCS-4 (format 12)
        } elseif ($platform === 3 && $encoding === 1) {
            $score = 90;  // Windows Unicode BMP (format 4)
        } elseif ($platform === 0 && $encoding === 4) {
            $score = 85;  // Unicode 2.0+ full repertoire
        } elseif ($platform === 0 && $encoding === 3) {
            $score = 80;  // Unicode 2.0 BMP
        } elseif ($platform === 0 && $encoding === 6) {
            $score = 70;  // Unicode 2.1+
        } elseif ($platform === 0 && $encoding === 2) {
            $score = 60;  // Unicode 1.1 BMP
        } elseif ($platform === 0 && $encoding <= 1) {
            $score = 50;  // Unicode 1.0/1.1 (old Thai fonts use these)
        } elseif ($platform === 3 && $encoding === 0) {
            $score = 40;  // Windows Symbol (last resort)
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = [$format, $sub, $data];
        }
    }

    return $best;
}

/**
 * Check which of the given Unicode codepoints are mapped to a real (non-zero)
 * glyph in the font. Results are cached per font file + mtime for the request.
 *
 * @param string $fontPath absolute path to a TTF/OTF font
 * @param list<int> $codepoints
 * @return array<int,bool> codepoint => covered
 */
function font_glyph_coverage(string $fontPath, array $codepoints): array
{
    static $parsed = [];

    $mtime = @filemtime($fontPath);
    $key = $fontPath . ':' . $mtime;
    if (!isset($parsed[$key])) {
        $parsed[$key] = font_cmap_parse($fontPath);
    }
    $cmap = $parsed[$key];

    $covered = [];
    foreach ($codepoints as $cp) {
        $covered[$cp] = false;
    }
    if ($cmap === null) {
        return $covered;
    }

    [$format, $sub, $data] = $cmap;

    if ($format === 4) {
        $segCountX2 = unpack('n', substr($data, $sub + 6, 2))[1];
        $segCount = intdiv($segCountX2, 2);
        $endCodes = $sub + 14;
        $startCodes = $endCodes + $segCountX2 + 2;
        $idDeltas = $startCodes + $segCountX2;
        $idRangeOffsets = $idDeltas + $segCountX2;

        for ($s = 0; $s < $segCount; $s++) {
            $end = unpack('n', substr($data, $endCodes + $s * 2, 2))[1];
            $start = unpack('n', substr($data, $startCodes + $s * 2, 2))[1];
            if ($end === 0xFFFF && $start === 0xFFFF) {
                continue;
            }
            $idDelta = unpack('n', substr($data, $idDeltas + $s * 2, 2))[1];
            $idRangeOffset = unpack('n', substr($data, $idRangeOffsets + $s * 2, 2))[1];

            foreach ($codepoints as $cp) {
                if ($covered[$cp] || $cp < $start || $cp > $end) {
                    continue;
                }
                $gid = null;
                if ($idRangeOffset === 0) {
                    $gid = ($cp + $idDelta) & 0xFFFF;
                } else {
                    $entryPos = $idRangeOffsets + $s * 2 + $idRangeOffset + 2 * ($cp - $start);
                    if ($entryPos + 2 <= strlen($data)) {
                        $raw = unpack('n', substr($data, $entryPos, 2))[1];
                        $gid = $raw !== 0 ? ($raw + $idDelta) & 0xFFFF : 0;
                    }
                }
                if ($gid !== null && $gid !== 0) {
                    $covered[$cp] = true;
                }
            }
        }
    } elseif ($format === 12) {
        $nGroups = unpack('N', substr($data, $sub + 12, 4))[1];
        for ($g = 0; $g < $nGroups; $g++) {
            $go = $sub + 16 + $g * 12;
            $start = unpack('N', substr($data, $go, 4))[1];
            $end = unpack('N', substr($data, $go + 4, 4))[1];
            $startGid = unpack('N', substr($data, $go + 8, 4))[1];
            foreach ($codepoints as $cp) {
                if (!$covered[$cp] && $cp >= $start && $cp <= $end && ($startGid + ($cp - $start)) !== 0) {
                    $covered[$cp] = true;
                }
            }
        }
    } elseif ($format === 6) {
        $first = unpack('n', substr($data, $sub + 6, 2))[1];
        $count = unpack('n', substr($data, $sub + 8, 2))[1];
        foreach ($codepoints as $cp) {
            if (!$covered[$cp] && $cp >= $first && $cp < $first + $count) {
                $entry = $sub + 10 + 2 * ($cp - $first);
                $gid = unpack('n', substr($data, $entry, 2))[1];
                if ($gid !== 0) {
                    $covered[$cp] = true;
                }
            }
        }
    }

    return $covered;
}

/**
 * Whether the font contains glyphs for the Thai script block (U+0E01-U+0E5B).
 * Cached per font file + mtime.
 */
function font_supports_thai(string $fontPath): bool
{
    static $cache = [];

    $mtime = @filemtime($fontPath);
    $key = $fontPath . ':' . $mtime;
    if (!isset($cache[$key])) {
        $cache[$key] = false;
        $coverage = font_glyph_coverage($fontPath, range(FONT_THAI_BLOCK_START, FONT_THAI_BLOCK_END));
        foreach ($coverage as $covered) {
            if ($covered) {
                $cache[$key] = true;
                break;
            }
        }
    }

    return $cache[$key];
}
