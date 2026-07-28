# Science Spark Laboratory Management System

Native PHP and HTML laboratory equipment management system for the existing Science Spark PostgreSQL database.

The app does not use Laravel, Symfony, React, Vite, MVC controllers, repositories, middleware, or JWT. PHP renders the pages directly, forms post back to PHP, and PDO writes to PostgreSQL with prepared statements. Composer libraries are used only for QR codes and PDF generation.

## Structure

```text
Browser -> public/index.php -> includes/app.php -> PDO -> PostgreSQL
```

- `public/index.php` contains the page routes and form actions.
- `includes/app.php` contains simple shared PHP functions for database access, sessions, rendering, uploads, QR, and PDF.
- `public/assets/app.css` contains the UI styling.
- `storage/uploads` stores uploaded document files.
- `docs` contains reference notes.

## Requirements

- PHP 8.2 or newer with `pdo_pgsql`, `fileinfo`, `openssl`, and `json`
- Composer
- PostgreSQL database `sciencespark_lab_db`

## Setup

1. Install PHP dependencies:

   ```bash
   composer install
   ```

2. Create `.env` from `.env.example` and set local database values:

   ```env
   DB_NAME=sciencespark_lab_db
   DB_USER=postgres
   DB_PASSWORD=your-local-password
   ```

3. Start the PHP app:

   ```bash
   php -S 127.0.0.1:8080 -t public public/index.php
   ```

4. Open:

   ```text
   http://127.0.0.1:8080
   ```

For phone QR testing on the same Wi-Fi, set `APP_URL` in `.env` to the laptop IP, for example:

```env
APP_URL=http://192.168.1.206:8080
```

## Features

- PHP session login/logout
- Dashboard metrics
- Equipment management
- Customer management
- Maintenance records and alerts
- Notifications
- Service reports with PDF downloads
- Service requests
- Document uploads and downloads
- CSV exports
- Activity log view
- Profile and settings pages
- Equipment QR codes
- Public read-only QR scan profile pages

## Commands

- `composer lint` checks PHP syntax.
- `composer test` runs lightweight helper tests.
- `php -S 127.0.0.1:8080 -t public public/index.php` starts the local app.

## QR And PDF

QR codes are generated with `chillerlan/php-qrcode`.

Service report PDFs are generated with `setasign/fpdf`.
