<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserRepository;
use chillerlan\QRCode\QRCode;
use PDO;
use Throwable;

final class WebController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users
    ) {
    }

    public function loginForm(Request $request): void
    {
        if ($this->currentUser() !== null) {
            $this->redirect('/dashboard');
            return;
        }

        $this->renderGuest('Sign in', '<div class="brand-lockup">
            <div class="brand-mark">SS</div>
            <div>
                <p class="eyebrow">Science Spark</p>
                <h1>Laboratory Operations</h1>
            </div>
        </div>
        <form class="login-form" method="post" action="/login">
            <div class="form-heading">
                <h2>Sign in</h2>
                <p>Access equipment, maintenance, service reports, and dashboards through native PHP.</p>
            </div>
            ' . $this->flashHtml() . '
            <label class="field"><span>Username or Email</span><input name="identifier" required autofocus></label>
            <label class="field"><span>Password</span><input name="password" type="password" required></label>
            <button class="primary-action" type="submit">Sign in</button>
        </form>');
    }

    public function login(Request $request): void
    {
        $identifier = trim((string) ($request->input('identifier') ?? ''));
        $password = (string) ($request->input('password') ?? '');
        $user = $identifier === '' ? null : $this->users->findByIdentifier($identifier);

        if (
            $user === null
            || !is_string($user['hashed_password'] ?? null)
            || !password_verify($password, $user['hashed_password'])
        ) {
            $this->flash('Incorrect username or password.');
            $this->redirect('/login');
            return;
        }

        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];

        $this->redirect('/dashboard');
    }

    public function logout(Request $request): void
    {
        $_SESSION = [];
        session_destroy();
        session_start();
        $this->flash('Signed out.');
        $this->redirect('/login');
    }

    public function dashboard(Request $request): void
    {
        $this->requireUser();
        $activeEquipment = $this->scalar("SELECT COUNT(*)::int FROM instruments WHERE status = 'active'");
        $summary = [
            'Total Equipment' => [$this->count('instruments'), $activeEquipment . ' active'],
            'Customers' => [$this->count('customers'), 'Linked to inventory'],
            'Due Soon' => [$this->scalar("SELECT COUNT(*)::int FROM maintenance_records WHERE next_due_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'"), 'Next 30 days'],
            'Overdue' => [$this->scalar('SELECT COUNT(*)::int FROM maintenance_records WHERE next_due_date IS NOT NULL AND next_due_date < CURRENT_DATE'), 'Needs attention'],
            'Reports This Month' => [$this->scalar("SELECT COUNT(*)::int FROM service_reports WHERE date >= DATE_TRUNC('month', CURRENT_DATE)"), 'Generated PDFs'],
        ];
        $statusCounts = $this->rows(
            "SELECT COALESCE(NULLIF(status, ''), 'unknown') AS status, COUNT(*)::int AS count
             FROM instruments
             GROUP BY COALESCE(NULLIF(status, ''), 'unknown')
             ORDER BY count DESC, status"
        );
        $recentReports = $this->rows(
            'SELECT sr.id, sr.date, sr.technician, sr.summary, i.name AS instrument_name
             FROM service_reports sr
             LEFT JOIN instruments i ON i.id = sr.instrument_id
             ORDER BY sr.date DESC NULLS LAST, sr.id DESC
             LIMIT 8'
        );
        $alerts = $this->rows(
            'SELECT mr.id, mr.next_due_date, mr.type, i.name AS instrument_name
             FROM maintenance_records mr
             LEFT JOIN instruments i ON i.id = mr.instrument_id
             WHERE mr.next_due_date IS NOT NULL
               AND mr.next_due_date <= CURRENT_DATE + INTERVAL \'30 days\'
             ORDER BY mr.next_due_date ASC
             LIMIT 8'
        );

        $cards = '';
        $accents = ['accent-mint', 'accent-blue', 'accent-amber', 'accent-rose', ''];
        $index = 0;
        foreach ($summary as $label => [$value, $hint]) {
            $cards .= '<article class="metric-card ' . $accents[$index % count($accents)] . '"><span>' . $this->e($label) . '</span><strong>' . $this->e($value) . '</strong><small>' . $this->e($hint) . '</small></article>';
            $index++;
        }
        $attention = $this->maintenanceAttention($alerts);

        $body = '<section class="content-section">
            <div class="section-header"><div><p class="eyebrow">Command center</p><h1>Operations Dashboard</h1></div></div>
            ' . $this->flashHtml() . '
            <div class="metric-grid">' . $cards . '</div>
            <div class="dashboard-grid">
                <section class="panel"><h2>Equipment Status</h2>' . $this->statusBars($statusCounts) . '</section>
                <section class="panel"><h2>Maintenance Attention</h2>' . $attention . '</section>
            </div>
            <section class="panel reports-panel"><h2>Recent Service Reports</h2>' .
                $this->simpleList($recentReports, static fn (array $row): string => ($row['instrument_name'] ?? 'Equipment') . ' - ' . ($row['technician'] ?? 'Not set') . ' - ' . ($row['date'] ?? 'Not set')) .
            '</section>
        </section>';

        $this->render('Dashboard', 'dashboard', $body);
    }

    public function equipment(Request $request): void
    {
        $this->requireUser();
        $rows = $this->rows(
            'SELECT i.id, i.name, i.model, i.serial_number, i.manufacturer, i.location, i.status, i.purchase_date,
                    c.name AS customer_name
             FROM instruments i
             LEFT JOIN customers c ON c.id = i.customer_id
             ORDER BY i.id DESC'
        );
        $customers = $this->rows('SELECT id, name FROM customers ORDER BY name');

        $body = '<section class="content-section">
            <div class="section-header"><div><p class="eyebrow">Inventory</p><h1>Equipment</h1></div></div>
            ' . $this->flashHtml() . '
            <form class="form-grid" method="post" action="/equipment">
                ' . $this->input('Name', 'name', true) . '
                ' . $this->input('Model', 'model') . '
                ' . $this->input('Serial Number', 'serial_number') . '
                ' . $this->input('Manufacturer', 'manufacturer') . '
                ' . $this->input('Location', 'location') . '
                ' . $this->select('Status', 'status', [
                    'active' => 'Active',
                    'inactive' => 'Inactive',
                    'maintenance' => 'Maintenance',
                    'retired' => 'Retired',
                ]) . '
                ' . $this->input('Purchase Date', 'purchase_date', false, 'date') . '
                ' . $this->selectFromRows('Customer', 'customer_id', $customers, 'Unassigned') . '
                <button class="primary-action form-submit" type="submit">Add Equipment</button>
            </form>' .
            $this->table(
                ['Name', 'Model', 'Serial', 'Customer', 'Location', 'Status', 'QR', 'Actions'],
                array_map(fn (array $row): array => [
                    $row['name'],
                    $row['model'],
                    $row['serial_number'],
                    $row['customer_name'] ?? 'Unassigned',
                    $row['location'],
                    $this->statusChip($row['status']),
                    '<button class="icon-action qr-action" type="button" data-qr-src="/equipment/' . (int) $row['id'] . '/qrcode" data-equipment-name="' . $this->e($row['name']) . '" data-profile-url="/equipment/' . (int) $row['id'] . '">View QR</button>',
                    '<div class="row-actions"><a class="icon-action" href="/equipment/' . (int) $row['id'] . '">Profile</a>' . $this->deleteForm('/equipment/' . (int) $row['id'] . '/delete') . '</div>',
                ], $rows),
                true
            ) . '
        </section>';

        $this->render('Equipment', 'equipment', $body);
    }

    public function createEquipment(Request $request): void
    {
        $this->requireUser();
        $id = $this->insert('instruments', [
            'name' => $request->input('name'),
            'model' => $this->nullable($request->input('model')),
            'serial_number' => $this->nullable($request->input('serial_number')),
            'manufacturer' => $this->nullable($request->input('manufacturer')),
            'location' => $this->nullable($request->input('location')),
            'status' => $this->nullable($request->input('status')) ?: 'active',
            'purchase_date' => $this->nullable($request->input('purchase_date')),
            'customer_id' => $this->nullableInt($request->input('customer_id')),
        ]);
        $this->execute(
            'UPDATE instruments SET qr_code_path = :path WHERE id = :id',
            ['path' => $this->scanUrl($id), 'id' => $id]
        );
        $this->flash('Equipment created.');
        $this->redirect('/equipment');
    }

    public function equipmentProfile(Request $request): void
    {
        $this->requireUser();
        $id = $this->routeId($request);
        $instrument = $this->row(
            'SELECT i.*, c.name AS customer_name FROM instruments i LEFT JOIN customers c ON c.id = i.customer_id WHERE i.id = :id',
            ['id' => $id]
        );

        if ($instrument === null) {
            $this->flash('Equipment not found.');
            $this->redirect('/equipment');
            return;
        }

        $maintenance = $this->rows('SELECT date, type, technician, next_due_date FROM maintenance_records WHERE instrument_id = :id ORDER BY date DESC NULLS LAST LIMIT 8', ['id' => $id]);
        $reports = $this->rows('SELECT date, technician, summary FROM service_reports WHERE instrument_id = :id ORDER BY date DESC NULLS LAST LIMIT 8', ['id' => $id]);

        $body = '<section class="content-section">
            <div class="section-header"><div><p class="eyebrow">Equipment Profile</p><h1>' . $this->e($instrument['name']) . '</h1></div><a class="ghost-action" href="/equipment">Back</a></div>
            <div class="profile-grid">
                ' . $this->detail('Model', $instrument['model']) . '
                ' . $this->detail('Serial', $instrument['serial_number']) . '
                ' . $this->detail('Manufacturer', $instrument['manufacturer']) . '
                ' . $this->detail('Customer', $instrument['customer_name'] ?? 'Unassigned') . '
                ' . $this->detail('Location', $instrument['location']) . '
                <div><span>Status</span>' . $this->statusChip($instrument['status']) . '</div>
            </div>
            <section class="panel"><h2>QR Code</h2><div class="qr-inline"><img alt="Equipment QR code" src="/equipment/' . $id . '/qrcode"><span>' . $this->e($this->scanUrl($id)) . '</span></div></section>
            <div class="dashboard-grid">
                <section class="panel"><h2>Maintenance</h2>' . $this->simpleList($maintenance, static fn (array $row): string => ($row['date'] ?? 'Not set') . ' - ' . ($row['type'] ?? 'maintenance') . ' - ' . ($row['technician'] ?? 'Not set')) . '</section>
                <section class="panel"><h2>Service Reports</h2>' . $this->simpleList($reports, static fn (array $row): string => ($row['date'] ?? 'Not set') . ' - ' . ($row['technician'] ?? 'Not set')) . '</section>
            </div>
        </section>';

        $this->render('Equipment Profile', 'equipment', $body);
    }

    public function equipmentQr(Request $request): void
    {
        $id = $this->routeId($request);
        if ($this->row('SELECT id FROM instruments WHERE id = :id', ['id' => $id]) === null) {
            Response::raw($this->missingQrSvg(), 'image/svg+xml', 404);
            return;
        }

        $svg = $this->dataUriContent((new QRCode())->render($this->scanUrl($id)));
        Response::raw($svg, 'image/svg+xml');
    }

    public function deleteEquipment(Request $request): void
    {
        $this->deleteById('instruments', $this->routeId($request));
        $this->flash('Equipment deleted.');
        $this->redirect('/equipment');
    }

    public function customers(Request $request): void
    {
        $this->resourcePage(
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

    public function createCustomer(Request $request): void
    {
        $this->createSimple('customers', ['name', 'contact_person', 'email', 'phone', 'address'], '/customers', 'Customer created.');
    }

    public function deleteCustomer(Request $request): void
    {
        $this->deleteSimple('customers', $request, '/customers', 'Customer deleted.');
    }

    public function maintenance(Request $request): void
    {
        $this->requireUser();
        $instruments = $this->rows('SELECT id, name FROM instruments ORDER BY name');
        $rows = $this->rows(
            'SELECT mr.id, mr.date, mr.type, mr.description, mr.technician, mr.next_due_date, i.name AS instrument_name
             FROM maintenance_records mr
             LEFT JOIN instruments i ON i.id = mr.instrument_id
             ORDER BY mr.date DESC NULLS LAST, mr.id DESC'
        );
        $body = '<section class="content-section">
            <div class="section-header"><div><p class="eyebrow">Scheduling</p><h1>Maintenance</h1></div></div>
            ' . $this->flashHtml() . '
            <form class="form-grid" method="post" action="/maintenance">
                ' . $this->selectFromRows('Equipment', 'instrument_id', $instruments, 'Select equipment', true) . '
                ' . $this->input('Date', 'date', false, 'date') . '
                ' . $this->select('Type', 'type', ['preventive' => 'Preventive', 'corrective' => 'Corrective', 'inspection' => 'Inspection', 'calibration' => 'Calibration']) . '
                ' . $this->input('Technician', 'technician') . '
                ' . $this->input('Next Due', 'next_due_date', false, 'date') . '
                ' . $this->input('Description', 'description') . '
                <button class="primary-action form-submit" type="submit">Add Record</button>
            </form>' .
            $this->table(
                ['Equipment', 'Date', 'Type', 'Technician', 'Next Due', 'Description', 'Actions'],
                array_map(fn (array $row): array => [
                    $row['instrument_name'],
                    $row['date'],
                    $row['type'],
                    $row['technician'],
                    $row['next_due_date'],
                    $row['description'],
                    $this->deleteForm('/maintenance/' . (int) $row['id'] . '/delete'),
                ], $rows),
                true
            ) . '
        </section>';
        $this->render('Maintenance', 'maintenance', $body);
    }

    public function createMaintenance(Request $request): void
    {
        $this->createSimple('maintenance_records', ['instrument_id', 'date', 'type', 'description', 'technician', 'next_due_date'], '/maintenance', 'Maintenance record created.', ['instrument_id']);
    }

    public function deleteMaintenance(Request $request): void
    {
        $this->deleteSimple('maintenance_records', $request, '/maintenance', 'Maintenance record deleted.');
    }

    public function notifications(Request $request): void
    {
        $this->requireUser();
        $rows = $this->rows('SELECT id, title, message, category, severity, is_read, created_at FROM notifications ORDER BY created_at DESC, id DESC');
        $body = '<section class="content-section">
            <div class="section-header"><div><p class="eyebrow">Reminders</p><h1>Notifications</h1></div>
                <div class="row-actions">
                    <form method="post" action="/notifications/generate"><button class="primary-action" type="submit">Generate Reminders</button></form>
                    <form method="post" action="/notifications/read-all"><button class="ghost-action" type="submit">Mark All Read</button></form>
                </div>
            </div>
            ' . $this->flashHtml() .
            $this->table(
                ['Title', 'Message', 'Severity', 'Read', 'Created', 'Actions'],
                array_map(fn (array $row): array => [
                    $row['title'],
                    $row['message'],
                    $this->statusChip($row['severity']),
                    $this->statusChip(((bool) $row['is_read']) ? 'read' : 'unread'),
                    $row['created_at'],
                    '<div class="row-actions"><form method="post" action="/notifications/' . (int) $row['id'] . '/read"><button class="icon-action" type="submit">Mark Read</button></form>' . $this->deleteForm('/notifications/' . (int) $row['id'] . '/delete') . '</div>',
                ], $rows),
                true
            ) . '
        </section>';
        $this->render('Notifications', 'notifications', $body);
    }

    public function generateNotifications(Request $request): void
    {
        $this->requireUser();
        $alerts = $this->rows(
            "SELECT mr.id, mr.next_due_date, i.name AS instrument_name,
                    CASE WHEN mr.next_due_date < CURRENT_DATE THEN 'critical' ELSE 'warning' END AS severity
             FROM maintenance_records mr
             LEFT JOIN instruments i ON i.id = mr.instrument_id
             WHERE mr.next_due_date IS NOT NULL AND mr.next_due_date <= CURRENT_DATE + INTERVAL '30 days'"
        );
        foreach ($alerts as $alert) {
            $this->insert('notifications', [
                'title' => $alert['severity'] === 'critical' ? 'Overdue maintenance' : 'Maintenance due soon',
                'message' => ($alert['instrument_name'] ?? 'Equipment') . ' maintenance is due on ' . ($alert['next_due_date'] ?? 'not set'),
                'category' => 'maintenance',
                'severity' => $alert['severity'],
                'is_read' => false,
                'maintenance_record_id' => $alert['id'],
            ]);
        }
        $this->flash(count($alerts) . ' reminder(s) generated.');
        $this->redirect('/notifications');
    }

    public function markAllNotificationsRead(Request $request): void
    {
        $this->execute('UPDATE notifications SET is_read = true WHERE is_read = false');
        $this->flash('All notifications marked as read.');
        $this->redirect('/notifications');
    }

    public function markNotificationRead(Request $request): void
    {
        $this->execute('UPDATE notifications SET is_read = true WHERE id = :id', ['id' => $this->routeId($request)]);
        $this->flash('Notification marked as read.');
        $this->redirect('/notifications');
    }

    public function deleteNotification(Request $request): void
    {
        $this->deleteSimple('notifications', $request, '/notifications', 'Notification deleted.');
    }

    public function alerts(Request $request): void
    {
        $this->requireUser();
        $overdue = $this->rows(
            'SELECT mr.next_due_date, mr.type, mr.technician, i.name AS instrument_name
             FROM maintenance_records mr LEFT JOIN instruments i ON i.id = mr.instrument_id
             WHERE mr.next_due_date IS NOT NULL AND mr.next_due_date < CURRENT_DATE
             ORDER BY mr.next_due_date'
        );
        $dueSoon = $this->rows(
            "SELECT mr.next_due_date, mr.type, mr.technician, i.name AS instrument_name
             FROM maintenance_records mr LEFT JOIN instruments i ON i.id = mr.instrument_id
             WHERE mr.next_due_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
             ORDER BY mr.next_due_date"
        );
        $body = '<section class="content-section">
            <div class="section-header"><div><p class="eyebrow">Maintenance</p><h1>Alerts</h1></div></div>
            <div class="dashboard-grid">
                <section class="panel"><h2>Overdue</h2>' . $this->simpleList($overdue, static fn (array $row): string => ($row['instrument_name'] ?? 'Equipment') . ' - due ' . ($row['next_due_date'] ?? 'not set')) . '</section>
                <section class="panel"><h2>Due Soon</h2>' . $this->simpleList($dueSoon, static fn (array $row): string => ($row['instrument_name'] ?? 'Equipment') . ' - due ' . ($row['next_due_date'] ?? 'not set')) . '</section>
            </div>
        </section>';
        $this->render('Alerts', 'alerts', $body);
    }

    public function serviceReports(Request $request): void
    {
        $this->requireUser();
        $instruments = $this->rows('SELECT id, name FROM instruments ORDER BY name');
        $rows = $this->rows(
            'SELECT sr.id, sr.date, sr.technician, sr.summary, i.name AS instrument_name
             FROM service_reports sr LEFT JOIN instruments i ON i.id = sr.instrument_id
             ORDER BY sr.date DESC NULLS LAST, sr.id DESC'
        );
        $body = '<section class="content-section"><div class="section-header"><div><p class="eyebrow">Field service</p><h1>Service Reports</h1></div></div>' . $this->flashHtml() . '
            <form class="form-grid" method="post" action="/service-reports">
                ' . $this->selectFromRows('Equipment', 'instrument_id', $instruments, 'Select equipment', true) . '
                ' . $this->input('Date', 'date', false, 'date') . '
                ' . $this->input('Technician', 'technician') . '
                ' . $this->input('Summary', 'summary') . '
                <button class="primary-action form-submit" type="submit">Create Report</button>
            </form>' .
            $this->table(['Equipment', 'Date', 'Technician', 'Summary', 'Actions'], array_map(fn (array $row): array => [
                $row['instrument_name'], $row['date'], $row['technician'], $row['summary'], $this->deleteForm('/service-reports/' . (int) $row['id'] . '/delete'),
            ], $rows), true) . '</section>';
        $this->render('Service Reports', 'serviceReports', $body);
    }

    public function createServiceReport(Request $request): void
    {
        $this->createSimple('service_reports', ['instrument_id', 'date', 'summary', 'technician'], '/service-reports', 'Service report created.', ['instrument_id']);
    }

    public function deleteServiceReport(Request $request): void
    {
        $this->deleteSimple('service_reports', $request, '/service-reports', 'Service report deleted.');
    }

    public function serviceRequests(Request $request): void
    {
        $this->requireUser();
        $instruments = $this->rows('SELECT id, name FROM instruments ORDER BY name');
        $customers = $this->rows('SELECT id, name FROM customers ORDER BY name');
        $rows = $this->rows(
            'SELECT sr.id, sr.status, sr.description, sr.assigned_technician, sr.created_date,
                    i.name AS instrument_name, c.name AS customer_name
             FROM service_requests sr
             LEFT JOIN instruments i ON i.id = sr.instrument_id
             LEFT JOIN customers c ON c.id = sr.customer_id
             ORDER BY sr.id DESC'
        );
        $body = '<section class="content-section"><div class="section-header"><div><p class="eyebrow">Requests</p><h1>Service Requests</h1></div></div>' . $this->flashHtml() . '
            <form class="form-grid" method="post" action="/service-requests">
                ' . $this->selectFromRows('Equipment', 'instrument_id', $instruments, 'Select equipment', true) . '
                ' . $this->selectFromRows('Customer', 'customer_id', $customers, 'Select customer', true) . '
                ' . $this->input('Technician', 'assigned_technician') . '
                ' . $this->select('Status', 'status', ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed']) . '
                ' . $this->input('Description', 'description', true) . '
                <button class="primary-action form-submit" type="submit">Open Request</button>
            </form>' .
            $this->table(['Equipment', 'Customer', 'Status', 'Technician', 'Created', 'Description', 'Actions'], array_map(fn (array $row): array => [
                $row['instrument_name'], $row['customer_name'], $this->statusChip($row['status']), $row['assigned_technician'], $row['created_date'], $row['description'], $this->deleteForm('/service-requests/' . (int) $row['id'] . '/delete'),
            ], $rows), true) . '</section>';
        $this->render('Service Requests', 'serviceRequests', $body);
    }

    public function createServiceRequest(Request $request): void
    {
        $this->createSimple('service_requests', ['instrument_id', 'customer_id', 'description', 'status', 'assigned_technician', 'created_date'], '/service-requests', 'Service request created.', ['instrument_id', 'customer_id']);
    }

    public function deleteServiceRequest(Request $request): void
    {
        $this->deleteSimple('service_requests', $request, '/service-requests', 'Service request deleted.');
    }

    public function documents(Request $request): void
    {
        $this->requireUser();
        $instruments = $this->rows('SELECT id, name FROM instruments ORDER BY name');
        $rows = $this->rows(
            'SELECT d.id, d.title, d.category, d.uploaded_by, d.upload_date, d.description, i.name AS instrument_name
             FROM documents d LEFT JOIN instruments i ON i.id = d.instrument_id
             ORDER BY d.upload_date DESC NULLS LAST, d.id DESC'
        );
        $body = '<section class="content-section"><div class="section-header"><div><p class="eyebrow">Knowledge base</p><h1>Documents</h1></div></div>' . $this->flashHtml() . '
            <form class="form-grid" method="post" action="/documents">
                ' . $this->input('Title', 'title', true) . '
                ' . $this->input('Category', 'category', true) . '
                ' . $this->selectFromRows('Equipment', 'instrument_id', $instruments, 'Unlinked') . '
                ' . $this->input('Description', 'description') . '
                <button class="primary-action form-submit" type="submit">Add Document</button>
            </form>' .
            $this->table(['Title', 'Category', 'Equipment', 'Uploaded By', 'Uploaded', 'Description', 'Actions'], array_map(fn (array $row): array => [
                $row['title'], $row['category'], $row['instrument_name'] ?? 'Unlinked', $row['uploaded_by'], $row['upload_date'], $row['description'], $this->deleteForm('/documents/' . (int) $row['id'] . '/delete'),
            ], $rows), true) . '</section>';
        $this->render('Documents', 'documents', $body);
    }

    public function createDocument(Request $request): void
    {
        $user = $this->requireUser();
        $this->insert('documents', [
            'title' => $request->input('title'),
            'category' => $request->input('category'),
            'file_path' => 'manual-entry',
            'instrument_id' => $this->nullableInt($request->input('instrument_id')),
            'uploaded_by' => $user['username'],
            'upload_date' => date('Y-m-d'),
            'description' => $this->nullable($request->input('description')),
        ]);
        $this->flash('Document record created.');
        $this->redirect('/documents');
    }

    public function deleteDocument(Request $request): void
    {
        $this->deleteSimple('documents', $request, '/documents', 'Document deleted.');
    }

    public function reports(Request $request): void
    {
        $this->requireUser();
        $body = '<section class="content-section">
            <div class="section-header"><div><p class="eyebrow">Exports</p><h1>Reports</h1></div></div>
            <div class="export-grid">
                <section class="panel"><h2>Equipment</h2><a class="primary-action" href="/reports/equipment.csv">Download CSV</a></section>
                <section class="panel"><h2>Maintenance</h2><a class="primary-action" href="/reports/maintenance.csv">Download CSV</a></section>
                <section class="panel"><h2>Service Reports</h2><a class="primary-action" href="/reports/service-reports.csv">Download CSV</a></section>
            </div>
        </section>';
        $this->render('Reports', 'exports', $body);
    }

    public function exportEquipment(Request $request): void
    {
        $this->csv('equipment.csv', ['id', 'name', 'model', 'serial_number', 'manufacturer', 'location', 'status'], 'SELECT id, name, model, serial_number, manufacturer, location, status FROM instruments ORDER BY id');
    }

    public function exportMaintenance(Request $request): void
    {
        $this->csv('maintenance.csv', ['id', 'instrument_id', 'date', 'type', 'technician', 'next_due_date'], 'SELECT id, instrument_id, date, type, technician, next_due_date FROM maintenance_records ORDER BY id');
    }

    public function exportServiceReports(Request $request): void
    {
        $this->csv('service-reports.csv', ['id', 'instrument_id', 'date', 'technician', 'summary'], 'SELECT id, instrument_id, date, technician, summary FROM service_reports ORDER BY id');
    }

    public function activity(Request $request): void
    {
        $this->requireUser();
        $rows = $this->rows('SELECT id, username, action, entity_type, entity_id, details, timestamp FROM activity_logs ORDER BY timestamp DESC LIMIT 100');
        $body = '<section class="content-section"><div class="section-header"><div><p class="eyebrow">Audit</p><h1>Activity</h1></div></div>' .
            $this->table(['Time', 'User', 'Action', 'Entity', 'Entity ID', 'Details'], array_map(fn (array $row): array => [
                $row['timestamp'], $row['username'], $row['action'], $row['entity_type'], $row['entity_id'], $row['details'],
            ], $rows)) . '</section>';
        $this->render('Activity', 'activity', $body);
    }

    public function profile(Request $request): void
    {
        $user = $this->requireUser();
        $body = '<section class="content-section">
            <div class="section-header"><div><p class="eyebrow">Account</p><h1>Profile</h1></div></div>
            <div class="account-profile-grid">
                <section class="panel account-hero"><div class="account-avatar">' . $this->e(strtoupper(substr((string) $user['username'], 0, 2))) . '</div><div><p class="eyebrow">Signed in as</p><h2>' . $this->e($user['username']) . '</h2><p>' . $this->e($user['email']) . '</p></div></section>
                <section class="panel account-detail-list"><h2>Account Details</h2>' . $this->detailRow('Role', $user['role']) . $this->detailRow('User ID', $user['id']) . $this->detailRow('Backend', 'Native PHP') . '</section>
                <section class="panel account-detail-list"><h2>Workspace Snapshot</h2>' . $this->detailRow('Equipment', $this->count('instruments')) . $this->detailRow('Customers', $this->count('customers')) . $this->detailRow('Unread Notifications', $this->scalar('SELECT COUNT(*)::int FROM notifications WHERE is_read = false')) . '</section>
            </div>
        </section>';
        $this->render('Profile', 'profile', $body);
    }

    public function settings(Request $request): void
    {
        $user = $this->requireUser();
        $body = '<section class="content-section">
            <div class="section-header"><div><p class="eyebrow">Preferences</p><h1>Settings</h1></div></div>
            <div class="settings-grid">
                <section class="panel settings-panel"><h2>Account</h2><div class="settings-list">' . $this->detailRow('Username', $user['username']) . $this->detailRow('Role', $user['role']) . $this->detailRow('Email', $user['email']) . '</div><form method="post" action="/logout"><button class="danger-action" type="submit">Sign out</button></form></section>
                <section class="panel settings-panel"><h2>Interface</h2><div class="settings-list">' . $this->detailRow('Frontend', 'PHP + HTML5') . $this->detailRow('Theme', 'Purple Night') . $this->detailRow('Sidebar', 'Compact') . '</div></section>
                <section class="panel settings-panel"><h2>System</h2><div class="settings-list">' . $this->detailRow('Backend', 'Native PHP') . $this->detailRow('Database', 'PostgreSQL') . $this->detailRow('QR', 'Server-generated SVG') . '</div></section>
            </div>
        </section>';
        $this->render('Settings', 'settings', $body);
    }

    public function publicScan(Request $request): void
    {
        $id = $this->routeId($request);
        $instrument = $this->rowOrFail('SELECT * FROM instruments WHERE id = :id', ['id' => $id]);
        $maintenance = $this->rows('SELECT date, type, technician FROM maintenance_records WHERE instrument_id = :id ORDER BY date DESC NULLS LAST LIMIT 5', ['id' => $id]);
        $reports = $this->rows('SELECT date, technician FROM service_reports WHERE instrument_id = :id ORDER BY date DESC NULLS LAST LIMIT 5', ['id' => $id]);

        $body = '<main class="public-scan-page"><div class="public-scan-shell">
            <section class="public-scan-hero"><div><p class="eyebrow">Science Spark Equipment</p><h1>' . $this->e($instrument['name']) . '</h1><p>Read-only QR profile</p></div><span class="scan-status">' . $this->e($instrument['status']) . '</span></section>
            <section class="public-scan-card"><h2>Details</h2><div class="public-detail-grid">' .
                $this->detail('Model', $instrument['model']) .
                $this->detail('Serial', $instrument['serial_number']) .
                $this->detail('Manufacturer', $instrument['manufacturer']) .
                $this->detail('Location', $instrument['location']) .
                $this->detail('Purchase Date', $instrument['purchase_date']) .
            '</div></section>
            <section class="public-scan-card"><h2>Recent Maintenance</h2>' . $this->simpleList($maintenance, static fn (array $row): string => ($row['date'] ?? 'Not set') . ' - ' . ($row['type'] ?? 'maintenance') . ' - ' . ($row['technician'] ?? 'Not set')) . '</section>
            <section class="public-scan-card"><h2>Recent Service Reports</h2>' . $this->simpleList($reports, static fn (array $row): string => ($row['date'] ?? 'Not set') . ' - ' . ($row['technician'] ?? 'Not set')) . '</section>
            <a class="primary-action public-dashboard-link" href="/equipment">Open dashboard</a>
        </div></main>';

        $this->htmlPage('Equipment QR Profile', $body);
    }

    private function resourcePage(string $title, string $active, string $eyebrow, string $action, array $fields, string $table, array $columns): void
    {
        $this->requireUser();
        $rows = $this->rows('SELECT * FROM ' . $table . ' ORDER BY id DESC');
        $form = '<form class="form-grid" method="post" action="' . $this->e($action) . '">';
        foreach ($fields as $field) {
            $form .= $this->input($field[0], $field[1], $field[2], $field[3] ?? 'text');
        }
        $form .= '<button class="primary-action form-submit" type="submit">Add ' . $this->e(rtrim($title, 's')) . '</button></form>';
        $bodyRows = [];
        foreach ($rows as $row) {
            $bodyRow = [];
            foreach ($columns as $column) {
                $bodyRow[] = $row[$column] ?? '';
            }
            $bodyRow[] = $this->deleteForm($action . '/' . (int) $row['id'] . '/delete');
            $bodyRows[] = $bodyRow;
        }
        $body = '<section class="content-section"><div class="section-header"><div><p class="eyebrow">' . $this->e($eyebrow) . '</p><h1>' . $this->e($title) . '</h1></div></div>' . $this->flashHtml() . $form . $this->table([...array_keys($columns), 'Actions'], $bodyRows, true) . '</section>';
        $this->render($title, $active, $body);
    }

    private function createSimple(string $table, array $columns, string $redirect, string $message, array $intColumns = []): void
    {
        $this->requireUser();
        $data = [];
        foreach ($columns as $column) {
            $value = $_POST[$column] ?? null;
            $data[$column] = in_array($column, $intColumns, true) ? $this->nullableInt($value) : $this->nullable($value);
        }
        $this->insert($table, $data);
        $this->flash($message);
        $this->redirect($redirect);
    }

    private function deleteSimple(string $table, Request $request, string $redirect, string $message): void
    {
        $this->requireUser();
        $this->deleteById($table, $this->routeId($request));
        $this->flash($message);
        $this->redirect($redirect);
    }

    private function renderGuest(string $title, string $body): void
    {
        $this->htmlPage($title, '<main class="login-page"><section class="login-panel">' . $body . '</section></main>');
    }

    private function render(string $title, string $active, string $body): void
    {
        $user = $this->requireUser();
        $nav = [
            'dashboard' => ['/dashboard', 'D', 'Dashboard'],
            'equipment' => ['/equipment', 'E', 'Equipment'],
            'customers' => ['/customers', 'C', 'Customers'],
            'maintenance' => ['/maintenance', 'M', 'Maintenance'],
            'alerts' => ['/alerts', 'A', 'Alerts'],
            'serviceReports' => ['/service-reports', 'R', 'Service Reports'],
            'serviceRequests' => ['/service-requests', 'Q', 'Service Requests'],
            'documents' => ['/documents', 'F', 'Documents'],
            'exports' => ['/reports', 'X', 'Reports'],
            'activity' => ['/activity', 'L', 'Activity'],
            'settings' => ['/settings', 'S', 'Settings'],
        ];
        $navHtml = '';
        foreach ($nav as $key => [$href, $icon, $label]) {
            $class = $key === $active ? 'nav-item active' : 'nav-item';
            $navHtml .= '<a class="' . $class . '" href="' . $href . '"><span>' . $icon . '</span>' . $this->e($label) . '</a>';
        }
        $unread = $this->scalar('SELECT COUNT(*)::int FROM notifications WHERE is_read = false');
        $initials = strtoupper(substr((string) $user['username'], 0, 2));
        $shell = '<div class="app-shell">
            <aside class="sidebar">
                <div class="sidebar-brand"><div class="brand-mark">SS</div><div><strong>Science Spark</strong><span>Lab System</span></div></div>
                <nav aria-label="Main navigation">' . $navHtml . '</nav>
            </aside>
            <main class="workspace">
                <header class="topbar">
                    <div><p class="eyebrow">Workspace</p><h1>' . $this->e($title) . '</h1></div>
                    <div class="user-menu">
                        <a class="notification-top-button" href="/notifications"><span>Notifications</span><strong>' . $this->e($unread) . '</strong></a>
                        <a class="profile-top-button" href="/profile"><span class="account-mini-avatar">' . $this->e($initials) . '</span><span class="top-profile-meta"><strong>' . $this->e($user['username']) . '</strong><small>' . $this->e($user['role']) . '</small></span></a>
                    </div>
                </header>' . $body . '
            </main>
            ' . $this->qrModal() . '
        </div>';
        $this->htmlPage($title, $shell);
    }

    private function qrModal(): string
    {
        return '<div class="qr-backdrop" data-qr-modal hidden>
            <section class="qr-modal" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title">
                <div class="qr-modal-header">
                    <div><p class="eyebrow">Equipment QR</p><h2 id="qr-modal-title">QR Code</h2></div>
                    <button class="icon-action qr-close" type="button" data-qr-close aria-label="Close QR code">Close</button>
                </div>
                <div class="qr-frame"><img data-qr-image alt="Equipment QR code"></div>
                <p class="qr-modal-hint">Scan this code to open the read-only equipment profile.</p>
                <div class="row-actions">
                    <a class="primary-action" data-qr-profile href="/equipment">View Profile</a>
                    <button class="ghost-action" type="button" data-qr-close>Done</button>
                </div>
            </section>
        </div>';
    }

    private function htmlPage(string $title, string $body): void
    {
        Response::raw('<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . $this->e($title) . ' - Science Spark</title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body>' . $body . $this->appScript() . '</body>
</html>', 'text/html; charset=utf-8');
    }

    private function appScript(): string
    {
        return '<script>
(() => {
  const modal = document.querySelector("[data-qr-modal]");
  if (!modal) return;

  const image = modal.querySelector("[data-qr-image]");
  const title = modal.querySelector("#qr-modal-title");
  const profile = modal.querySelector("[data-qr-profile]");
  let lastTrigger = null;

  const closeModal = () => {
    modal.hidden = true;
    image.removeAttribute("src");
    if (lastTrigger) lastTrigger.focus();
  };

  document.addEventListener("click", (event) => {
    const trigger = event.target.closest(".qr-action");
    if (trigger) {
      lastTrigger = trigger;
      title.textContent = trigger.dataset.equipmentName || "QR Code";
      image.src = trigger.dataset.qrSrc || "";
      profile.href = trigger.dataset.profileUrl || "/equipment";
      modal.hidden = false;
      return;
    }

    if (event.target.matches("[data-qr-close]") || event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !modal.hidden) {
      closeModal();
    }
  });
})();
</script>';
    }

    private function currentUser(): ?array
    {
        return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
    }

    private function requireUser(): array
    {
        $user = $this->currentUser();
        if ($user === null) {
            $this->redirect('/login');
            exit;
        }
        return $user;
    }

    private function redirect(string $path): void
    {
        if (!headers_sent()) {
            http_response_code(302);
            header('Location: ' . $path);
        }
    }

    private function flash(string $message): void
    {
        $_SESSION['flash'] = $message;
    }

    private function flashHtml(): string
    {
        if (!isset($_SESSION['flash'])) {
            return '';
        }
        $message = (string) $_SESSION['flash'];
        unset($_SESSION['flash']);
        return '<p class="toast-inline">' . $this->e($message) . '</p>';
    }

    private function table(array $headers, array $rows, bool $htmlCells = false): string
    {
        if ($rows === []) {
            return '<div class="empty-state"><strong>No records found</strong><span>Nothing to show yet.</span></div>';
        }
        $html = '<div class="table-shell"><table><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . $this->e($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $cellString = (string) $cell;
                $isTrustedHtmlCell = str_starts_with($cellString, '<a class="icon-action"')
                    || str_starts_with($cellString, '<a class="ghost-action"')
                    || str_starts_with($cellString, '<button class="icon-action')
                    || str_starts_with($cellString, '<form method="post"')
                    || str_starts_with($cellString, '<span class="status-chip')
                    || str_starts_with($cellString, '<div class="row-actions"');
                $html .= '<td>' . ($htmlCells && $isTrustedHtmlCell ? $cellString : $this->e($cellString)) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function statusBars(array $rows): string
    {
        if ($rows === []) {
            return '<div class="empty-state"><strong>No status data</strong><span>Equipment statuses will appear here.</span></div>';
        }

        $total = array_reduce($rows, static fn (int $sum, array $row): int => $sum + (int) $row['count'], 0);
        $html = '<div class="status-bars">';
        foreach ($rows as $row) {
            $count = (int) $row['count'];
            $width = $total > 0 ? max(4, (int) round(($count / $total) * 100)) : 0;
            $html .= '<div class="status-bar-row"><div><span>' . $this->e($this->display($row['status'])) . '</span><strong>' . $this->e($count) . '</strong></div><div class="bar-track"><span style="width: ' . $width . '%"></span></div></div>';
        }
        return $html . '</div>';
    }

    private function maintenanceAttention(array $rows): string
    {
        if ($rows === []) {
            return '<div class="empty-state"><strong>No active alerts</strong><span>Due and overdue maintenance will appear here.</span></div>';
        }

        $html = '<div class="alert-list">';
        foreach ($rows as $row) {
            $dueDate = $this->display($row['next_due_date'] ?? null);
            $status = is_string($row['next_due_date'] ?? null) && $row['next_due_date'] < date('Y-m-d') ? 'overdue' : 'due soon';
            $html .= '<div class="alert-item"><div><strong>' . $this->e($this->display($row['instrument_name'] ?? null)) . '</strong><span>' . $this->e($this->display($row['type'] ?? null)) . ' maintenance due ' . $this->e($dueDate) . '</span></div>' . $this->statusChip($status) . '</div>';
        }
        return $html . '</div>';
    }

    private function simpleList(array $rows, callable $label): string
    {
        if ($rows === []) {
            return '<div class="empty-state"><strong>No records found</strong><span>Nothing to show yet.</span></div>';
        }
        $html = '<div class="alert-list">';
        foreach ($rows as $row) {
            $html .= '<div class="alert-item"><strong>' . $this->e($label($row)) . '</strong></div>';
        }
        return $html . '</div>';
    }

    private function input(string $label, string $name, bool $required = false, string $type = 'text'): string
    {
        return '<label class="field compact-field"><span>' . $this->e($label) . '</span><input name="' . $this->e($name) . '" type="' . $this->e($type) . '"' . ($required ? ' required' : '') . '></label>';
    }

    private function select(string $label, string $name, array $options): string
    {
        $html = '<label class="field compact-field"><span>' . $this->e($label) . '</span><select name="' . $this->e($name) . '">';
        foreach ($options as $value => $text) {
            $html .= '<option value="' . $this->e($value) . '">' . $this->e($text) . '</option>';
        }
        return $html . '</select></label>';
    }

    private function selectFromRows(string $label, string $name, array $rows, string $emptyLabel, bool $required = false): string
    {
        $html = '<label class="field compact-field"><span>' . $this->e($label) . '</span><select name="' . $this->e($name) . '"' . ($required ? ' required' : '') . '><option value="">' . $this->e($emptyLabel) . '</option>';
        foreach ($rows as $row) {
            $html .= '<option value="' . (int) $row['id'] . '">' . $this->e($row['name']) . '</option>';
        }
        return $html . '</select></label>';
    }

    private function deleteForm(string $action): string
    {
        return '<form method="post" action="' . $this->e($action) . '"><button class="danger-action" type="submit">Delete</button></form>';
    }

    private function detail(string $label, mixed $value): string
    {
        return '<div><span>' . $this->e($label) . '</span><strong>' . $this->e($this->display($value)) . '</strong></div>';
    }

    private function detailRow(string $label, mixed $value): string
    {
        return '<p><span>' . $this->e($label) . '</span><strong>' . $this->e($this->display($value)) . '</strong></p>';
    }

    private function statusChip(mixed $value): string
    {
        $label = $this->display($value);
        $class = strtolower(str_replace([' ', '_'], '-', $label));

        return '<span class="status-chip status-' . $this->e($class) . '">' . $this->e($label) . '</span>';
    }

    private function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    private function row(string $sql, array $parameters = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function rowOrFail(string $sql, array $parameters = []): array
    {
        $row = $this->row($sql, $parameters);
        if ($row === null) {
            http_response_code(404);
            $this->htmlPage('Not found', '<main class="public-scan-page"><section class="public-scan-shell"><div class="public-scan-card"><h1>Not found</h1><p>This record could not be found.</p></div></section></main>');
            exit;
        }
        return $row;
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function count(string $table): int
    {
        return $this->scalar('SELECT COUNT(*)::int FROM ' . $table);
    }

    private function insert(string $table, array $data): int
    {
        $data = array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '');
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ') RETURNING id'
        );
        $statement->execute($data);
        return (int) $statement->fetchColumn();
    }

    private function execute(string $sql, array $parameters = []): void
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
    }

    private function deleteById(string $table, int $id): void
    {
        try {
            $this->execute('DELETE FROM ' . $table . ' WHERE id = :id', ['id' => $id]);
        } catch (Throwable) {
            $this->flash('Could not delete this record because it may be linked to other data.');
        }
    }

    private function csv(string $filename, array $headers, string $sql): void
    {
        $this->requireUser();
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);
        foreach ($this->rows($sql) as $row) {
            fputcsv($handle, array_map(static fn (string $header): mixed => $row[$header] ?? '', $headers));
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);
        Response::raw($csv, 'text/csv; charset=utf-8', 200, $filename);
    }

    private function routeId(Request $request): int
    {
        return max(1, (int) $request->route('id'));
    }

    private function nullable(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function display(mixed $value): string
    {
        return $value === null || $value === '' ? 'Not set' : (string) $value;
    }

    private function scanUrl(int $id): string
    {
        return rtrim(Config::string('APP_URL', ''), '/') . '/scan/equipment/' . $id;
    }

    private function dataUriContent(string $dataUri): string
    {
        $parts = explode(',', $dataUri, 2);
        return count($parts) === 2 ? (base64_decode($parts[1], true) ?: $dataUri) : $dataUri;
    }

    private function missingQrSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240" viewBox="0 0 240 240"><rect width="240" height="240" fill="#ffffff"/><text x="120" y="116" font-family="Arial, sans-serif" font-size="18" font-weight="700" text-anchor="middle" fill="#111111">QR not found</text><text x="120" y="142" font-family="Arial, sans-serif" font-size="12" text-anchor="middle" fill="#555555">Equipment record missing</text></svg>';
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars($this->display($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
