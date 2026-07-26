# AGENTS.md

Science Spark is a plain PHP JSON API for the existing PostgreSQL database. Keep the backend understandable for a Software Engineering student: clear names, small classes, prepared SQL, and no Laravel/Symfony migration.

## Commands

- Install dependencies: `composer install`
- Syntax check: `composer lint`
- Test: `composer test`
- Local API server: `php -S 127.0.0.1:8080 -t public`

## Architecture

- `public/index.php` bootstraps Dotenv, CORS, security headers, central errors, and routing.
- `routes/api.php` registers REST routes.
- `src/Http` contains `Request`, `Response`, and `Router`.
- `src/Controllers` handles request flow.
- `src/Repositories` owns PDO SQL.
- `src/Auth` and `src/Http/Middleware` enforce JWT authentication and roles.

## Rules

- Preserve the existing PostgreSQL schema and data unless a reversible migration is explicitly requested.
- Never commit `.env`, database passwords, JWT secrets, uploaded files, or logs.
- Use prepared statements for every user-provided value.
- Validate request bodies in controllers before writing to the database.
- Keep API responses in the shared `{ success, message, data, errors, meta }` JSON conventions.
- Unknown routes return 404; unsupported methods return 405.
- Backend authorization is required even when a frontend hides buttons.
- Run `composer lint` and `composer test` after PHP changes.

## Domain Terms

- The database table is `instruments`; user-facing routes also expose `/api/equipment` as an alias.
- Service data lives in `maintenance_records`, `service_reports`, and `service_requests`.
- Documents belong to optional instruments and are stored under `storage/uploads`.
