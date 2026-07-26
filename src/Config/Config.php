<?php

declare(strict_types=1);

namespace App\Config;

final class Config
{
    public static function rootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_string($value) ? $value : (string) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return array<int, string>
     */
    public static function csv(string $key, array $default = []): array
    {
        $value = self::string($key, implode(',', $default));

        if (trim($value) === '') {
            return [];
        }

        return array_values(
            array_filter(
                array_map(static fn (string $item): string => trim($item), explode(',', $value)),
                static fn (string $item): bool => $item !== ''
            )
        );
    }

    public static function path(string $key, string $default): string
    {
        $path = self::string($key, $default);

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }

        return self::rootPath() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
