<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Config;
use App\Http\Request;
use Throwable;

final class Logger
{
    public static function exception(Throwable $exception, ?Request $request = null): void
    {
        $directory = Config::rootPath() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $context = [
            'time' => date('c'),
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8)),
            'method' => $request?->method(),
            'path' => $request?->path(),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        if (Config::bool('APP_DEBUG', false)) {
            $context['trace'] = $exception->getTraceAsString();
        }

        file_put_contents(
            $directory . DIRECTORY_SEPARATOR . 'app.log',
            json_encode($context, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
