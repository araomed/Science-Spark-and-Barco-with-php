<?php

declare(strict_types=1);

use App\Auth\JwtService;
use App\Controllers\AuthController;
use App\Controllers\CompatibilityController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentController;
use App\Controllers\HealthController;
use App\Controllers\InstrumentController;
use App\Controllers\ResourceController;
use App\Controllers\UserController;
use App\Controllers\WebController;
use App\Database\Database;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Router;
use App\Repositories\TableRepository;
use App\Repositories\UserRepository;

$router = new Router();
$pdo = Database::connection();

$jwt = new JwtService();
$auth = new AuthMiddleware($jwt);
$admin = new RoleMiddleware(['admin']);
$manager = new RoleMiddleware(['admin', 'manager']);
$technical = new RoleMiddleware(['admin', 'manager', 'technician']);

$health = new HealthController();
$router->get('/health', [$health, 'health']);
$router->get('/api', [$health, 'apiIndex']);
$router->get('/api/health', [$health, 'health']);

$userRepository = new UserRepository($pdo);
$authController = new AuthController($userRepository, $jwt);
$compatibility = new CompatibilityController($pdo);
$web = new WebController($pdo, $userRepository);

$router->get('/', [$web, 'dashboard']);
$router->get('/login', [$web, 'loginForm']);
$router->post('/login', [$web, 'login']);
$router->post('/logout', [$web, 'logout']);
$router->get('/dashboard', [$web, 'dashboard']);
$router->get('/equipment', [$web, 'equipment']);
$router->post('/equipment', [$web, 'createEquipment']);
$router->get('/equipment/{id}', [$web, 'equipmentProfile']);
$router->get('/equipment/{id}/qrcode', [$web, 'equipmentQr']);
$router->post('/equipment/{id}/delete', [$web, 'deleteEquipment']);
$router->get('/customers', [$web, 'customers']);
$router->post('/customers', [$web, 'createCustomer']);
$router->post('/customers/{id}/delete', [$web, 'deleteCustomer']);
$router->get('/maintenance', [$web, 'maintenance']);
$router->post('/maintenance', [$web, 'createMaintenance']);
$router->post('/maintenance/{id}/delete', [$web, 'deleteMaintenance']);
$router->get('/alerts', [$web, 'alerts']);
$router->get('/notifications', [$web, 'notifications']);
$router->post('/notifications/generate', [$web, 'generateNotifications']);
$router->post('/notifications/read-all', [$web, 'markAllNotificationsRead']);
$router->post('/notifications/{id}/read', [$web, 'markNotificationRead']);
$router->post('/notifications/{id}/delete', [$web, 'deleteNotification']);
$router->get('/service-reports', [$web, 'serviceReports']);
$router->post('/service-reports', [$web, 'createServiceReport']);
$router->post('/service-reports/{id}/delete', [$web, 'deleteServiceReport']);
$router->get('/service-requests', [$web, 'serviceRequests']);
$router->post('/service-requests', [$web, 'createServiceRequest']);
$router->post('/service-requests/{id}/delete', [$web, 'deleteServiceRequest']);
$router->get('/documents', [$web, 'documents']);
$router->post('/documents', [$web, 'createDocument']);
$router->post('/documents/{id}/delete', [$web, 'deleteDocument']);
$router->get('/reports', [$web, 'reports']);
$router->get('/reports/equipment.csv', [$web, 'exportEquipment']);
$router->get('/reports/maintenance.csv', [$web, 'exportMaintenance']);
$router->get('/reports/service-reports.csv', [$web, 'exportServiceReports']);
$router->get('/activity', [$web, 'activity']);
$router->get('/profile', [$web, 'profile']);
$router->get('/settings', [$web, 'settings']);
$router->get('/scan/equipment/{id}', [$web, 'publicScan']);

$router->get('/api/public/instruments/{id}/profile', [$compatibility, 'publicInstrumentProfile']);
$router->post('/api/auth/login', [$authController, 'login']);
$router->post('/api/login', [$authController, 'login']);
$router->post('/api/auth/logout', [$authController, 'logout'], [$auth]);
$router->get('/api/auth/me', [$authController, 'me'], [$auth]);
$router->get('/api/users/me', [$authController, 'me'], [$auth]);

$router->get('/api/dashboard/summary', [$compatibility, 'dashboardSummary'], [$auth]);
$router->get('/api/dashboard/instruments-by-status', [$compatibility, 'instrumentsByStatus'], [$auth]);
$router->get('/api/dashboard/recent-service-reports', [$compatibility, 'recentServiceReports'], [$auth]);
$router->get('/api/dashboard/alerts', [$compatibility, 'dashboardAlerts'], [$auth]);
$router->get('/api/maintenance/alerts/due-soon', [$compatibility, 'dueSoonMaintenance'], [$auth]);
$router->get('/api/maintenance/alerts/overdue', [$compatibility, 'overdueMaintenance'], [$auth]);
$router->get('/api/reports/instruments/export', [$compatibility, 'exportInstruments'], [$auth]);
$router->get('/api/reports/maintenance/export', [$compatibility, 'exportMaintenance'], [$auth]);
$router->get('/api/reports/service-reports/export', [$compatibility, 'exportServiceReports'], [$auth]);

$makeRepository = static function (
    string $table,
    array $columns,
    array $writable,
    array $searchable = [],
    array $filterable = [],
    array $sortable = ['id'],
    string $defaultSort = 'id'
) use ($pdo): TableRepository {
    return new TableRepository(
        $pdo,
        $table,
        $columns,
        $writable,
        $searchable,
        $filterable,
        $sortable,
        $defaultSort
    );
};

$registerResource = static function (
    Router $router,
    string $basePath,
    ResourceController $controller,
    array $readMiddleware,
    array $writeMiddleware,
    bool $allowDelete = true
): void {
    $router->get($basePath, [$controller, 'index'], $readMiddleware);
    $router->get($basePath . '/{id}', [$controller, 'show'], $readMiddleware);
    $router->post($basePath, [$controller, 'store'], $writeMiddleware);
    $router->put($basePath . '/{id}', [$controller, 'update'], $writeMiddleware);
    $router->patch($basePath . '/{id}', [$controller, 'update'], $writeMiddleware);

    if ($allowDelete) {
        $router->delete($basePath . '/{id}', [$controller, 'destroy'], $writeMiddleware);
    }
};

$categories = new ResourceController(
    $makeRepository(
        'categories',
        ['id', 'name'],
        ['name'],
        ['name'],
        [],
        ['id', 'name'],
        'name'
    ),
    ['name' => 'required|string|min:1|max:255'],
    ['name' => 'optional|string|min:1|max:255']
);
$registerResource($router, '/api/categories', $categories, [$auth], [$auth, $manager]);

$customers = new ResourceController(
    $makeRepository(
        'customers',
        ['id', 'name', 'contact_person', 'email', 'phone', 'address'],
        ['name', 'contact_person', 'email', 'phone', 'address'],
        ['name', 'contact_person', 'email', 'phone', 'address'],
        [],
        ['id', 'name', 'email']
    ),
    [
        'name' => 'required|string|min:1|max:255',
        'contact_person' => 'optional|nullable|string|max:255',
        'email' => 'optional|nullable|email|max:255',
        'phone' => 'optional|nullable|string|max:80',
        'address' => 'optional|nullable|string|max:500',
    ],
    [
        'name' => 'optional|string|min:1|max:255',
        'contact_person' => 'optional|nullable|string|max:255',
        'email' => 'optional|nullable|email|max:255',
        'phone' => 'optional|nullable|string|max:80',
        'address' => 'optional|nullable|string|max:500',
    ]
);
$registerResource($router, '/api/customers', $customers, [$auth], [$auth, $manager]);

$instruments = new InstrumentController(
    $makeRepository(
        'instruments',
        [
            'id',
            'name',
            'model',
            'serial_number',
            'manufacturer',
            'location',
            'status',
            'purchase_date',
            'customer_id',
            'qr_code_path',
        ],
        [
            'name',
            'model',
            'serial_number',
            'manufacturer',
            'location',
            'status',
            'purchase_date',
            'customer_id',
            'qr_code_path',
        ],
        ['name', 'model', 'serial_number', 'manufacturer', 'location', 'status'],
        ['customer_id', 'status', 'manufacturer'],
        ['id', 'name', 'serial_number', 'manufacturer', 'status', 'purchase_date']
    )
);
$registerResource($router, '/api/instruments', $instruments, [$auth], [$auth, $technical]);
$registerResource($router, '/api/equipment', $instruments, [$auth], [$auth, $technical]);
$router->get('/api/instruments/{id}/qr', [$instruments, 'qr'], [$auth]);
$router->get('/api/equipment/{id}/qr', [$instruments, 'qr'], [$auth]);
$router->get('/api/instruments/{id}/qrcode', [$compatibility, 'qrCode'], [$auth]);
$router->get('/api/instruments/{id}/profile', [$compatibility, 'instrumentProfile'], [$auth]);

$maintenance = new ResourceController(
    $makeRepository(
        'maintenance_records',
        ['id', 'instrument_id', 'date', 'type', 'description', 'technician', 'next_due_date'],
        ['instrument_id', 'date', 'type', 'description', 'technician', 'next_due_date'],
        ['type', 'description', 'technician'],
        ['instrument_id', 'type', 'technician'],
        ['id', 'instrument_id', 'date', 'type', 'next_due_date']
    ),
    [
        'instrument_id' => 'optional|nullable|integer',
        'date' => 'optional|nullable|date',
        'type' => 'optional|nullable|string|max:120',
        'description' => 'optional|nullable|string|max:5000',
        'technician' => 'optional|nullable|string|max:255',
        'next_due_date' => 'optional|nullable|date',
    ],
    [
        'instrument_id' => 'optional|nullable|integer',
        'date' => 'optional|nullable|date',
        'type' => 'optional|nullable|string|max:120',
        'description' => 'optional|nullable|string|max:5000',
        'technician' => 'optional|nullable|string|max:255',
        'next_due_date' => 'optional|nullable|date',
    ]
);
$registerResource($router, '/api/maintenance', $maintenance, [$auth], [$auth, $technical]);
$registerResource($router, '/api/maintenance-records', $maintenance, [$auth], [$auth, $technical]);

$serviceReports = new ResourceController(
    $makeRepository(
        'service_reports',
        ['id', 'instrument_id', 'date', 'report_file_path', 'summary', 'technician'],
        ['instrument_id', 'date', 'report_file_path', 'summary', 'technician'],
        ['summary', 'technician', 'report_file_path'],
        ['instrument_id', 'technician'],
        ['id', 'instrument_id', 'date', 'technician']
    ),
    [
        'instrument_id' => 'optional|nullable|integer',
        'date' => 'optional|nullable|date',
        'report_file_path' => 'optional|nullable|string|max:500',
        'summary' => 'optional|nullable|string|max:5000',
        'technician' => 'optional|nullable|string|max:255',
    ],
    [
        'instrument_id' => 'optional|nullable|integer',
        'date' => 'optional|nullable|date',
        'report_file_path' => 'optional|nullable|string|max:500',
        'summary' => 'optional|nullable|string|max:5000',
        'technician' => 'optional|nullable|string|max:255',
    ]
);
$registerResource($router, '/api/service-reports', $serviceReports, [$auth], [$auth, $technical]);
$router->get('/api/service-reports/{id}/download', [$compatibility, 'serviceReportDownload'], [$auth]);

$serviceRequests = new ResourceController(
    $makeRepository(
        'service_requests',
        [
            'id',
            'instrument_id',
            'customer_id',
            'description',
            'status',
            'assigned_technician',
            'created_date',
            'resolved_date',
        ],
        [
            'instrument_id',
            'customer_id',
            'description',
            'status',
            'assigned_technician',
            'created_date',
            'resolved_date',
        ],
        ['description', 'status', 'assigned_technician'],
        ['instrument_id', 'customer_id', 'status', 'assigned_technician'],
        ['id', 'instrument_id', 'customer_id', 'status', 'created_date', 'resolved_date']
    ),
    [
        'instrument_id' => 'optional|nullable|integer',
        'customer_id' => 'optional|nullable|integer',
        'description' => 'optional|nullable|string|max:5000',
        'status' => 'optional|nullable|string|max:120',
        'assigned_technician' => 'optional|nullable|string|max:255',
        'created_date' => 'optional|nullable|date',
        'resolved_date' => 'optional|nullable|date',
    ],
    [
        'instrument_id' => 'optional|nullable|integer',
        'customer_id' => 'optional|nullable|integer',
        'description' => 'optional|nullable|string|max:5000',
        'status' => 'optional|nullable|string|max:120',
        'assigned_technician' => 'optional|nullable|string|max:255',
        'created_date' => 'optional|nullable|date',
        'resolved_date' => 'optional|nullable|date',
    ]
);
$registerResource($router, '/api/service-requests', $serviceRequests, [$auth], [$auth, $technical]);
$router->put('/api/service-requests/{id}/status', [$compatibility, 'updateServiceRequestStatus'], [$auth, $technical]);

$documents = new DocumentController(
    $makeRepository(
        'documents',
        ['id', 'title', 'category', 'file_path', 'instrument_id', 'uploaded_by', 'upload_date', 'description'],
        ['title', 'category', 'file_path', 'instrument_id', 'uploaded_by', 'upload_date', 'description'],
        ['title', 'category', 'file_path', 'uploaded_by', 'description'],
        ['instrument_id', 'category', 'uploaded_by'],
        ['id', 'title', 'category', 'upload_date', 'uploaded_by']
    )
);
$router->get('/api/documents/browse', [$compatibility, 'browseDocuments'], [$auth]);
$router->get('/api/documents/search', [$compatibility, 'searchDocuments'], [$auth]);
$registerResource($router, '/api/documents', $documents, [$auth], [$auth, $technical]);
$router->get('/api/documents/{id}/download', [$documents, 'download'], [$auth]);

$notifications = new ResourceController(
    $makeRepository(
        'notifications',
        ['id', 'title', 'message', 'category', 'severity', 'is_read', 'maintenance_record_id', 'created_at'],
        ['title', 'message', 'category', 'severity', 'is_read', 'maintenance_record_id'],
        ['title', 'message', 'category', 'severity'],
        ['category', 'severity', 'is_read', 'maintenance_record_id'],
        ['id', 'category', 'severity', 'is_read', 'created_at'],
        '-created_at'
    ),
    [
        'title' => 'required|string|min:1|max:255',
        'message' => 'required|string|min:1|max:5000',
        'category' => 'optional|nullable|string|max:120',
        'severity' => 'optional|nullable|string|max:80',
        'is_read' => 'optional|boolean',
        'maintenance_record_id' => 'optional|nullable|integer',
    ],
    [
        'title' => 'optional|string|min:1|max:255',
        'message' => 'optional|string|min:1|max:5000',
        'category' => 'optional|nullable|string|max:120',
        'severity' => 'optional|nullable|string|max:80',
        'is_read' => 'optional|boolean',
        'maintenance_record_id' => 'optional|nullable|integer',
    ]
);
$router->post('/api/notifications/maintenance-reminders', [$compatibility, 'generateMaintenanceReminders'], [$auth, $technical]);
$router->put('/api/notifications/read-all', [$compatibility, 'markAllNotificationsRead'], [$auth, $technical]);
$router->put('/api/notifications/{id}/read', [$compatibility, 'markNotificationRead'], [$auth, $technical]);
$registerResource($router, '/api/notifications', $notifications, [$auth], [$auth, $technical]);

$activityLogs = new ResourceController(
    $makeRepository(
        'activity_logs',
        ['id', 'user_id', 'username', 'action', 'entity_type', 'entity_id', 'details', 'timestamp'],
        [],
        ['username', 'action', 'entity_type', 'details'],
        ['user_id', 'username', 'action', 'entity_type', 'entity_id'],
        ['id', 'user_id', 'username', 'action', 'entity_type', 'entity_id', 'timestamp'],
        '-timestamp'
    )
);
$router->get('/api/activity-logs', [$activityLogs, 'index'], [$auth, $manager]);
$router->get('/api/activity-logs/{id}', [$activityLogs, 'show'], [$auth, $manager]);

$users = new UserController(
    $makeRepository(
        'users',
        ['id', 'username', 'email', 'role'],
        [],
        ['username', 'email', 'role'],
        ['role'],
        ['id', 'username', 'email', 'role']
    ),
    $userRepository
);
$registerResource($router, '/api/users', $users, [$auth, $admin], [$auth, $admin]);

$dashboard = new DashboardController();
$router->get('/api/dashboard', [$dashboard, 'show'], [$auth]);

return $router;
