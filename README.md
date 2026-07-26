# Science Spark Laboratory Management System

Native PHP 8.5 and HTML5 laboratory equipment management system for the Science Spark database.

This project does not use Laravel, React, Vite, Python, or FastAPI. PHP renders the web pages directly and also keeps JSON API endpoints available under `/api`.

## Architecture

```text
Browser -> public/index.php -> Router -> Controller -> PDO -> PostgreSQL
```

Main folders:

- `public/` contains the web entry point and CSS assets.
- `routes/` defines PHP web routes and API routes.
- `src/Controllers/` contains page and API controllers.
- `src/Database/` contains the PDO database connection.
- `src/Auth/` contains JWT support for API clients.
- `storage/` contains logs and uploaded files.
- `docs/` contains project documentation.

## Requirements

- PHP 8.5 with `pdo_pgsql`, `fileinfo`, `openssl`, and `json`
- Composer
- PostgreSQL database `sciencespark_lab_db`

## Setup

1. Install PHP dependencies:

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

3. Start the PHP app:

   ```bash
   php -S 127.0.0.1:8080 -t public public/index.php
   ```

4. Open:

   ```text
   http://127.0.0.1:8080
   ```

For phone testing on the same Wi-Fi, set `APP_URL` in `.env` to the laptop IP, for example:

```env
APP_URL=http://192.168.1.206:8080
```

Then start PHP on all network interfaces:

```bash
php -S 0.0.0.0:8080 -t public public/index.php
```

## Features

- PHP session login/logout
- Dashboard metrics
- Equipment management
- Customer management
- Maintenance records
- Maintenance alerts
- Notifications
- Service reports
- Service requests
- Documents
- CSV exports
- Activity log view
- Profile and settings pages
- Automatic equipment QR codes
- Public read-only QR scan profile pages
- JSON API endpoints under `/api`

## Commands

- `composer lint` checks PHP syntax.
- `composer test` runs focused unit tests.
- `php -S 127.0.0.1:8080 -t public public/index.php` starts the local app.

## QR Codes

Equipment QR targets are assigned automatically when equipment is created. QR codes open:

```text
/scan/equipment/{id}
```

That page is rendered by PHP and does not require the React/Vite frontend or a phone login session.

## Documentation

- [docs/api.md](docs/api.md)
- [docs/database.md](docs/database.md)
- [docs/architecture.md](docs/architecture.md)
- [docs/development.md](docs/development.md)
