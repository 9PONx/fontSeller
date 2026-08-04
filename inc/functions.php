<?php
declare(strict_types=1);

function is_https_request(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function site_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name((string) config('APP_SESSION_NAME', 'fontseller_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        // Never set the Secure flag over plain HTTP: browsers refuse to send Secure
        // cookies on http:// (non-localhost), which silently breaks sessions/CSRF.
        // Auto-detect HTTPS so the same config works in production behind a proxy too.
        'secure' => is_https_request(),
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    site_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(?string $token): bool
{
    site_session_start();
    return is_string($token) && $token !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function money_thai(int $satang): string
{
    return number_format($satang / 100, 2) . ' THB';
}

/**
 * User-visible base path, e.g. "" (web root) or "/FontSeller" (Laragon subfolder).
 * Detected from SCRIPT_NAME so the site works under both setups.
 */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    // Strip the /public part of the path so links point at the user-visible URL.
    $script = (string) preg_replace('#/public(/|$)#', '/', $script);
    $base = rtrim((string) dirname($script), '/');

    return $base;
}

/** Build an absolute URL for a path (starts with '/'). */
function url(string $path = '/'): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function error_page(int $status, string $message): never
{
    http_response_code($status);
    page_header('Error');
    echo '<div class="max-w-md mx-auto px-4 py-16 text-center">';
    echo '<h1 class="text-5xl font-bold text-gray-300 mb-3">' . $status . '</h1>';
    echo '<p class="text-gray-600 mb-6">' . e($message) . '</p>';
    echo '<a href="' . e(url('/')) . '" class="text-green-600 hover:underline">กลับไปหน้าแรก</a>';
    echo '</div>';
    page_footer();
    exit;
}
