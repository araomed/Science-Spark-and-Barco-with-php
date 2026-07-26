<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Http\Request;
use App\Http\Response;

final class DashboardController
{
    public function show(Request $request): void
    {
        $pdo = Database::connection();

        $summary = [
            'equipment_total' => $this->count($pdo, 'instruments'),
            'equipment_active' => $this->scalar(
                $pdo,
                "SELECT COUNT(*)::int FROM instruments WHERE status = 'active'"
            ),
            'equipment_in_maintenance' => $this->scalar(
                $pdo,
                "SELECT COUNT(*)::int FROM instruments WHERE status = 'maintenance'"
            ),
            'customers_total' => $this->count($pdo, 'customers'),
            'documents_total' => $this->count($pdo, 'documents'),
            'service_requests_open' => $this->scalar(
                $pdo,
                "SELECT COUNT(*)::int FROM service_requests WHERE status = 'open'"
            ),
            'maintenance_overdue' => $this->scalar(
                $pdo,
                'SELECT COUNT(*)::int
                 FROM maintenance_records
                 WHERE next_due_date IS NOT NULL AND next_due_date < CURRENT_DATE'
            ),
            'maintenance_upcoming_30_days' => $this->scalar(
                $pdo,
                'SELECT COUNT(*)::int
                 FROM maintenance_records
                 WHERE next_due_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL \'30 days\''
            ),
        ];

        $recentReports = $pdo->query(
            'SELECT sr.id, sr.instrument_id, i.name AS instrument_name,
                    sr.date, sr.technician, sr.summary
             FROM service_reports sr
             LEFT JOIN instruments i ON i.id = sr.instrument_id
             ORDER BY sr.date DESC NULLS LAST, sr.id DESC
             LIMIT 5'
        )->fetchAll();

        $recentActivity = $pdo->query(
            'SELECT id, user_id, username, action, entity_type,
                    entity_id, details, timestamp
             FROM activity_logs
             ORDER BY timestamp DESC NULLS LAST, id DESC
             LIMIT 10'
        )->fetchAll();

        Response::success([
            'summary' => $summary,
            'recent_service_reports' => $recentReports,
            'recent_activity' => $recentActivity,
        ]);
    }

    private function count(\PDO $pdo, string $table): int
    {
        return $this->scalar($pdo, 'SELECT COUNT(*)::int FROM ' . $table);
    }

    private function scalar(\PDO $pdo, string $sql): int
    {
        return (int) $pdo->query($sql)->fetchColumn();
    }
}
