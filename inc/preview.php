<?php
declare(strict_types=1);

const PREVIEW_RENDERER_VERSION = 'renderer-v2'; // bump to invalidate all cached PNGs

/**
 * Render a cached PNG preview for a supported font (TTF/OTF).
 * @throws InvalidArgumentException  for invalid input / unsupported font
 * @throws RuntimeException          on rendering failure
 * @return string absolute path to the PNG file
 */
function preview_render(int $fontId, string $text): string
{
    $text = preview_normalize_text($text);

    $font = fonts_find_active($fontId);
    if ($font === null) {
        throw new InvalidArgumentException('Font not found');
    }
    if ($font['preview_status'] !== 'supported') {
        throw new InvalidArgumentException('unsupported');
    }

    if (preview_rate_limited()) {
        throw new RuntimeException('rate_limited');
    }

    $fontPath = preview_resolve_font($font['file_name']);

    $key = hash('sha256', implode("\0", [
        (string) $font['id'],
        (string) strtotime($font['modified_at']),
        $text,
        PREVIEW_RENDERER_VERSION,
        '1200x140@42',
    ]));

    $cacheRoot = rtrim((string) config('PREVIEW_CACHE_PATH', dirname(__DIR__) . '/storage/cache/previews'), '/\\');
    $cacheDir = $cacheRoot . '/' . substr($key, 0, 2);
    $cacheFile = $cacheDir . '/' . $key . '.png';

    if (is_file($cacheFile) && filesize($cacheFile) > 0) {
        return $cacheFile;
    }

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }

    $tmp = $cacheDir . '/' . $key . '.' . getmypid() . '.tmp';

    try {
        preview_render_gd($fontPath, $text, $tmp);
        if (file_get_contents($tmp, false, null, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('Rendered file is not a valid PNG');
        }
        rename($tmp, $cacheFile);
    } catch (\Throwable $e) {
        @unlink($tmp);
        throw $e;
    }

    return $cacheFile;
}

function preview_normalize_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = str_replace("\n", ' ', $text);
    $text = trim($text);

    if ($text === '') {
        throw new InvalidArgumentException('กรุณากรอกข้อความตัวอย่าง');
    }
    if (mb_strlen($text, 'UTF-8') > config_int('PREVIEW_MAX_TEXT_LENGTH', 80)) {
        throw new InvalidArgumentException('ข้อความยาวเกิน 80 ตัวอักษร');
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text)) {
        throw new InvalidArgumentException('ข้อความมีตัวอักษรควบคุมที่ไม่ได้รับอนุญาต');
    }

    return $text;
}

function preview_resolve_font(string $fileName): string
{
    if (str_contains($fileName, '/') || str_contains($fileName, '\\') || str_contains($fileName, '..')) {
        throw new InvalidArgumentException('Invalid font filename');
    }

    $root = realpath((string) config('FONT_SOURCE_PATH'));
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('Font source path is not accessible');
    }

    $full = $root . DIRECTORY_SEPARATOR . $fileName;
    $canonical = realpath($full);

    if ($canonical === false || !str_starts_with($canonical, $root . DIRECTORY_SEPARATOR)) {
        throw new InvalidArgumentException('Font file is outside the allowed directory');
    }

    return $canonical;
}

function preview_rate_limited(): bool
{
    site_session_start();
    $now = time();
    $hits = array_values(array_filter(
        $_SESSION['preview_hits'] ?? [],
        fn (int $t) => $t > $now - 60
    ));

    if (count($hits) >= 60) {
        $_SESSION['preview_hits'] = $hits;
        return true;
    }

    $hits[] = $now;
    $_SESSION['preview_hits'] = $hits;
    return false;
}

function preview_retry_after(): int
{
    site_session_start();
    $hits = $_SESSION['preview_hits'] ?? [];
    if (empty($hits)) {
        return 0;
    }
    return max(0, min($hits) + 60 - time());
}

function preview_render_gd(string $fontPath, string $text, string $outputPath): void
{
    $width = 1200;
    $height = 140;
    $fontSize = 42;
    $bg = 0xF8FAFC;   // slate-50
    $fg = 0x111827;   // gray-900

    $image = imagecreatetruecolor($width, $height);
    if ($image === false) {
        throw new RuntimeException('Failed to create image');
    }

    try {
        $bgColor = imagecolorallocate($image, ($bg >> 16) & 0xFF, ($bg >> 8) & 0xFF, $bg & 0xFF);
        $fgColor = imagecolorallocate($image, ($fg >> 16) & 0xFF, ($fg >> 8) & 0xFF, $fg & 0xFF);

        imagefill($image, 0, 0, $bgColor);

        // Split the text into runs: characters the font cannot draw fall back to
        // a Thai-capable system font, so the strip never shows tofu boxes.
        $runs = preview_text_runs($text, $fontPath);
        if (empty($runs)) {
            throw new RuntimeException('Nothing to render');
        }

        $layout = [];
        $totalWidth = 0;
        foreach ($runs as $run) {
            $bbox = imagettfbbox($fontSize, 0, $run['font'], $run['text']);
            if ($bbox === false) {
                throw new RuntimeException('Failed to measure text');
            }
            $runWidth = $bbox[2] - $bbox[0];
            $layout[] = ['run' => $run, 'bbox' => $bbox, 'width' => $runWidth];
            $totalWidth += $runWidth + 1; // +1px breathing gap between runs
        }

        $x = max(20, (int) (($width - $totalWidth) / 2));

        foreach ($layout as $piece) {
            $textHeight = $piece['bbox'][1] - $piece['bbox'][7];
            $y = (int) (($height + $textHeight) / 2);

            if (imagettftext($image, $fontSize, 0, $x, $y, $fgColor, $piece['run']['font'], $piece['run']['text']) === false) {
                throw new RuntimeException('Failed to render text');
            }

            $x += $piece['width'] + 1;
        }

        if (!imagepng($image, $outputPath)) {
            throw new RuntimeException('Failed to save PNG');
        }
    } finally {
        imagedestroy($image);
    }
}

/**
 * Split the preview text into runs of consecutive characters the target font
 * can draw, alternating with runs rendered by a Thai-capable fallback font.
 * Keeps each run in a single imagettftext() call so FreeType can still apply
 * mark positioning (tone marks) inside the run.
 *
 * @return list<array{text:string,font:string}>
 */
function preview_text_runs(string $text, string $fontPath): array
{
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($chars === []) {
        return [];
    }

    $codepoints = array_map(fn (string $ch): int => mb_ord($ch, 'UTF-8'), $chars);
    $coverage = font_glyph_coverage($fontPath, array_values(array_unique($codepoints)));
    $fallback = preview_fallback_font();

    $runs = [];
    $currentText = '';
    $currentFont = null;

    foreach ($chars as $i => $ch) {
        $covered = (bool) ($coverage[$codepoints[$i]] ?? false);
        $font = $covered ? $fontPath : $fallback;

        if ($font === null) {
            $font = $fontPath; // no fallback available; render as-is
        }

        if ($currentFont === null) {
            $currentFont = $font;
            $currentText = $ch;
            continue;
        }

        if ($font !== $currentFont) {
            $runs[] = ['text' => $currentText, 'font' => $currentFont];
            $currentText = $ch;
            $currentFont = $font;
        } else {
            $currentText .= $ch;
        }
    }

    if ($currentText !== '') {
        $runs[] = ['text' => $currentText, 'font' => $currentFont ?? $fontPath];
    }

    return $runs;
}

/**
 * Pick a system font that can render Thai, to substitute missing glyphs.
 * Returns null when no candidate is usable (falls back to tofu-less rendering
 * with the target font alone).
 */
function preview_fallback_font(): ?string
{
    static $resolved = false;
    if ($resolved !== false) {
        return $resolved;
    }

    $resolved = null;
    $root = realpath((string) config('FONT_SOURCE_PATH'));
    if ($root === false || !is_dir($root)) {
        return $resolved;
    }

    $candidates = [
        'Tahoma.ttf',
        'Sarabun-Regular.ttf',
        'LeelawadeeUI.ttf',
        'Segoe UI.ttf',
        'segoeui.ttf',
    ];

    foreach ($candidates as $name) {
        $candidate = $root . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate) && font_supports_thai($candidate)) {
            $resolved = $candidate;
            break;
        }
    }

    return $resolved;
}
