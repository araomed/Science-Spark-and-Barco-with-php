<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exceptions\ValidationException;

final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $ruleText) {
            $ruleList = explode('|', $ruleText);
            $exists = array_key_exists($field, $data);
            $value = $data[$field] ?? null;
            $optional = in_array('optional', $ruleList, true);
            $nullable = in_array('nullable', $ruleList, true);

            if (!$exists && $optional) {
                continue;
            }

            if (($value === null || $value === '') && $nullable) {
                $validated[$field] = null;
                continue;
            }

            foreach ($ruleList as $rule) {
                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);

                if ($name === 'optional' || $name === 'nullable') {
                    continue;
                }

                $error = self::checkRule($field, $value, $exists, $name, $argument);

                if ($error !== null) {
                    $errors[$field][] = $error;
                }
            }

            if (!isset($errors[$field]) && $exists) {
                $validated[$field] = $value;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    private static function checkRule(
        string $field,
        mixed $value,
        bool $exists,
        string $rule,
        ?string $argument
    ): ?string {
        if ($rule === 'required' && (!$exists || $value === null || $value === '')) {
            return 'This field is required.';
        }

        if (!$exists || $value === null || $value === '') {
            return null;
        }

        return match ($rule) {
            'string' => is_string($value) ? null : 'This field must be a string.',
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false
                ? null
                : 'This field must be an integer.',
            'numeric' => is_numeric($value) ? null : 'This field must be numeric.',
            'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true)
                ? null
                : 'This field must be boolean.',
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? null
                : 'This field must be a valid email address.',
            'date' => self::isDate((string) $value) ? null : 'This field must be a valid date.',
            'max' => self::maxError($value, (int) $argument),
            'min' => self::minError($value, (int) $argument),
            'enum' => self::enumError((string) $value, (string) $argument),
            default => null,
        };
    }

    private static function isDate(string $value): bool
    {
        $date = date_create_from_format('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function maxError(mixed $value, int $maximum): ?string
    {
        if (is_numeric($value)) {
            return (float) $value <= $maximum
                ? null
                : 'This field may not be greater than ' . $maximum . '.';
        }

        return strlen((string) $value) <= $maximum
            ? null
            : 'This field may not be longer than ' . $maximum . ' characters.';
    }

    private static function minError(mixed $value, int $minimum): ?string
    {
        if (is_numeric($value)) {
            return (float) $value >= $minimum
                ? null
                : 'This field must be at least ' . $minimum . '.';
        }

        return strlen((string) $value) >= $minimum
            ? null
            : 'This field must be at least ' . $minimum . ' characters.';
    }

    private static function enumError(string $value, string $allowed): ?string
    {
        $values = array_map('trim', explode(',', $allowed));

        return in_array($value, $values, true)
            ? null
            : 'This field must be one of: ' . implode(', ', $values) . '.';
    }
}
