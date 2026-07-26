# API Reference

Base URL: `http://127.0.0.1:8080/api`

Authentication header for protected routes:

```text
Authorization: Bearer <token>
```

## Public

| Method | Path | Description |
| --- | --- | --- |
| GET | `/` | API root |
| GET | `/health` | API/database health |
| GET | `/api/health` | API/database health |
| POST | `/api/auth/login` | Login with `identifier` or `username` or `email`, plus `password` |

## Auth

| Method | Path | Role |
| --- | --- | --- |
| GET | `/api/auth/me` | Authenticated |
| POST | `/api/auth/logout` | Authenticated |

## Resources

All list endpoints support `page`, `per_page`, `search`, allowlisted filters, and `sort`. Prefix sort with `-` for descending order.

| Method | Path | Role |
| --- | --- | --- |
| GET | `/api/categories` | Authenticated |
| POST/PUT/PATCH/DELETE | `/api/categories` | admin, manager |
| GET | `/api/customers` | Authenticated |
| POST/PUT/PATCH/DELETE | `/api/customers` | admin, manager |
| GET | `/api/instruments` | Authenticated |
| POST/PUT/PATCH/DELETE | `/api/instruments` | admin, manager, technician |
| GET | `/api/equipment` | Authenticated |
| POST/PUT/PATCH/DELETE | `/api/equipment` | admin, manager, technician |
| GET | `/api/instruments/{id}/qr` | Authenticated |
| GET | `/api/instruments/{id}/qrcode` | Authenticated; returns view-only SVG QR image |
| GET | `/api/equipment/{id}/qr` | Authenticated |
| GET | `/api/maintenance` | Authenticated |
| POST/PUT/PATCH/DELETE | `/api/maintenance` | admin, manager, technician |
| GET | `/api/service-reports` | Authenticated |
| POST/PUT/PATCH | `/api/service-reports` | admin, manager, technician |
| GET | `/api/service-requests` | Authenticated |
| POST/PUT/PATCH/DELETE | `/api/service-requests` | admin, manager, technician |
| GET | `/api/documents` | Authenticated |
| POST/PUT/PATCH/DELETE | `/api/documents` | admin, manager, technician |
| GET | `/api/documents/{id}/download` | Authenticated |
| GET | `/api/notifications` | Authenticated |
| POST/PUT/PATCH/DELETE | `/api/notifications` | admin, manager, technician |
| GET | `/api/activity-logs` | admin, manager |
| GET | `/api/users` | admin |
| POST/PUT/PATCH/DELETE | `/api/users` | admin |
| GET | `/api/dashboard` | Authenticated |
