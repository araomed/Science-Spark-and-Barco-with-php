<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Database\Database;
use App\Http\Request;
use App\Http\Response;

final class HealthController
{
    public function root(Request $request): void
    {
        $frontendUrl = Config::string('FRONTEND_URL', '');

        if ($frontendUrl !== '' && !headers_sent()) {
            http_response_code(302);
            header('Location: ' . $frontendUrl);
            return;
        }

        Response::success([
            'name' => 'Science Spark PHP API',
            'backend' => 'PHP',
        ], 'Science Spark PHP API is running');
    }

    public function health(Request $request): void
    {
        $pdo = Database::connection();
        $databaseInformation = $pdo->query(
            'SELECT current_database() AS database_name,
                    current_user AS database_user,
                    version() AS database_version'
        )->fetch();

        Response::success([
            'backend' => 'PHP',
            'database_connected' => true,
            'database' => [
                'name' => $databaseInformation['database_name'],
                'user' => $databaseInformation['database_user'],
                'version' => $databaseInformation['database_version'],
            ],
        ], 'Science Spark PHP API is running');
    }

    public function apiIndex(Request $request): void
    {
        Response::success([
            'base_url' => '/api',
            'health' => '/api/health',
            'frontend' => Config::string('FRONTEND_URL', 'http://127.0.0.1:5173'),
            'authentication' => [
                'login' => 'POST /api/auth/login',
                'me' => 'GET /api/auth/me',
            ],
            'resources' => [
                'GET /api/customers',
                'GET /api/instruments',
                'GET /api/equipment',
                'GET /api/maintenance',
                'GET /api/service-reports',
                'GET /api/service-requests',
                'GET /api/documents',
                'GET /api/dashboard',
            ],
        ], 'Science Spark PHP API endpoint index');
    }
}
