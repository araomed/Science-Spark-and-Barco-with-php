# Repository Audit

Audit date: 2026-07-25

## A. Current Architecture

This folder contains a small plain PHP API project:

- `public/index.php`
- `src/Database/Database.php`
- `src/Http/Router.php`
- Composer dependencies under `vendor`
- `.env` and `.env.example`

No Python/FastAPI source, React frontend source, package.json, migrations, SQL files, or test suite were present inside `laboratory-php-api`.

The PostgreSQL database is reachable through PDO using `.env` values. It contains an `alembic_version` table, which indicates that the existing database was likely created by a Python/Alembic backend, but that backend source is not available in this folder.

Authentication data exists in the `users` table. Stored password hashes use bcrypt-style `$2b$12$` hashes and can be verified with PHP `password_verify()`. Roles found in current data are `admin`, `manager`, and `technician`.

Existing PHP code before this rebuild had only a direct root route and a health route. It did not yet have request parsing, JSON validation, route parameters, 405 handling, authentication, controllers, repositories, upload handling, tests, or documentation.

## B. Database Analysis

Tables found in `public` schema:

- `activity_logs`: activity audit entries linked optionally to `users`.
- `alembic_version`: migration version marker from the previous Alembic system.
- `categories`: unique equipment/document categories.
- `customers`: customer organizations and contact details.
- `documents`: uploaded/searchable documents linked optionally to instruments.
- `instruments`: laboratory equipment/instruments; user-facing alias is equipment.
- `maintenance_records`: maintenance history and next due dates.
- `notifications`: reminder/notification records linked optionally to maintenance.
- `service_reports`: service report metadata linked optionally to instruments.
- `service_requests`: customer/instrument service requests.
- `users`: application users with username, email, bcrypt password hash, and role.

Primary keys exist on all domain tables. Foreign keys:

- `activity_logs.user_id -> users.id`
- `documents.instrument_id -> instruments.id`
- `instruments.customer_id -> customers.id`
- `maintenance_records.instrument_id -> instruments.id`
- `notifications.maintenance_record_id -> maintenance_records.id`
- `service_reports.instrument_id -> instruments.id`
- `service_requests.customer_id -> customers.id`
- `service_requests.instrument_id -> instruments.id`

Unique constraints/indexes:

- `categories.name`
- `instruments.serial_number`
- `users.email`
- `users.username`

Search indexes:

- GIN index on `documents.search_vector`
- GIN index on `instruments.search_vector`

Potential schema inconsistencies:

- Most foreign keys are nullable, so orphan-style workflow records are allowed.
- Tables have limited timestamp coverage; only `notifications.created_at` and `activity_logs.timestamp` are present.
- `documents` has no original filename, MIME type, size, or uploader user ID columns.
- `users` has no `is_active`, password reset, or created/updated timestamps.
- Service requests do not have a `priority` column.

No schema was changed.

## C. API Compatibility Map

Exact Python API compatibility could not be mapped because the Python/FastAPI source is not present in this folder.

| Existing Python endpoint | Method | Request | Auth | Response | PHP endpoint | Status |
| --- | --- | --- | --- | --- | --- | --- |
| Not available locally | Unknown | Unknown | Unknown | Unknown | `/api/auth/login` | Implemented from DB auth data |
| Not available locally | Unknown | Unknown | Unknown | Unknown | `/api/auth/me` | Implemented |
| Not available locally | Unknown | Unknown | Unknown | Unknown | `/api/users` | Implemented, admin-only |
| Not available locally | Unknown | Unknown | Unknown | Unknown | `/api/customers` | Implemented |
| Not available locally | Unknown | Unknown | Unknown | Unknown | `/api/instruments`, `/api/equipment` | Implemented |
| Not available locally | Unknown | Unknown | Unknown | Unknown | `/api/maintenance`, `/api/maintenance-records` | Implemented |
| Not available locally | Unknown | Unknown | Unknown | Unknown | `/api/service-reports` | Implemented |
| Not available locally | Unknown | Unknown | Unknown | Unknown | `/api/service-requests` | Implemented |
| Not available locally | Unknown | Unknown | Unknown | Unknown | `/api/documents` | Implemented with upload support |
| Not available locally | Unknown | Unknown | Unknown | Unknown | `/api/dashboard` | Implemented |

## D. Frontend Analysis

The earlier React/Vite frontend has been removed. The project now includes a native PHP and HTML5 web interface rendered by `WebController`, while JSON API routes remain available under `/api`.

## E. Implementation Plan

Phase 1, audit and compatibility:

- Completed repository inspection.
- Completed PostgreSQL schema inspection.
- Documented missing Python/React compatibility sources.

Phase 2, backend foundation:

- Add central bootstrap, request, response, router, exception handling, logging, CORS, and health route.

Phase 3, authentication:

- Verify bcrypt hashes from `users.hashed_password`.
- Add JWT login, current-user endpoint, logout acknowledgement, and role middleware.

Phase 4, core modules:

- Add REST endpoints for users, customers, categories, instruments/equipment, maintenance, service reports, service requests, documents, notifications, activity logs, QR metadata, and dashboard.

Phase 5, web interface:

- Completed as native PHP and HTML5 pages.

Phase 6, testing and optimisation:

- Add focused PHP tests and syntax checks.
- Review SQL for prepared statements and allowlisted sort/filter fields.
