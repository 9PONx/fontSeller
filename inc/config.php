<?php
declare(strict_types=1);

/**
 * Tiny .env loader — no Composer needed.
 */
function load_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $config = [];
    $path = dirname(__DIR__) . '/.env';

    if (file_exists($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $config[trim($key)] = trim($value);
        }
    }

    return $config;
}

function config(string $key, mixed $default = null): mixed
{
    $config = load_config();
    return array_key_exists($key, $config) ? $config[$key] : $default;
}

function config_int(string $key, int $default = 0): int
{
    $value = config($key, (string) $default);
    return filter_var($value, FILTER_VALIDATE_INT) ?: $default;
}

function validate_environment(): void
{
    $key = config('APP_KEY', '');
    if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
        throw new RuntimeException('APP_KEY must be exactly 64 hexadecimal characters');
    }

    foreach (['STORAGE_PATH', 'FONT_SOURCE_PATH'] as $required) {
        if (config($required) === null || config($required) === '') {
            throw new RuntimeException("Missing required config: {$required}");
        }
    }

    date_default_timezone_set(config('APP_TIMEZONE', 'Asia/Bangkok'));
}
