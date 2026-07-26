<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ValidationException extends HttpException
{
    /**
     * @param array<string, array<int, string>> $errors
     */
    public function __construct(array $errors)
    {
        parent::__construct('Validation failed', 422, $errors);
    }
}
