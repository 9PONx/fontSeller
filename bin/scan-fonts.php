<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inc/bootstrap.php';

$sourcePath = (string) config('FONT_SOURCE_PATH');

// Optional override in testing mode: php bin/scan-fonts.php --path=C:\tmp\fonts
if (config('APP_ENV', 'local') === 'testing') {
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--path=')) {
            $sourcePath = substr($arg, 7);
        }
    }
}

try {
    $report = fonts_scan($sourcePath);
    printf(
        "seen=%d inserted=%d updated=%d deactivated=%d supported=%d unsupported=%d errors=%d\n",
        $report['seen'],
        $report['inserted'],
        $report['updated'],
        $report['deactivated'],
        $report['supported'],
        $report['unsupported'],
        $report['errors']
    );
    echo 'Stored at: ' . storage_path('fonts.json') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Scan failed: ' . $e->getMessage() . "\n");
    exit(1);
}
