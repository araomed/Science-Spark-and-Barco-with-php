<?php

declare(strict_types=1);

use chillerlan\QRCode\QRCode;

require_once dirname(__DIR__) . '/vendor/autoload.php';

load_env_file(root_path('.env'));

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

set_exception_handler(static function (Throwable $throwable): void {
    error_log($throwable->getMessage());

    $detail = env_bool('APP_DEBUG', false)
        ? '<pre>' . h($throwable->getMessage()) . '</pre>'
        : '<p>Please check the database connection and try again.</p>';

    render_guest('Server error', '<h1>Server error</h1>' . $detail, 500);
});

function root_path(string $path = ''): string
{
    $root = dirname(__DIR__);

    return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($value !== '' && (
            ($value[0] === '"' && substr($value, -1) === '"')
            || ($value[0] === "'" && substr($value, -1) === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function env_string(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
}

function env_int(string $key, int $default): int
{
    $value = env_string($key, (string) $default);

    return is_numeric($value) ? (int) $value : $default;
}

function env_bool(string $key, bool $default): bool
{
    $value = strtolower(env_string($key, $default ? 'true' : 'false'));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        env_string('DB_HOST', '127.0.0.1'),
        env_string('DB_PORT', '5432'),
        env_string('DB_NAME', 'sciencespark_lab_db')
    );

    $pdo = new PDO($dsn, env_string('DB_USER', 'postgres'), env_string('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function request_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = '/' . trim((string) $path, '/');

    return $path === '/' ? '/' : rtrim($path, '/');
}

function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

function query_string(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

function h(mixed $value): string
{
    return htmlspecialchars(display($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function display(mixed $value): string
{
    return $value === null || $value === '' ? 'Not set' : (string) $value;
}

function nullable(mixed $value): mixed
{
    return $value === null || $value === '' ? null : $value;
}

function nullable_int(mixed $value): ?int
{
    return $value === null || $value === '' ? null : (int) $value;
}

function safe_identifier(string $name): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }

    return $name;
}

function db_rows(string $sql, array $parameters = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchAll();
}

function db_row(string $sql, array $parameters = []): ?array
{
    $statement = db()->prepare($sql);
    $statement->execute($parameters);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function db_scalar(string $sql, array $parameters = []): int
{
    $statement = db()->prepare($sql);
    $statement->execute($parameters);

    return (int) $statement->fetchColumn();
}

function db_execute(string $sql, array $parameters = []): void
{
    $statement = db()->prepare($sql);
    $statement->execute($parameters);
}

function db_insert(string $table, array $data): int
{
    $table = safe_identifier($table);
    $data = array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '');

    if ($data === []) {
        throw new InvalidArgumentException('No data supplied.');
    }

    $columns = array_map('safe_identifier', array_keys($data));
    $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
    $statement = db()->prepare(
        'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ') RETURNING id'
    );
    $statement->execute($data);

    return (int) $statement->fetchColumn();
}

function db_delete(string $table, int $id): void
{
    $table = safe_identifier($table);
    db_execute('DELETE FROM ' . $table . ' WHERE id = :id', ['id' => $id]);
}

function db_update_qr_path(int $id): void
{
    db_execute('UPDATE instruments SET qr_code_path = :path WHERE id = :id', [
        'id' => $id,
        'path' => scan_url($id),
    ]);
}

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_user(): array
{
    $user = current_user();

    if ($user === null) {
        redirect('/login');
    }

    return $user;
}

function login_user(string $identifier, string $password): bool
{
    $user = db_row(
        'SELECT id, username, email, hashed_password, role FROM users WHERE username = :identifier OR email = :identifier LIMIT 1',
        ['identifier' => $identifier]
    );

    if ($user === null || !is_string($user['hashed_password'] ?? null) || !password_verify($password, $user['hashed_password'])) {
        return false;
    }

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];

    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    session_destroy();
    session_start();
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';

    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        flash('Security check failed. Please try again.');
        redirect('/');
    }
}

function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function flash_html(): string
{
    if (!isset($_SESSION['flash'])) {
        return '';
    }

    $message = (string) $_SESSION['flash'];
    unset($_SESSION['flash']);

    return '<p class="toast-inline">' . h($message) . '</p>';
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}

function send_raw(string $body, string $contentType, int $status = 200, ?string $downloadName = null): never
{
    http_response_code($status);
    header('Content-Type: ' . $contentType);

    if ($downloadName !== null) {
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    }

    echo $body;
    exit;
}

function render_guest(string $title, string $body, int $status = 200): never
{
    html_page($title, '<main class="login-page"><section class="login-panel">' . $body . '</section></main>', $status);
}

function render_app(string $title, string $active, string $body, int $status = 200): never
{
    $user = require_user();
    $unread = db_scalar('SELECT COUNT(*)::int FROM notifications WHERE is_read = false');
    $initials = strtoupper(substr((string) $user['username'], 0, 2));
    $nav = [
        'dashboard' => ['/dashboard', 'D', 'Dashboard'],
        'equipment' => ['/equipment', 'E', 'Equipment'],
        'customers' => ['/customers', 'C', 'Customers'],
        'maintenance' => ['/maintenance', 'M', 'Maintenance'],
        'alerts' => ['/alerts', 'A', 'Alerts'],
        'notifications' => ['/notifications', 'N', 'Notifications'],
        'service-reports' => ['/service-reports', 'R', 'Service Reports'],
        'service-requests' => ['/service-requests', 'Q', 'Service Requests'],
        'documents' => ['/documents', 'F', 'Documents'],
        'reports' => ['/reports', 'X', 'Reports'],
        'activity' => ['/activity', 'L', 'Activity'],
        'settings' => ['/settings', 'S', 'Settings'],
    ];
    $navHtml = '';

    foreach ($nav as $key => [$href, $icon, $label]) {
        $class = $key === $active ? 'nav-item active' : 'nav-item';
        $navHtml .= '<a class="' . $class . '" href="' . $href . '"><span>' . $icon . '</span>' . h($label) . '</a>';
    }

    $logout = '<form method="post" action="/logout">' . csrf_field() . '<button class="ghost-action" type="submit">Sign out</button></form>';
    $shell = '<div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar-brand"><div class="brand-mark">SS</div><div><strong>Science Spark</strong><span>Lab System</span></div></div>
            <nav aria-label="Main navigation">' . $navHtml . '</nav>
        </aside>
        <main class="workspace">
            <header class="topbar">
                <div><p class="eyebrow">Workspace</p><h1>' . h($title) . '</h1></div>
                <div class="user-menu">
                    <a class="notification-top-button" href="/notifications"><span>Notifications</span><strong>' . h($unread) . '</strong></a>
                    <a class="profile-top-button" href="/profile"><span class="account-mini-avatar">' . h($initials) . '</span><span class="top-profile-meta"><strong>' . h($user['username']) . '</strong><small>' . h($user['role']) . '</small></span></a>
                    ' . $logout . '
                </div>
            </header>' . $body . '
        </main>
    </div>';

    html_page($title, $shell, $status);
}

function html_page(string $title, string $body, int $status = 200): never
{
    $cssPath = root_path('public/assets/app.css');
    $cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();

    send_raw('<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . h($title) . ' - Science Spark</title>
  <link rel="stylesheet" href="/assets/app.css?v=' . h($cssVersion) . '">
</head>
<body>' . $body . '</body>
</html>', 'text/html; charset=utf-8', $status);
}

function not_found(): never
{
    if (current_user() !== null) {
        render_app('Not found', 'dashboard', '<section class="content-section"><div class="empty-state"><strong>Page not found</strong><span>This address does not exist.</span></div></section>', 404);
    }

    render_guest('Not found', '<h1>Page not found</h1><p>This address does not exist.</p><a class="primary-action" href="/login">Sign in</a>', 404);
}

function method_not_allowed(): never
{
    render_guest('Method not allowed', '<h1>Method not allowed</h1><p>This page does not support that request method.</p>', 405);
}

function metric_card(string $label, mixed $value, string $hint, string $accent = ''): string
{
    return '<article class="metric-card ' . h($accent) . '"><span>' . h($label) . '</span><strong>' . h($value) . '</strong><small>' . h($hint) . '</small></article>';
}

function status_chip(mixed $value): string
{
    $label = display($value);
    $class = strtolower(str_replace([' ', '_'], '-', $label));

    return '<span class="status-chip status-' . h($class) . '">' . h($label) . '</span>';
}

function raw_cell(string $html): array
{
    return ['__html' => $html];
}

function table_html(array $headers, array $rows): string
{
    if ($rows === []) {
        return '<div class="empty-state"><strong>No records found</strong><span>Nothing to show yet.</span></div>';
    }

    $html = '<div class="table-shell"><table><thead><tr>';

    foreach ($headers as $header) {
        $html .= '<th>' . h($header) . '</th>';
    }

    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr>';

        foreach ($row as $cell) {
            $html .= '<td>' . (is_array($cell) && isset($cell['__html']) ? $cell['__html'] : h($cell)) . '</td>';
        }

        $html .= '</tr>';
    }

    return $html . '</tbody></table></div>';
}

function input_html(string $label, string $name, bool $required = false, string $type = 'text', mixed $value = ''): string
{
    return '<label class="field compact-field"><span>' . h($label) . '</span><input name="' . h($name) . '" type="' . h($type) . '" value="' . h($value) . '"' . ($required ? ' required' : '') . '></label>';
}

function textarea_html(string $label, string $name, bool $required = false, mixed $value = ''): string
{
    return '<label class="field compact-field"><span>' . h($label) . '</span><textarea name="' . h($name) . '"' . ($required ? ' required' : '') . '>' . h($value) . '</textarea></label>';
}

function select_html(string $label, string $name, array $options, mixed $selected = null, bool $required = false): string
{
    $html = '<label class="field compact-field"><span>' . h($label) . '</span><select name="' . h($name) . '"' . ($required ? ' required' : '') . '>';

    foreach ($options as $value => $text) {
        $html .= '<option value="' . h($value) . '"' . ((string) $selected === (string) $value ? ' selected' : '') . '>' . h($text) . '</option>';
    }

    return $html . '</select></label>';
}

function select_rows_html(string $label, string $name, array $rows, string $emptyLabel, mixed $selected = null, bool $required = false): string
{
    $html = '<label class="field compact-field"><span>' . h($label) . '</span><select name="' . h($name) . '"' . ($required ? ' required' : '') . '>';
    $html .= '<option value="">' . h($emptyLabel) . '</option>';

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $html .= '<option value="' . $id . '"' . ((string) $selected === (string) $id ? ' selected' : '') . '>' . h($row['name']) . '</option>';
    }

    return $html . '</select></label>';
}

function delete_form(string $action): string
{
    return '<form method="post" action="' . h($action) . '">' . csrf_field() . '<button class="danger-action" type="submit">Delete</button></form>';
}

function detail_html(string $label, mixed $value): string
{
    return '<div><span>' . h($label) . '</span><strong>' . h(display($value)) . '</strong></div>';
}

function simple_list(array $rows, callable $label): string
{
    if ($rows === []) {
        return '<div class="empty-state"><strong>No records found</strong><span>Nothing to show yet.</span></div>';
    }

    $html = '<div class="alert-list">';

    foreach ($rows as $row) {
        $html .= '<div class="alert-item"><strong>' . h($label($row)) . '</strong></div>';
    }

    return $html . '</div>';
}

function row_actions(array $items): string
{
    return '<div class="row-actions">' . implode('', $items) . '</div>';
}

function id_from_match(array $matches): int
{
    return max(1, (int) ($matches[1] ?? 1));
}

function scan_url(int $id): string
{
    return rtrim(env_string('APP_URL', 'http://127.0.0.1:8080'), '/') . '/scan/equipment/' . $id;
}

function send_equipment_qr(int $id): never
{
    if (db_row('SELECT id FROM instruments WHERE id = :id', ['id' => $id]) === null) {
        send_raw(missing_qr_svg(), 'image/svg+xml', 404);
    }

    $dataUri = (new QRCode())->render(scan_url($id));
    $parts = explode(',', $dataUri, 2);
    $svg = count($parts) === 2 ? (base64_decode($parts[1], true) ?: $dataUri) : $dataUri;

    send_raw($svg, 'image/svg+xml');
}

function missing_qr_svg(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240" viewBox="0 0 240 240"><rect width="240" height="240" fill="#ffffff"/><text x="120" y="116" font-family="Arial, sans-serif" font-size="18" font-weight="700" text-anchor="middle" fill="#111111">QR not found</text><text x="120" y="142" font-family="Arial, sans-serif" font-size="12" text-anchor="middle" fill="#555555">Equipment record missing</text></svg>';
}

function send_csv(string $filename, array $headers, string $sql): never
{
    require_user();
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $headers);

    foreach (db_rows($sql) as $row) {
        fputcsv($handle, array_map(static fn (string $header): mixed => $row[$header] ?? '', $headers));
    }

    rewind($handle);
    $csv = stream_get_contents($handle) ?: '';
    fclose($handle);

    send_raw($csv, 'text/csv; charset=utf-8', 200, $filename);
}

function pdf_text(string $text): string
{
    $converted = iconv('UTF-8', 'windows-1252//TRANSLIT', $text);

    return is_string($converted) ? $converted : preg_replace('/[^\x20-\x7E]/', '', $text);
}

function send_service_report_pdf(int $id): never
{
    require_user();
    $report = db_row(
        'SELECT sr.id, sr.date, sr.summary, sr.technician, i.name AS instrument_name, i.serial_number
         FROM service_reports sr
         LEFT JOIN instruments i ON i.id = sr.instrument_id
         WHERE sr.id = :id',
        ['id' => $id]
    );

    if ($report === null) {
        not_found();
    }

    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetTitle(pdf_text('Service Report #' . $report['id']));
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 12, pdf_text('Science Spark Service Report'), 0, 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, pdf_text('Report ID: ' . $report['id']), 0, 1);
    $pdf->Cell(0, 8, pdf_text('Date: ' . display($report['date'])), 0, 1);
    $pdf->Cell(0, 8, pdf_text('Instrument: ' . display($report['instrument_name'])), 0, 1);
    $pdf->Cell(0, 8, pdf_text('Serial: ' . display($report['serial_number'])), 0, 1);
    $pdf->Cell(0, 8, pdf_text('Technician: ' . display($report['technician'])), 0, 1);
    $pdf->Ln(6);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(0, 8, pdf_text('Summary'), 0, 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(0, 7, pdf_text(display($report['summary'])));

    send_raw($pdf->Output('S'), 'application/pdf', 200, 'service_report_' . $id . '.pdf');
}

function upload_document_file(): ?string
{
    if (!isset($_FILES['file']) || !is_array($_FILES['file']) || (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int) $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }

    $maxSize = env_int('MAX_UPLOAD_SIZE', 10485760);
    $tmpName = (string) $_FILES['file']['tmp_name'];
    $original = basename((string) $_FILES['file']['name']);

    if ((int) $_FILES['file']['size'] > $maxSize) {
        throw new RuntimeException('File is too large.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName) ?: 'application/octet-stream';
    $allowed = array_map('trim', explode(',', env_string('ALLOWED_UPLOAD_MIME_TYPES', 'application/pdf,image/png,image/jpeg,text/plain,text/csv')));

    if (!in_array($mime, $allowed, true)) {
        throw new RuntimeException('File type is not allowed.');
    }

    $folder = root_path(env_string('UPLOAD_PATH', 'storage/uploads'));

    if (!is_dir($folder)) {
        mkdir($folder, 0775, true);
    }

    $extension = pathinfo($original, PATHINFO_EXTENSION);
    $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($original, PATHINFO_FILENAME)) ?: 'document';
    $filename = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . $safeBase . ($extension !== '' ? '.' . $extension : '');
    $target = $folder . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException('Could not save uploaded file.');
    }

    return trim(env_string('UPLOAD_PATH', 'storage/uploads'), '/\\') . '/' . $filename;
}

function send_document_download(int $id): never
{
    require_user();
    $document = db_row('SELECT title, file_path FROM documents WHERE id = :id', ['id' => $id]);

    if ($document === null || empty($document['file_path'])) {
        not_found();
    }

    $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $document['file_path']);
    $path = root_path($relative);

    if (!is_file($path)) {
        not_found();
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    send_raw((string) file_get_contents($path), $mime, 200, basename($path));
}
