<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';

$fontId = (int) ($_GET['id'] ?? 0);
$text = (string) ($_GET['text'] ?? '');

try {
    $path = preview_render($fontId, $text);

    header('Content-Type: image/png');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=86400');
    header('ETag: "' . hash('sha256', (string) file_get_contents($path)) . '"');

    readfile($path);
    exit;
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'rate_limited') {
        http_response_code(429);
        header('Retry-After: ' . preview_retry_after());
        echo 'Too many requests';
        exit;
    }
    http_response_code(500);
    echo 'Preview not available';
    exit;
} catch (InvalidArgumentException $e) {
    http_response_code(404);
    echo $e->getMessage();
    exit;
}
