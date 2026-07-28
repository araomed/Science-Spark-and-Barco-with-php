# AGENTS.md

Science Spark is a native PHP and HTML app for the existing PostgreSQL database. Keep it understandable for a Software Engineering student: clear names, small functions, prepared SQL, and no Laravel/Symfony/React/MVC migration.

## Commands

- Install dependencies: `composer install`
- Syntax check: `composer lint`
- Test: `composer test`
- Local app server: `php -S 127.0.0.1:8080 -t public public/index.php`

## Architecture

- `public/index.php` is the single native PHP entry point with page handling and form actions.
- `includes/app.php` contains shared procedural helpers for `.env`, PDO, sessions, rendering, uploads, QR, and PDF.
- `public/assets/app.css` owns visual styling.
- `storage/uploads` stores uploaded files.

## Rules

- Preserve the existing PostgreSQL schema and data unless a reversible migration is explicitly requested.
- Never commit `.env`, database passwords, uploaded files, or logs.
- Use prepared statements for every user-provided value.
- Validate request bodies before writing to the database.
- Unknown routes return 404; unsupported methods return 405.
- Backend authorization is required even when a page hides buttons.
- Use Composer libraries only for QR code and PDF generation.
- Run `composer lint` and `composer test` after PHP changes.

## Domain Terms

- The database table is `instruments`; user-facing pages call them equipment.
- Service data lives in `maintenance_records`, `service_reports`, and `service_requests`.
- Documents belong to optional instruments and are stored under `storage/uploads`.
