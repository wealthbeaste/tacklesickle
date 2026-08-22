<?php

declare(strict_types=1);

final class Config
{
    private static bool $loaded = false;

    public static function load(string $file): void
    {
        if (self::$loaded || !is_file($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');

            $key = trim($key);
            $value = trim($value);

            if ($key !== '') {
                putenv("{$key}={$value}");
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}