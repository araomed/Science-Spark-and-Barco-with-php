# Development

## Local Start

```bash
composer install
php -S 127.0.0.1:8080 -t public public/index.php
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

`APP_URL` should match the URL used to open the PHP app. It is also used for generated QR targets.

## Database Safety

Do not drop, truncate, rename, or recreate existing tables. If schema changes are needed later, create reversible SQL migrations and verify compatibility with current records.
