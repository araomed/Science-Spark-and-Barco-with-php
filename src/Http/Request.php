<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\HttpException;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     * @param array<string, string> $headers
     * @param array<string, string> $routeParameters
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $files = [],
        private readonly array $headers = [],
        private readonly array $routeParameters = [],
        private readonly array $attributes = []
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $headers = self::readHeaders();
        $contentType = $headers['content-type'] ?? '';
        $rawBody = file_get_contents('php://input') ?: '';
        $body = $_POST;

        if (str_contains(strtolower($contentType), 'application/json')) {
            $body = self::parseJsonBody($rawBody);
        }

        return new self(
            $method,
            $path,
            $_GET,
            $body,
            $_FILES,
            $headers
        );
    }

    /**
     * Factory used by tests and command-line scripts.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function create(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $headers = []
    ): self {
        return new self(
            strtoupper($method),
            $path,
            $query,
            $body,
            [],
            self::normalizeHeaders($headers)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseJsonBody(string $rawBody): array
    {
        if (trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new HttpException('Malformed JSON request body', 400, [
                'json' => json_last_error_msg(),
            ]);
        }

        return $decoded;
    }

    public function method(): string
    {
        return strtoupper($this->method);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function queryValue(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function route(string $key, mixed $default = null): mixed
    {
        return $this->routeParameters[$key] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->headers['authorization'] ?? '';

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function withRouteParameters(array $parameters): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->files,
            $this->headers,
            $parameters,
            $this->attributes
        );
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$name] = $value;

        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->files,
            $this->headers,
            $this->routeParameters,
            $attributes
        );
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    private static function readHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            if (is_array($headers)) {
                return self::normalizeHeaders($headers);
            }
        }

        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = (string) $value;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }

        return $normalized;
    }
}
