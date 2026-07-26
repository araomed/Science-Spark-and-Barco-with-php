<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class HttpException extends RuntimeException
{
    /**
     * @param array<string, mixed>|array<int, mixed> $errors
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 400,
        private readonly array $errors = []
    ) {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>|array<int, mixed>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
