# Science Spark Laboratory PHP API

Plain PHP 8.5 JSON API for the Science Spark laboratory equipment database.

## Architecture

React frontend, when available, should call:

```text
React -> HTTP JSON -> public/index.php -> Router -> Controller -> Repository -> PDO -> PostgreSQL
```

This repository currently contains the PHP API only. The existing Python/FastAPI source and React frontend source were not present in this folder during the audit, so exact endpoint compatibility must be confirmed when those projects are provided.

## Requirements

- PHP 8.5 with `pdo_pgsql`, `fileinfo`, `openssl`, and `json`
- Composer
- PostgreSQL database `sciencespark_lab_db`

## Setup

1. Install dependencies:

   ```bash
   composer install
   ```

2. Create `.env` from `.env.example` and set local values:

   ```env
   DB_NAME=sciencespark_lab_db
   DB_USER=postgres
   DB_PASSWORD=your-local-password
   JWT_SECRET=generate-a-long-random-secret
   ```

3. Start the API:

   ```bash
   php -S 127.0.0.1:8080 -t public
   ```

4. Check health:

   ```bash
   curl http://127.0.0.1:8080/api/health
   ```

## Commands

- `composer lint` checks PHP syntax.
- `composer test` runs focused unit tests for request parsing, validation, routing, and JWT handling.
- `php -S 127.0.0.1:8080 -t public` starts the local API.

## API Shape

Responses use:

```json
{
  "success": true,
  "data": {},
  "meta": {}
}
```

Errors use:

```json
{
  "success": false,
  "message": "Human readable error",
  "errors": {}
}
```

Implemented route documentation is in [docs/api.md](docs/api.md).

## Authentication

`POST /api/auth/login` verifies the existing `users.hashed_password` bcrypt hashes with `password_verify()` and returns a signed JWT. Protected routes require:

```text
Authorization: Bearer <token>
```

Roles currently found in the database are `admin`, `manager`, and `technician`.

## Uploads

Document uploads are stored under `storage/uploads`. File type is detected server-side with `fileinfo`, upload size is limited by `MAX_UPLOAD_SIZE`, and allowed MIME types come from `ALLOWED_UPLOAD_MIME_TYPES`.

## QR Codes

Equipment QR targets are assigned automatically when equipment is created. The frontend shows a view-only QR preview from `GET /api/instruments/{id}/qrcode`; manual generate/download controls are not part of the UI.

## Documentation

- [docs/audit.md](docs/audit.md)
- [docs/api.md](docs/api.md)
- [docs/database.md](docs/database.md)
- [docs/architecture.md](docs/architecture.md)
- [docs/development.md](docs/development.md)
