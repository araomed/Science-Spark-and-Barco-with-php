<?php

declare(strict_types=1);

if (
    PHP_SAPI === 'cli-server'
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && isset($_SERVER['REQUEST_URI'])
    && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))
) {
    return false;
}

require_once dirname(__DIR__) . '/includes/app.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$path = request_path();
$method = request_method();

if ($method === 'POST') {
    verify_csrf();
}

if ($path === '/login') {
    $method === 'POST' ? action_login() : page_login();
}

if ($path === '/logout') {
    $method === 'POST' ? action_logout() : method_not_allowed();
}

if (preg_match('#^/scan/equipment/(\d+)$#', $path, $matches) === 1) {
    page_public_scan(id_from_match($matches));
}

if (preg_match('#^/equipment/(\d+)/qrcode$#', $path, $matches) === 1) {
    send_equipment_qr(id_from_match($matches));
}

if (preg_match('#^/service-reports/(\d+)/pdf$#', $path, $matches) === 1) {
    send_service_report_pdf(id_from_match($matches));
}

if (preg_match('#^/documents/(\d+)/download$#', $path, $matches) === 1) {
    send_document_download(id_from_match($matches));
}

match (true) {
    $path === '/', $path === '/dashboard' => $method === 'GET' ? page_dashboard() : method_not_allowed(),
    $path === '/equipment' => $method === 'POST' ? action_create_equipment() : page_equipment(),
    preg_match('#^/equipment/(\d+)$#', $path, $matches) === 1 => $method === 'GET' ? page_equipment_profile(id_from_match($matches)) : method_not_allowed(),
    preg_match('#^/equipment/(\d+)/delete$#', $path, $matches) === 1 => $method === 'POST' ? action_delete('instruments', id_from_match($matches), '/equipment', 'Equipment deleted.') : method_not_allowed(),
    $path === '/customers' => $method === 'POST' ? action_create_customer() : page_customers(),
    preg_match('#^/customers/(\d+)/delete$#', $path, $matches) === 1 => $method === 'POST' ? action_delete('customers', id_from_match($matches), '/customers', 'Customer deleted.') : method_not_allowed(),
    $path === '/maintenance' => $method === 'POST' ? action_create_maintenance() : page_maintenance(),
    preg_match('#^/maintenance/(\d+)/delete$#', $path, $matches) === 1 => $method === 'POST' ? action_delete('maintenance_records', id_from_match($matches), '/maintenance', 'Maintenance record deleted.') : method_not_allowed(),
    $path === '/alerts' => $method === 'GET' ? page_alerts() : method_not_allowed(),
    $path === '/notifications' => $method === 'GET' ? page_notifications() : method_not_allowed(),
    $path === '/notifications/generate' => $method === 'POST' ? action_generate_notifications() : method_not_allowed(),
    $path === '/notifications/read-all' => $method === 'POST' ? action_mark_all_notifications_read() : method_not_allowed(),
    preg_match('#^/notifications/(\d+)/read$#', $path, $matches) === 1 => $method === 'POST' ? action_mark_notification_read(id_from_match($matches)) : method_not_allowed(),
    preg_match('#^/notifications/(\d+)/delete$#', $path, $matches) === 1 => $method === 'POST' ? action_delete('notifications', id_from_match($matches), '/notifications', 'Notification deleted.') : method_not_allowed(),
    $path === '/service-reports' => $method === 'POST' ? action_create_service_report() : page_service_reports(),
    preg_match('#^/service-reports/(\d+)/delete$#', $path, $matches) === 1 => $method === 'POST' ? action_delete('service_reports', id_from_match($matches), '/service-reports', 'Service report deleted.') : method_not_allowed(),
    $path === '/service-requests' => $method === 'POST' ? action_create_service_request() : page_service_requests(),
    preg_match('#^/service-requests/(\d+)/delete$#', $path, $matches) === 1 => $method === 'POST' ? action_delete('service_requests', id_from_match($matches), '/service-requests', 'Service request deleted.') : method_not_allowed(),
    $path === '/documents' => $method === 'POST' ? action_create_document() : page_documents(),
    preg_match('#^/documents/(\d+)/delete$#', $path, $matches) === 1 => $method === 'POST' ? action_delete('documents', id_from_match($matches), '/documents', 'Document deleted.') : method_not_allowed(),
    $path === '/reports' => $method === 'GET' ? page_reports() : method_not_allowed(),
    $path === '/reports/equipment.csv' => $method === 'GET' ? export_equipment_csv() : method_not_allowed(),
    $path === '/reports/maintenance.csv' => $method === 'GET' ? export_maintenance_csv() : method_not_allowed(),
    $path === '/reports/service-reports.csv' => $method === 'GET' ? export_service_reports_csv() : method_not_allowed(),
    $path === '/activity' => $method === 'GET' ? page_activity() : method_not_allowed(),
    $path === '/profile' => $method === 'GET' ? page_profile() : method_not_allowed(),
    $path === '/settings' => $method === 'GET' ? page_settings() : method_not_allowed(),
    default => not_found(),
};

function page_login(): never
{
    if (current_user() !== null) {
        redirect('/dashboard');
    }

    $body = '<div class="brand-lockup">
        <div class="brand-mark">SS</div>
        <div>
            <p class="eyebrow">Science Spark</p>
            <h1>Laboratory Operations</h1>
        </div>
    </div>
    <form class="login-form" method="post" action="/login">
        ' . csrf_field() . '
        <div class="form-heading">
            <h2>Sign in</h2>
            <p>Manage equipment, maintenance, service reports, documents, and QR profiles with native PHP pages.</p>
        </div>
        ' . flash_html() . '
        <label class="field"><span>Username or Email</span><input name="identifier" required autofocus></label>
        <label class="field"><span>Password</span><input name="password" type="password" required></label>
        <button class="primary-action" type="submit">Sign in</button>
    </form>';

    render_guest('Sign in', $body);
}

function action_login(): never
{
    $identifier = post_string('identifier');
    $password = (string) ($_POST['password'] ?? '');

    if ($identifier === '' || !login_user($identifier, $password)) {
        flash('Incorrect username or password.');
        redirect('/login');
    }

    redirect('/dashboard');
}

function action_logout(): never
{
    logout_user();
    flash('Signed out.');
    redirect('/login');
}

function page_dashboard(): never
{
    require_user();
    $activeEquipment = db_scalar("SELECT COUNT(*)::int FROM instruments WHERE status = 'active'");
    $summary = [
        ['Total Equipment', db_scalar('SELECT COUNT(*)::int FROM instruments'), $activeEquipment . ' active', 'accent-mint'],
        ['Customers', db_scalar('SELECT COUNT(*)::int FROM customers'), 'Linked accounts', 'accent-blue'],
        ['Due Soon', db_scalar("SELECT COUNT(*)::int FROM maintenance_records WHERE next_due_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'"), 'Next 30 days', 'accent-amber'],
        ['Overdue', db_scalar('SELECT COUNT(*)::int FROM maintenance_records WHERE next_due_date IS NOT NULL AND next_due_date < CURRENT_DATE'), 'Needs attention', 'accent-rose'],
        ['Reports This Month', db_scalar("SELECT COUNT(*)::int FROM service_reports WHERE date >= DATE_TRUNC('month', CURRENT_DATE)"), 'PDF ready', ''],
    ];
    $statusCounts = db_rows(
        "SELECT COALESCE(NULLIF(status, ''), 'unknown') AS status, COUNT(*)::int AS count
         FROM instruments
         GROUP BY COALESCE(NULLIF(status, ''), 'unknown')
         ORDER BY count DESC, status"
    );
    $alerts = db_rows(
        "SELECT mr.id, mr.next_due_date, mr.type, i.name AS instrument_name
         FROM maintenance_records mr
         LEFT JOIN instruments i ON i.id = mr.instrument_id
         WHERE mr.next_due_date IS NOT NULL
           AND mr.next_due_date <= CURRENT_DATE + INTERVAL '30 days'
         ORDER BY mr.next_due_date ASC
         LIMIT 8"
    );
    $recentReports = db_rows(
        'SELECT sr.id, sr.date, sr.technician, sr.summary, i.name AS instrument_name
         FROM service_reports sr
         LEFT JOIN instruments i ON i.id = sr.instrument_id
         ORDER BY sr.date DESC NULLS LAST, sr.id DESC
         LIMIT 8'
    );

    $cards = '';
    foreach ($summary as [$label, $value, $hint, $accent]) {
        $cards .= metric_card($label, $value, $hint, $accent);
    }

    $body = '<section class="content-section">
        <div class="section-header"><div><p class="eyebrow">Command center</p><h1>Operations Dashboard</h1></div></div>
        ' . flash_html() . '
        <div class="metric-grid">' . $cards . '</div>
        <div class="dashboard-grid">
            <section class="panel"><h2>Equipment Status</h2>' . status_bars($statusCounts) . '</section>
            <section class="panel"><h2>Maintenance Attention</h2>' . maintenance_attention($alerts) . '</section>
        </div>
        <section class="panel reports-panel"><h2>Recent Service Reports</h2>' .
            simple_list($recentReports, static fn (array $row): string => display($row['instrument_name']) . ' - ' . display($row['technician']) . ' - ' . display($row['date'])) .
        '</section>
    </section>';

    render_app('Dashboard', 'dashboard', $body);
}

function page_equipment(): never
{
    require_user();
    $rows = db_rows(
        'SELECT i.id, i.name, i.model, i.serial_number, i.manufacturer, i.location, i.status, i.purchase_date,
                c.name AS customer_name
         FROM instruments i
         LEFT JOIN customers c ON c.id = i.customer_id
         ORDER BY i.id DESC'
    );
    $customers = db_rows('SELECT id, name FROM customers ORDER BY name');
    $tableRows = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $tableRows[] = [
            $row['name'],
            $row['model'],
            $row['serial_number'],
            $row['customer_name'] ?? 'Unassigned',
            $row['location'],
            raw_cell(status_chip($row['status'])),
            raw_cell(row_actions([
                '<a class="icon-action" target="_blank" href="/equipment/' . $id . '/qrcode">QR</a>',
                '<a class="icon-action" href="/equipment/' . $id . '">Profile</a>',
                delete_form('/equipment/' . $id . '/delete'),
            ])),
        ];
    }

    $form = '<form class="form-grid" method="post" action="/equipment">
        ' . csrf_field() . '
        ' . input_html('Name', 'name', true) . '
        ' . input_html('Model', 'model') . '
        ' . input_html('Serial Number', 'serial_number') . '
        ' . input_html('Manufacturer', 'manufacturer') . '
        ' . input_html('Location', 'location') . '
        ' . select_html('Status', 'status', ['active' => 'Active', 'inactive' => 'Inactive', 'maintenance' => 'Maintenance', 'retired' => 'Retired'], 'active') . '
        ' . input_html('Purchase Date', 'purchase_date', false, 'date') . '
        ' . select_rows_html('Customer', 'customer_id', $customers, 'Unassigned') . '
        <button class="primary-action form-submit" type="submit">Add Equipment</button>
    </form>';

    render_app('Equipment', 'equipment', '<section class="content-section"><div class="section-header"><div><p class="eyebrow">Inventory</p><h1>Equipment</h1></div></div>' . flash_html() . $form . table_html(['Name', 'Model', 'Serial', 'Customer', 'Location', 'Status', 'Actions'], $tableRows) . '</section>');
}

function action_create_equipment(): never
{
    require_user();
    $name = post_string('name');

    if ($name === '') {
        flash('Equipment name is required.');
        redirect('/equipment');
    }

    $id = db_insert('instruments', [
        'name' => $name,
        'model' => nullable(post_string('model')),
        'serial_number' => nullable(post_string('serial_number')),
        'manufacturer' => nullable(post_string('manufacturer')),
        'location' => nullable(post_string('location')),
        'status' => nullable(post_string('status')) ?: 'active',
        'purchase_date' => nullable(post_string('purchase_date')),
        'customer_id' => nullable_int(post_string('customer_id')),
    ]);
    db_update_qr_path($id);
    flash('Equipment created.');
    redirect('/equipment');
}

function page_equipment_profile(int $id): never
{
    require_user();
    $instrument = db_row(
        'SELECT i.*, c.name AS customer_name FROM instruments i LEFT JOIN customers c ON c.id = i.customer_id WHERE i.id = :id',
        ['id' => $id]
    );

    if ($instrument === null) {
        not_found();
    }

    $maintenance = db_rows('SELECT date, type, technician, next_due_date FROM maintenance_records WHERE instrument_id = :id ORDER BY date DESC NULLS LAST LIMIT 8', ['id' => $id]);
    $reports = db_rows('SELECT id, date, technician, summary FROM service_reports WHERE instrument_id = :id ORDER BY date DESC NULLS LAST LIMIT 8', ['id' => $id]);
    $reportRows = array_map(static fn (array $row): array => [
        $row['date'],
        $row['technician'],
        $row['summary'],
        raw_cell('<a class="icon-action" href="/service-reports/' . (int) $row['id'] . '/pdf">PDF</a>'),
    ], $reports);

    $body = '<section class="content-section">
        <div class="section-header"><div><p class="eyebrow">Equipment Profile</p><h1>' . h($instrument['name']) . '</h1></div><a class="ghost-action" href="/equipment">Back</a></div>
        <div class="profile-grid">
            ' . detail_html('Model', $instrument['model']) . '
            ' . detail_html('Serial', $instrument['serial_number']) . '
            ' . detail_html('Manufacturer', $instrument['manufacturer']) . '
            ' . detail_html('Customer', $instrument['customer_name'] ?? 'Unassigned') . '
            ' . detail_html('Location', $instrument['location']) . '
            <div><span>Status</span>' . status_chip($instrument['status']) . '</div>
        </div>
        <section class="panel"><h2>QR Code</h2><div class="qr-inline"><img alt="Equipment QR code" src="/equipment/' . $id . '/qrcode"><span>' . h(scan_url($id)) . '</span></div></section>
        <div class="dashboard-grid">
            <section class="panel"><h2>Maintenance</h2>' . simple_list($maintenance, static fn (array $row): string => display($row['date']) . ' - ' . display($row['type']) . ' - ' . display($row['technician'])) . '</section>
            <section class="panel"><h2>Service Reports</h2>' . table_html(['Date', 'Technician', 'Summary', 'PDF'], $reportRows) . '</section>
        </div>
    </section>';

    render_app('Equipment Profile', 'equipment', $body);
}

function page_customers(): never
{
    simple_resource_page(
        'Customers',
        'customers',
        'Accounts',
        '/customers',
        [
            ['Name', 'name', true],
            ['Contact Person', 'contact_person', false],
            ['Email', 'email', false, 'email'],
            ['Phone', 'phone', false],
            ['Address', 'address', false],
        ],
        'customers',
        ['Name' => 'name', 'Contact' => 'contact_person', 'Email' => 'email', 'Phone' => 'phone', 'Address' => 'address']
    );
}

function action_create_customer(): never
{
    create_simple_row('customers', ['name', 'contact_person', 'email', 'phone', 'address'], '/customers', 'Customer created.', ['name']);
}

function page_maintenance(): never
{
    require_user();
    $instruments = db_rows('SELECT id, name FROM instruments ORDER BY name');
    $rows = db_rows(
        'SELECT mr.id, mr.date, mr.type, mr.description, mr.technician, mr.next_due_date, i.name AS instrument_name
         FROM maintenance_records mr
         LEFT JOIN instruments i ON i.id = mr.instrument_id
         ORDER BY mr.date DESC NULLS LAST, mr.id DESC'
    );
    $tableRows = [];

    foreach ($rows as $row) {
        $tableRows[] = [
            $row['instrument_name'],
            $row['date'],
            $row['type'],
            $row['technician'],
            $row['next_due_date'],
            $row['description'],
            raw_cell(delete_form('/maintenance/' . (int) $row['id'] . '/delete')),
        ];
    }

    $form = '<form class="form-grid" method="post" action="/maintenance">
        ' . csrf_field() . '
        ' . select_rows_html('Equipment', 'instrument_id', $instruments, 'Select equipment', null, true) . '
        ' . input_html('Date', 'date', false, 'date') . '
        ' . select_html('Type', 'type', ['preventive' => 'Preventive', 'corrective' => 'Corrective', 'inspection' => 'Inspection', 'calibration' => 'Calibration']) . '
        ' . input_html('Technician', 'technician') . '
        ' . input_html('Next Due', 'next_due_date', false, 'date') . '
        ' . textarea_html('Description', 'description') . '
        <button class="primary-action form-submit" type="submit">Add Record</button>
    </form>';

    render_app('Maintenance', 'maintenance', '<section class="content-section"><div class="section-header"><div><p class="eyebrow">Scheduling</p><h1>Maintenance</h1></div></div>' . flash_html() . $form . table_html(['Equipment', 'Date', 'Type', 'Technician', 'Next Due', 'Description', 'Actions'], $tableRows) . '</section>');
}

function action_create_maintenance(): never
{
    create_simple_row('maintenance_records', ['instrument_id', 'date', 'type', 'description', 'technician', 'next_due_date'], '/maintenance', 'Maintenance record created.', ['instrument_id'], ['instrument_id']);
}

function page_alerts(): never
{
    require_user();
    $rows = db_rows(
        "SELECT mr.id, mr.next_due_date, mr.type, mr.technician, i.name AS instrument_name,
                CASE WHEN mr.next_due_date < CURRENT_DATE THEN 'overdue' ELSE 'due soon' END AS alert_status
         FROM maintenance_records mr
         LEFT JOIN instruments i ON i.id = mr.instrument_id
         WHERE mr.next_due_date IS NOT NULL
           AND mr.next_due_date <= CURRENT_DATE + INTERVAL '30 days'
         ORDER BY mr.next_due_date ASC, mr.id ASC"
    );
    $tableRows = array_map(static fn (array $row): array => [
        $row['instrument_name'],
        $row['type'],
        $row['technician'],
        $row['next_due_date'],
        raw_cell(status_chip($row['alert_status'])),
    ], $rows);

    render_app('Alerts', 'alerts', '<section class="content-section"><div class="section-header"><div><p class="eyebrow">Maintenance</p><h1>Alerts</h1></div></div>' . table_html(['Equipment', 'Type', 'Technician', 'Due Date', 'Status'], $tableRows) . '</section>');
}

function page_notifications(): never
{
    require_user();
    $rows = db_rows('SELECT id, title, message, category, severity, is_read, created_at FROM notifications ORDER BY created_at DESC, id DESC');
    $tableRows = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $tableRows[] = [
            $row['title'],
            $row['message'],
            raw_cell(status_chip($row['severity'])),
            raw_cell(status_chip((bool) $row['is_read'] ? 'read' : 'unread')),
            $row['created_at'],
            raw_cell(row_actions([
                '<form method="post" action="/notifications/' . $id . '/read">' . csrf_field() . '<button class="icon-action" type="submit">Mark Read</button></form>',
                delete_form('/notifications/' . $id . '/delete'),
            ])),
        ];
    }

    $actions = '<div class="row-actions">
        <form method="post" action="/notifications/generate">' . csrf_field() . '<button class="primary-action" type="submit">Generate Reminders</button></form>
        <form method="post" action="/notifications/read-all">' . csrf_field() . '<button class="ghost-action" type="submit">Mark All Read</button></form>
    </div>';

    render_app('Notifications', 'notifications', '<section class="content-section"><div class="section-header"><div><p class="eyebrow">Reminders</p><h1>Notifications</h1></div>' . $actions . '</div>' . flash_html() . table_html(['Title', 'Message', 'Severity', 'Read', 'Created', 'Actions'], $tableRows) . '</section>');
}

function action_generate_notifications(): never
{
    require_user();
    $alerts = db_rows(
        "SELECT mr.id, mr.next_due_date, i.name AS instrument_name,
                CASE WHEN mr.next_due_date < CURRENT_DATE THEN 'critical' ELSE 'warning' END AS severity
         FROM maintenance_records mr
         LEFT JOIN instruments i ON i.id = mr.instrument_id
         WHERE mr.next_due_date IS NOT NULL AND mr.next_due_date <= CURRENT_DATE + INTERVAL '30 days'"
    );

    foreach ($alerts as $alert) {
        db_insert('notifications', [
            'title' => $alert['severity'] === 'critical' ? 'Overdue maintenance' : 'Maintenance due soon',
            'message' => display($alert['instrument_name']) . ' maintenance is due on ' . display($alert['next_due_date']),
            'category' => 'maintenance',
            'severity' => $alert['severity'],
            'is_read' => false,
            'maintenance_record_id' => $alert['id'],
        ]);
    }

    flash(count($alerts) . ' reminder(s) generated.');
    redirect('/notifications');
}

function action_mark_all_notifications_read(): never
{
    require_user();
    db_execute('UPDATE notifications SET is_read = true WHERE is_read = false');
    flash('All notifications marked as read.');
    redirect('/notifications');
}

function action_mark_notification_read(int $id): never
{
    require_user();
    db_execute('UPDATE notifications SET is_read = true WHERE id = :id', ['id' => $id]);
    flash('Notification marked as read.');
    redirect('/notifications');
}

function page_service_reports(): never
{
    require_user();
    $instruments = db_rows('SELECT id, name FROM instruments ORDER BY name');
    $rows = db_rows(
        'SELECT sr.id, sr.date, sr.summary, sr.technician, i.name AS instrument_name
         FROM service_reports sr
         LEFT JOIN instruments i ON i.id = sr.instrument_id
         ORDER BY sr.date DESC NULLS LAST, sr.id DESC'
    );
    $tableRows = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $tableRows[] = [
            $row['instrument_name'],
            $row['date'],
            $row['technician'],
            $row['summary'],
            raw_cell(row_actions([
                '<a class="icon-action" href="/service-reports/' . $id . '/pdf">PDF</a>',
                delete_form('/service-reports/' . $id . '/delete'),
            ])),
        ];
    }

    $form = '<form class="form-grid" method="post" action="/service-reports">
        ' . csrf_field() . '
        ' . select_rows_html('Equipment', 'instrument_id', $instruments, 'Select equipment', null, true) . '
        ' . input_html('Date', 'date', false, 'date') . '
        ' . input_html('Technician', 'technician') . '
        ' . textarea_html('Summary', 'summary') . '
        <button class="primary-action form-submit" type="submit">Add Report</button>
    </form>';

    render_app('Service Reports', 'service-reports', '<section class="content-section"><div class="section-header"><div><p class="eyebrow">Reports</p><h1>Service Reports</h1></div></div>' . flash_html() . $form . table_html(['Equipment', 'Date', 'Technician', 'Summary', 'Actions'], $tableRows) . '</section>');
}

function action_create_service_report(): never
{
    create_simple_row('service_reports', ['instrument_id', 'date', 'summary', 'technician'], '/service-reports', 'Service report created.', ['instrument_id'], ['instrument_id']);
}

function page_service_requests(): never
{
    require_user();
    $instruments = db_rows('SELECT id, name FROM instruments ORDER BY name');
    $customers = db_rows('SELECT id, name FROM customers ORDER BY name');
    $rows = db_rows(
        'SELECT sr.id, sr.description, sr.status, sr.assigned_technician, sr.created_date, sr.resolved_date,
                i.name AS instrument_name, c.name AS customer_name
         FROM service_requests sr
         LEFT JOIN instruments i ON i.id = sr.instrument_id
         LEFT JOIN customers c ON c.id = sr.customer_id
         ORDER BY sr.created_date DESC NULLS LAST, sr.id DESC'
    );
    $tableRows = [];

    foreach ($rows as $row) {
        $tableRows[] = [
            $row['instrument_name'],
            $row['customer_name'],
            $row['description'],
            raw_cell(status_chip($row['status'])),
            $row['assigned_technician'],
            $row['created_date'],
            $row['resolved_date'],
            raw_cell(delete_form('/service-requests/' . (int) $row['id'] . '/delete')),
        ];
    }

    $form = '<form class="form-grid" method="post" action="/service-requests">
        ' . csrf_field() . '
        ' . select_rows_html('Equipment', 'instrument_id', $instruments, 'Select equipment') . '
        ' . select_rows_html('Customer', 'customer_id', $customers, 'Select customer') . '
        ' . select_html('Status', 'status', ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed']) . '
        ' . input_html('Assigned Technician', 'assigned_technician') . '
        ' . input_html('Created Date', 'created_date', false, 'date') . '
        ' . input_html('Resolved Date', 'resolved_date', false, 'date') . '
        ' . textarea_html('Description', 'description') . '
        <button class="primary-action form-submit" type="submit">Add Request</button>
    </form>';

    render_app('Service Requests', 'service-requests', '<section class="content-section"><div class="section-header"><div><p class="eyebrow">Requests</p><h1>Service Requests</h1></div></div>' . flash_html() . $form . table_html(['Equipment', 'Customer', 'Description', 'Status', 'Technician', 'Created', 'Resolved', 'Actions'], $tableRows) . '</section>');
}

function action_create_service_request(): never
{
    create_simple_row(
        'service_requests',
        ['instrument_id', 'customer_id', 'description', 'status', 'assigned_technician', 'created_date', 'resolved_date'],
        '/service-requests',
        'Service request created.',
        [],
        ['instrument_id', 'customer_id']
    );
}

function page_documents(): never
{
    require_user();
    $instruments = db_rows('SELECT id, name FROM instruments ORDER BY name');
    $rows = db_rows(
        'SELECT d.id, d.title, d.category, d.file_path, d.uploaded_by, d.upload_date, d.description, i.name AS instrument_name
         FROM documents d
         LEFT JOIN instruments i ON i.id = d.instrument_id
         ORDER BY d.upload_date DESC NULLS LAST, d.id DESC'
    );
    $tableRows = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $download = $row['file_path']
            ? '<a class="icon-action" href="/documents/' . $id . '/download">Download</a>'
            : '';
        $tableRows[] = [
            $row['title'],
            $row['category'],
            $row['instrument_name'],
            $row['uploaded_by'],
            $row['upload_date'],
            $row['description'],
            raw_cell(row_actions([$download, delete_form('/documents/' . $id . '/delete')])),
        ];
    }

    $form = '<form class="form-grid" method="post" action="/documents" enctype="multipart/form-data">
        ' . csrf_field() . '
        ' . input_html('Title', 'title', true) . '
        ' . input_html('Category', 'category') . '
        ' . select_rows_html('Equipment', 'instrument_id', $instruments, 'Unassigned') . '
        ' . input_html('Upload Date', 'upload_date', false, 'date', date('Y-m-d')) . '
        ' . textarea_html('Description', 'description') . '
        <label class="field compact-field"><span>File</span><input name="file" type="file"></label>
        <button class="primary-action form-submit" type="submit">Add Document</button>
    </form>';

    render_app('Documents', 'documents', '<section class="content-section"><div class="section-header"><div><p class="eyebrow">Files</p><h1>Documents</h1></div></div>' . flash_html() . $form . table_html(['Title', 'Category', 'Equipment', 'Uploaded By', 'Date', 'Description', 'Actions'], $tableRows) . '</section>');
}

function action_create_document(): never
{
    require_user();
    $user = current_user();
    $title = post_string('title');

    if ($title === '') {
        flash('Document title is required.');
        redirect('/documents');
    }

    try {
        $path = upload_document_file();
        db_insert('documents', [
            'title' => $title,
            'category' => nullable(post_string('category')),
            'file_path' => $path,
            'instrument_id' => nullable_int(post_string('instrument_id')),
            'uploaded_by' => $user['username'] ?? 'system',
            'upload_date' => nullable(post_string('upload_date')) ?: date('Y-m-d'),
            'description' => nullable(post_string('description')),
        ]);
        flash('Document created.');
    } catch (Throwable $throwable) {
        flash($throwable->getMessage());
    }

    redirect('/documents');
}

function page_reports(): never
{
    require_user();
    $body = '<section class="content-section">
        <div class="section-header"><div><p class="eyebrow">Exports</p><h1>Reports</h1></div></div>
        <div class="export-grid">
            <section class="panel"><h2>Equipment CSV</h2><p>Inventory with customer IDs and equipment status.</p><a class="primary-action" href="/reports/equipment.csv">Download CSV</a></section>
            <section class="panel"><h2>Maintenance CSV</h2><p>Maintenance history and next due dates.</p><a class="primary-action" href="/reports/maintenance.csv">Download CSV</a></section>
            <section class="panel"><h2>Service Reports CSV</h2><p>Service summaries and technician names.</p><a class="primary-action" href="/reports/service-reports.csv">Download CSV</a></section>
            <section class="panel"><h2>Service Report PDFs</h2><p>Open any service report row and use its PDF button.</p><a class="ghost-action" href="/service-reports">Open Service Reports</a></section>
        </div>
    </section>';

    render_app('Reports', 'reports', $body);
}

function page_activity(): never
{
    require_user();
    $rows = db_rows('SELECT id, username, action, entity_type, entity_id, details, timestamp FROM activity_logs ORDER BY timestamp DESC, id DESC LIMIT 200');
    $tableRows = array_map(static fn (array $row): array => [
        $row['username'],
        $row['action'],
        $row['entity_type'],
        $row['entity_id'],
        $row['details'],
        $row['timestamp'],
    ], $rows);

    render_app('Activity', 'activity', '<section class="content-section"><div class="section-header"><div><p class="eyebrow">Audit</p><h1>Activity</h1></div></div>' . table_html(['User', 'Action', 'Entity', 'Entity ID', 'Details', 'Time'], $tableRows) . '</section>');
}

function page_profile(): never
{
    $user = require_user();
    $initials = strtoupper(substr((string) $user['username'], 0, 2));
    $body = '<section class="content-section">
        <div class="section-header"><div><p class="eyebrow">Account</p><h1>Profile</h1></div></div>
        <section class="panel account-hero">
            <span class="account-avatar">' . h($initials) . '</span>
            <div><h2>' . h($user['username']) . '</h2><p>' . h($user['email']) . '</p></div>
        </section>
        <div class="account-profile-grid">
            <section class="panel account-detail-list">
                <h2>Details</h2>
                <p><span>User ID</span><strong>' . h($user['id']) . '</strong></p>
                <p><span>Role</span><strong>' . h($user['role']) . '</strong></p>
                <p><span>Email</span><strong>' . h($user['email']) . '</strong></p>
            </section>
        </div>
    </section>';

    render_app('Profile', 'settings', $body);
}

function page_settings(): never
{
    require_user();
    $body = '<section class="content-section">
        <div class="section-header"><div><p class="eyebrow">System</p><h1>Settings</h1></div></div>
        <div class="settings-grid">
            <section class="panel settings-list">
                <h2>Application</h2>
                <p><span>App URL</span><strong>' . h(env_string('APP_URL', 'http://127.0.0.1:8080')) . '</strong></p>
                <p><span>Upload Path</span><strong>' . h(env_string('UPLOAD_PATH', 'storage/uploads')) . '</strong></p>
                <p><span>Max Upload Size</span><strong>' . h(env_int('MAX_UPLOAD_SIZE', 10485760)) . ' bytes</strong></p>
            </section>
            <section class="panel settings-list">
                <h2>Database</h2>
                <p><span>Host</span><strong>' . h(env_string('DB_HOST', '127.0.0.1')) . '</strong></p>
                <p><span>Port</span><strong>' . h(env_string('DB_PORT', '5432')) . '</strong></p>
                <p><span>Name</span><strong>' . h(env_string('DB_NAME', 'sciencespark_lab_db')) . '</strong></p>
            </section>
        </div>
    </section>';

    render_app('Settings', 'settings', $body);
}

function page_public_scan(int $id): never
{
    $instrument = db_row(
        'SELECT i.*, c.name AS customer_name FROM instruments i LEFT JOIN customers c ON c.id = i.customer_id WHERE i.id = :id',
        ['id' => $id]
    );

    if ($instrument === null) {
        html_page('Equipment not found', '<main class="public-scan-page"><section class="public-scan-shell"><div class="public-scan-card"><h1>Equipment not found</h1><p>This QR code does not match an equipment record.</p></div></section></main>', 404);
    }

    $maintenance = db_rows('SELECT date, type, technician, next_due_date FROM maintenance_records WHERE instrument_id = :id ORDER BY date DESC NULLS LAST LIMIT 5', ['id' => $id]);
    $reports = db_rows('SELECT date, technician, summary FROM service_reports WHERE instrument_id = :id ORDER BY date DESC NULLS LAST LIMIT 5', ['id' => $id]);
    $details = detail_html('Model', $instrument['model'])
        . detail_html('Serial', $instrument['serial_number'])
        . detail_html('Manufacturer', $instrument['manufacturer'])
        . detail_html('Customer', $instrument['customer_name'] ?? 'Unassigned')
        . detail_html('Location', $instrument['location'])
        . '<div><span>Status</span>' . status_chip($instrument['status']) . '</div>';
    $body = '<main class="public-scan-page">
        <section class="public-scan-shell">
            <section class="public-scan-hero">
                <div><p class="eyebrow">Science Spark Equipment</p><h1>' . h($instrument['name']) . '</h1><p>Read-only QR profile</p></div>
                <span class="scan-status">' . h(display($instrument['status'])) . '</span>
            </section>
            <section class="public-scan-card"><h2>Details</h2><div class="public-detail-grid">' . $details . '</div></section>
            <section class="public-scan-card"><h2>Recent Maintenance</h2>' . simple_list($maintenance, static fn (array $row): string => display($row['date']) . ' - ' . display($row['type']) . ' - ' . display($row['technician'])) . '</section>
            <section class="public-scan-card"><h2>Recent Service Reports</h2>' . simple_list($reports, static fn (array $row): string => display($row['date']) . ' - ' . display($row['technician'])) . '</section>
            <a class="primary-action public-dashboard-link" href="/dashboard">Open dashboard</a>
        </section>
    </main>';

    html_page('Equipment QR Profile', $body);
}

function action_delete(string $table, int $id, string $redirect, string $message): never
{
    require_user();

    try {
        db_delete($table, $id);
        flash($message);
    } catch (Throwable) {
        flash('Could not delete this record because it may be linked to other data.');
    }

    redirect($redirect);
}

function simple_resource_page(string $title, string $active, string $eyebrow, string $action, array $fields, string $table, array $columns): never
{
    require_user();
    $rows = db_rows('SELECT * FROM ' . safe_identifier($table) . ' ORDER BY id DESC');
    $form = '<form class="form-grid" method="post" action="' . h($action) . '">' . csrf_field();

    foreach ($fields as $field) {
        $form .= input_html($field[0], $field[1], $field[2], $field[3] ?? 'text');
    }

    $form .= '<button class="primary-action form-submit" type="submit">Add ' . h(rtrim($title, 's')) . '</button></form>';
    $tableRows = [];

    foreach ($rows as $row) {
        $tableRow = [];

        foreach ($columns as $column) {
            $tableRow[] = $row[$column] ?? '';
        }

        $tableRow[] = raw_cell(delete_form($action . '/' . (int) $row['id'] . '/delete'));
        $tableRows[] = $tableRow;
    }

    render_app($title, $active, '<section class="content-section"><div class="section-header"><div><p class="eyebrow">' . h($eyebrow) . '</p><h1>' . h($title) . '</h1></div></div>' . flash_html() . $form . table_html([...array_keys($columns), 'Actions'], $tableRows) . '</section>');
}

function create_simple_row(string $table, array $columns, string $redirect, string $message, array $required = [], array $intColumns = []): never
{
    require_user();
    $data = [];

    foreach ($columns as $column) {
        $value = post_string($column);

        if (in_array($column, $required, true) && $value === '') {
            flash(ucwords(str_replace('_', ' ', $column)) . ' is required.');
            redirect($redirect);
        }

        $data[$column] = in_array($column, $intColumns, true) ? nullable_int($value) : nullable($value);
    }

    db_insert($table, $data);
    flash($message);
    redirect($redirect);
}

function status_bars(array $rows): string
{
    if ($rows === []) {
        return '<div class="empty-state"><strong>No status data</strong><span>Equipment statuses will appear here.</span></div>';
    }

    $total = array_reduce($rows, static fn (int $sum, array $row): int => $sum + (int) $row['count'], 0);
    $html = '<div class="status-bars">';

    foreach ($rows as $row) {
        $count = (int) $row['count'];
        $width = $total > 0 ? max(4, (int) round(($count / $total) * 100)) : 0;
        $html .= '<div class="status-bar-row"><div><span>' . h(display($row['status'])) . '</span><strong>' . h($count) . '</strong></div><div class="bar-track"><span style="width: ' . $width . '%"></span></div></div>';
    }

    return $html . '</div>';
}

function maintenance_attention(array $rows): string
{
    if ($rows === []) {
        return '<div class="empty-state"><strong>No active alerts</strong><span>Due and overdue maintenance will appear here.</span></div>';
    }

    $html = '<div class="alert-list">';

    foreach ($rows as $row) {
        $status = is_string($row['next_due_date'] ?? null) && $row['next_due_date'] < date('Y-m-d') ? 'overdue' : 'due soon';
        $html .= '<div class="alert-item"><div><strong>' . h(display($row['instrument_name'])) . '</strong><span>' . h(display($row['type'])) . ' maintenance due ' . h(display($row['next_due_date'])) . '</span></div>' . status_chip($status) . '</div>';
    }

    return $html . '</div>';
}

function export_equipment_csv(): never
{
    send_csv(
        'equipment_export.csv',
        ['id', 'name', 'model', 'serial_number', 'manufacturer', 'location', 'status', 'purchase_date', 'customer_id'],
        'SELECT id, name, model, serial_number, manufacturer, location, status, purchase_date, customer_id FROM instruments ORDER BY id'
    );
}

function export_maintenance_csv(): never
{
    send_csv(
        'maintenance_export.csv',
        ['id', 'instrument_id', 'date', 'type', 'description', 'technician', 'next_due_date'],
        'SELECT id, instrument_id, date, type, description, technician, next_due_date FROM maintenance_records ORDER BY id'
    );
}

function export_service_reports_csv(): never
{
    send_csv(
        'service_reports_export.csv',
        ['id', 'instrument_id', 'date', 'report_file_path', 'summary', 'technician'],
        'SELECT id, instrument_id, date, report_file_path, summary, technician FROM service_reports ORDER BY id'
    );
}
