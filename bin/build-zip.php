<?php
declare(strict_types=1);

/**
 * Build the product bundle ZIP from the scanned font catalog.
 *
 * Usage:
 *   php bin/build-zip.php            # build only if missing
 *   php bin/build-zip.php --force    # rebuild even if it already exists
 *
 * Packs every active, supported (TTF/OTF) font listed in fonts.json into
 * PRODUCT_ZIP_PATH. Writes to a temp file first, then atomically renames it
 * over the old bundle so an in-flight download never reads a partial file.
 *
 * Requires ext/zip (ZipArchive). Enable it in php.ini: extension=zip
 */

require dirname(__DIR__) . '/inc/bootstrap.php';

$force = in_array('--force', $argv ?? [], true);

$productPath = (string) config('PRODUCT_ZIP_PATH', '');
if ($productPath === '') {
    fwrite(STDERR, "PRODUCT_ZIP_PATH is not set in .env\n");
    exit(1);
}

if (!$force && is_file($productPath)) {
    printf("Bundle already exists: %s (use --force to rebuild)\n", $productPath);
    exit(0);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive is not available.\n");
    fwrite(STDERR, "Enable it in php.ini: uncomment the line  extension=zip  then retry.\n");
    exit(1);
}

$sourceRoot = (string) config('FONT_SOURCE_PATH', '');
if ($sourceRoot === '' || !is_dir($sourceRoot)) {
    fwrite(STDERR, "FONT_SOURCE_PATH is not a valid directory: {$sourceRoot}\n");
    exit(1);
}

// Collect active, supported fonts from the catalog index.
$rows = storage_read('fonts.json');
$fonts = [];
foreach ($rows as $row) {
    if (!($row['is_active'] ?? false)) {
        continue;
    }
    if (!in_array(strtolower((string) ($row['extension'] ?? '')), ['ttf', 'otf'], true)) {
        continue;
    }
    $fonts[] = $row;
}

if ($fonts === []) {
    fwrite(STDERR, "No active supported fonts found in fonts.json — run bin/scan-fonts.php first\n");
    exit(1);
}

$productDir = dirname($productPath);
if (!is_dir($productDir) && !@mkdir($productDir, 0777, true)) {
    fwrite(STDERR, "Cannot create product directory: {$productDir}\n");
    exit(1);
}

$tmp = $productPath . '.' . getmypid() . '.tmp';
if (is_file($tmp)) {
    @unlink($tmp);
}

$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create ZIP at {$tmp}\n");
    exit(1);
}

$added = 0;
$skipped = 0;
$start = microtime(true);

foreach ($fonts as $row) {
    $fileName = (string) ($row['file_name'] ?? '');
    $src = $sourceRoot . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($src)) {
        $skipped++;
        continue;
    }
    if ($zip->addFile($src, 'fonts/' . $fileName)) {
        $added++;
    } else {
        $skipped++;
    }
}

$zip->addFromString(
    'README-ชุดฟอนต์.txt',
    "ชุดฟอนต์ FontSeller\n"
        . "จำนวนฟอนต์: {$added} ไฟล์ (TTF/OTF)\n"
        . "สร้างเมื่อ: " . now_utc() . " UTC\n"
        . "หมายเหตุ: ฟอนต์มาจาก C:\\Windows\\Fonts กรุณาตรวจสอบสิทธิ์การใช้งานก่อนใช้เชิงพาณิชย์\n"
);

$zip->close();

if (!is_file($tmp) || filesize($tmp) === 0) {
    fwrite(STDERR, "Build failed — no output produced\n");
    @unlink($tmp);
    exit(1);
}

// Atomic replace so an in-flight download never reads a partial file.
rename($tmp, $productPath);

printf(
    "added=%d skipped=%d size=%.1f MB elapsed=%.1fs\nstored at: %s\n",
    $added,
    $skipped,
    filesize($productPath) / 1048576,
    microtime(true) - $start,
    $productPath
);
