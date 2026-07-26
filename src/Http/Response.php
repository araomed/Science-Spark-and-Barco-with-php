<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /**
     * @param array<string, mixed> $headers
     */
    public static function json(
        array $payload,
        int $status = 200,
        array $headers = []
    ): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');

            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * @param array<string, mixed>|array<int, mixed>|null $data
     * @param array<string, mixed>|null $meta
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        int $status = 200,
        ?array $meta = null
    ): void {
        $payload = ['success' => true];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        self::json($payload, $status);
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $errors
     */
    public static function error(
        string $message,
        int $status = 400,
        array $errors = []
    ): void {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        self::json($payload, $status);
    }

    public static function noContent(): void
    {
        if (!headers_sent()) {
            http_response_code(204);
        }
    }

    public static function download(
        string $absolutePath,
        string $downloadName,
        string $mimeType
    ): void {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $mimeType);
            header(
                'Content-Disposition: attachment; filename="' .
                str_replace('"', '', $downloadName) .
                '"'
            );
            header('Content-Length: ' . filesize($absolutePath));
        }

        readfile($absolutePath);
    }

    public static function raw(
        string $content,
        string $mimeType,
        int $status = 200,
        ?string $downloadName = null
    ): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . strlen($content));

            if ($downloadName !== null) {
                header(
                    'Content-Disposition: attachment; filename="' .
                    str_replace('"', '', $downloadName) .
                    '"'
                );
            }
        }

        echo $content;
    }
}
