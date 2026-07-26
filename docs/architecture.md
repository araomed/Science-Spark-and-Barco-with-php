# Architecture

This project intentionally uses plain PHP instead of a framework.

## Request Flow

1. `public/index.php` loads Composer and `.env`.
2. CORS and security headers are applied.
3. `Request::fromGlobals()` parses method, path, query, headers, JSON body, form body, and uploaded files.
4. `routes/api.php` returns a configured `Router`.
5. The router matches method/path, extracts route parameters, runs middleware, and calls a controller.
6. Controllers validate input and call repositories or small services.
7. Repositories execute PDO prepared statements against PostgreSQL.
8. `Response` sends consistent JSON.

## Error Handling

`HttpException` carries safe client errors and status codes. Unexpected exceptions are logged to `storage/logs/app.log` and returned as safe 500 JSON unless `APP_DEBUG=true`.

## Authentication

Login verifies the existing bcrypt password hashes in `users.hashed_password`. JWTs are signed with `JWT_SECRET` and include subject, issue time, expiry, and a small public user payload. Protected routes require `Authorization: Bearer <token>`.

## Authorization

Role middleware enforces backend permissions for `admin`, `manager`, and `technician`. Frontend route protection should be treated only as a convenience layer.

## Uploads

Uploaded documents are detected with `fileinfo`, checked against an env-controlled MIME allowlist, stored under `storage/uploads`, and referenced by relative path in the existing `documents.file_path` column.

## QR Codes

New equipment records automatically receive a unique QR target based on their created instrument ID. The frontend only exposes QR viewing; it does not show manual generate or download controls.
