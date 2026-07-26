# Development

## Local Start

```bash
composer install
php -S 127.0.0.1:8080 -t public
```

## Quality Checks

```bash
composer lint
composer test
```

`composer lint` runs `php -l` over project PHP files. `composer test` runs a lightweight test suite for request parsing, validation, router behavior, and JWT encoding/decoding.

## Environment

Use `.env.example` as the template. Keep `.env` private.

Required local values:

- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`
- `JWT_SECRET`

Frontend values such as `FRONTEND_URL` and `CORS_ALLOWED_ORIGINS` are not secrets.

## Database Safety

Do not drop, truncate, rename, or recreate existing tables. If schema changes are needed later, create reversible SQL migrations and verify compatibility with current records.
