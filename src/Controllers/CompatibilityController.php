<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use chillerlan\QRCode\QRCode;
use PDO;

final class CompatibilityController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function dashboardSummary(Request $request): void
    {
        Response::success([
            'total_instruments' => $this->count('instruments'),
            'active_instruments' => $this->scalar("SELECT COUNT(*)::int FROM instruments WHERE status = 'active'"),
            'total_customers' => $this->count('customers'),
            'due_soon_maintenance' => $this->scalar(
                "SELECT COUNT(*)::int FROM maintenance_records
                 WHERE next_due_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'"
            ),
            'overdue_maintenance' => $this->scalar(
                'SELECT COUNT(*)::int FROM maintenance_records
                 WHERE next_due_date IS NOT NULL AND next_due_date < CURRENT_DATE'
            ),
            'service_reports_this_month' => $this->scalar(
                "SELECT COUNT(*)::int FROM service_reports
                 WHERE date_trunc('month', date::timestamp) = date_trunc('month', CURRENT_DATE::timestamp)"
            ),
        ]);
    }

    public function instrumentsByStatus(Request $request): void
    {
        Response::success(
            $this->pdo->query(
                "SELECT COALESCE(status, 'unknown') AS status, COUNT(*)::int AS count
                 FROM instruments
                 GROUP BY COALESCE(status, 'unknown')
                 ORDER BY status"
            )->fetchAll()
        );
    }

    public function recentServiceReports(Request $request): void
    {
        Response::success(
            $this->pdo->query(
                'SELECT sr.id, sr.instrument_id, i.name AS instrument_name,
                        sr.date, sr.report_file_path, sr.summary, sr.technician
                 FROM service_reports sr
                 LEFT JOIN instruments i ON i.id = sr.instrument_id
                 ORDER BY sr.date DESC NULLS LAST, sr.id DESC
                 LIMIT 10'
            )->fetchAll()
        );
    }

    public function dashboardAlerts(Request $request): void
    {
        Response::success([
            'overdue' => $this->maintenanceAlerts(true),
            'due_soon' => $this->maintenanceAlerts(false),
        ]);
    }

    public function dueSoonMaintenance(Request $request): void
    {
        Response::success($this->maintenanceAlerts(false));
    }

    public function overdueMaintenance(Request $request): void
    {
        Response::success($this->maintenanceAlerts(true));
    }

    public function updateServiceRequestStatus(Request $request): void
    {
        $id = $this->routeId($request);
        $status = (string) ($request->input('status') ?? '');
        $allowed = ['open', 'in_progress', 'resolved', 'closed'];

        if (!in_array($status, $allowed, true)) {
            throw new HttpException('Unsupported service request status', 422, [
                'status' => $allowed,
            ]);
        }

        $resolvedDate = in_array($status, ['resolved', 'closed'], true)
            ? date('Y-m-d')
            : null;

        $statement = $this->pdo->prepare(
            'UPDATE service_requests
             SET status = :status, resolved_date = COALESCE(:resolved_date, resolved_date)
             WHERE id = :id
             RETURNING id, instrument_id, customer_id, description, status,
                       assigned_technician, created_date, resolved_date'
        );
        $statement->execute([
            'id' => $id,
            'status' => $status,
            'resolved_date' => $resolvedDate,
        ]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new HttpException('Record not found', 404);
        }

        Response::success($row, 'Service request status updated');
    }

    public function generateMaintenanceReminders(Request $request): void
    {
        $alerts = [
            ...$this->maintenanceAlerts(true),
            ...$this->maintenanceAlerts(false),
        ];
        $created = [];

        $statement = $this->pdo->prepare(
            'INSERT INTO notifications
                (title, message, category, severity, is_read, maintenance_record_id, created_at)
             VALUES
                (:title, :message, :category, :severity, false, :maintenance_record_id, CURRENT_TIMESTAMP)
             RETURNING id, title, message, category, severity, is_read, maintenance_record_id, created_at'
        );

        foreach ($alerts as $alert) {
            $severity = $alert['alert_status'] === 'overdue' ? 'critical' : 'warning';
            $statement->execute([
                'title' => $alert['alert_status'] === 'overdue'
                    ? 'Overdue maintenance'
                    : 'Maintenance due soon',
                'message' => $alert['instrument_name'] . ' maintenance is due on ' . $alert['next_due_date'],
                'category' => 'maintenance',
                'severity' => $severity,
                'maintenance_record_id' => $alert['id'],
            ]);
            $created[] = $statement->fetch();
        }

        Response::success($created, 'Maintenance reminders generated');
    }

    public function markNotificationRead(Request $request): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE notifications
             SET is_read = true
             WHERE id = :id
             RETURNING id, title, message, category, severity, is_read, maintenance_record_id, created_at'
        );
        $statement->execute(['id' => $this->routeId($request)]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new HttpException('Record not found', 404);
        }

        Response::success($row, 'Notification marked as read');
    }

    public function markAllNotificationsRead(Request $request): void
    {
        $this->pdo->exec('UPDATE notifications SET is_read = true WHERE is_read = false');
        Response::success(null, 'All notifications marked as read');
    }

    public function browseDocuments(Request $request): void
    {
        Response::success($this->documents($request));
    }

    public function searchDocuments(Request $request): void
    {
        Response::success($this->documents($request));
    }

    public function instrumentProfile(Request $request): void
    {
        $id = $this->routeId($request);
        $instrument = $this->findInstrument($id);

        Response::success([
            'instrument' => $instrument,
            'maintenance_records' => $this->linkedRows(
                'SELECT id, instrument_id, date, type, description, technician, next_due_date
                 FROM maintenance_records
                 WHERE instrument_id = :id
                 ORDER BY date DESC NULLS LAST, id DESC',
                $id
            ),
            'service_reports' => $this->linkedRows(
                'SELECT id, instrument_id, date, report_file_path, summary, technician
                 FROM service_reports
                 WHERE instrument_id = :id
                 ORDER BY date DESC NULLS LAST, id DESC',
                $id
            ),
            'documents' => $this->linkedRows(
                'SELECT id, title, category, file_path, instrument_id, uploaded_by, upload_date, description
                 FROM documents
                 WHERE instrument_id = :id
                 ORDER BY upload_date DESC NULLS LAST, id DESC',
                $id
            ),
        ]);
    }

    public function qrCode(Request $request): void
    {
        $id = $this->routeId($request);
        $this->findInstrument($id);
        $dataUri = (new QRCode())->render($this->instrumentUrl($id));
        $svg = $this->dataUriContent($dataUri);

        Response::raw($svg, 'image/svg+xml');
    }

    public function serviceReportDownload(Request $request): void
    {
        $id = $this->routeId($request);
        $statement = $this->pdo->prepare(
            'SELECT sr.id, sr.date, sr.summary, sr.technician, i.name AS instrument_name
             FROM service_reports sr
             LEFT JOIN instruments i ON i.id = sr.instrument_id
             WHERE sr.id = :id'
        );
        $statement->execute(['id' => $id]);
        $report = $statement->fetch();

        if (!is_array($report)) {
            throw new HttpException('Record not found', 404);
        }

        Response::raw(
            $this->simplePdf(
                'Service Report #' . $report['id'],
                [
                    'Instrument: ' . ($report['instrument_name'] ?? 'Not set'),
                    'Date: ' . ($report['date'] ?? 'Not set'),
                    'Technician: ' . ($report['technician'] ?? 'Not set'),
                    'Summary: ' . ($report['summary'] ?? 'Not set'),
                ]
            ),
            'application/pdf',
            200,
            'service_report_' . $id . '.pdf'
        );
    }

    public function exportInstruments(Request $request): void
    {
        $this->export(
            'instruments_export.csv',
            ['id', 'name', 'model', 'serial_number', 'manufacturer', 'location', 'status', 'purchase_date', 'customer_id'],
            'SELECT id, name, model, serial_number, manufacturer, location, status, purchase_date, customer_id
             FROM instruments
             ORDER BY id'
        );
    }

    public function exportMaintenance(Request $request): void
    {
        $this->export(
            'maintenance_export.csv',
            ['id', 'instrument_id', 'date', 'type', 'description', 'technician', 'next_due_date'],
            'SELECT id, instrument_id, date, type, description, technician, next_due_date
             FROM maintenance_records
             ORDER BY id'
        );
    }

    public function exportServiceReports(Request $request): void
    {
        $this->export(
            'service_reports_export.csv',
            ['id', 'instrument_id', 'date', 'report_file_path', 'summary', 'technician'],
            'SELECT id, instrument_id, date, report_file_path, summary, technician
             FROM service_reports
             ORDER BY id'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function maintenanceAlerts(bool $overdue): array
    {
        $operator = $overdue
            ? 'mr.next_due_date < CURRENT_DATE'
            : "mr.next_due_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'";

        $statement = $this->pdo->query(
            'SELECT mr.id, mr.instrument_id, i.name AS instrument_name,
                    mr.date, mr.type, mr.description, mr.technician,
                    mr.next_due_date, ' .
                    ($overdue ? "'overdue'" : "'due_soon'") . ' AS alert_status
             FROM maintenance_records mr
             LEFT JOIN instruments i ON i.id = mr.instrument_id
             WHERE mr.next_due_date IS NOT NULL AND ' . $operator . '
             ORDER BY mr.next_due_date ASC, mr.id ASC'
        );

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documents(Request $request): array
    {
        $q = trim((string) ($request->queryValue('q') ?? ''));
        $category = trim((string) ($request->queryValue('category') ?? ''));
        $clauses = [];
        $parameters = [];

        if ($q !== '') {
            $clauses[] = '(title ILIKE :q OR description ILIKE :q OR file_path ILIKE :q)';
            $parameters['q'] = '%' . $q . '%';
        }

        if ($category !== '') {
            $clauses[] = 'category = :category';
            $parameters['category'] = $category;
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
        $statement = $this->pdo->prepare(
            'SELECT id, title, category, file_path, instrument_id, uploaded_by, upload_date, description
             FROM documents' . $where . '
             ORDER BY upload_date DESC NULLS LAST, id DESC'
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    private function findInstrument(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, model, serial_number, manufacturer, location,
                    status, purchase_date, customer_id, qr_code_path
             FROM instruments
             WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $instrument = $statement->fetch();

        if (!is_array($instrument)) {
            throw new HttpException('Record not found', 404);
        }

        return $instrument;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linkedRows(string $sql, int $id): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);

        return $statement->fetchAll();
    }

    private function instrumentUrl(int $id): string
    {
        $frontendUrl = rtrim(Config::string('FRONTEND_URL', 'http://127.0.0.1:5173'), '/');

        return $frontendUrl . '/dashboard?instrument=' . $id;
    }

    private function dataUriContent(string $dataUri): string
    {
        $parts = explode(',', $dataUri, 2);

        if (count($parts) !== 2) {
            return $dataUri;
        }

        return base64_decode($parts[1], true) ?: $dataUri;
    }

    private function count(string $table): int
    {
        return $this->scalar('SELECT COUNT(*)::int FROM ' . $table);
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function routeId(Request $request): int
    {
        $id = filter_var($request->route('id'), FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            throw new HttpException('Invalid record ID', 422);
        }

        return $id;
    }

    /**
     * @param array<int, string> $headers
     */
    private function export(string $filename, array $headers, string $sql): void
    {
        $rows = $this->pdo->query($sql)->fetchAll();
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new HttpException('Could not create export', 500);
        }

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                static fn (string $header): mixed => $row[$header] ?? null,
                $headers
            ));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        Response::raw((string) $csv, 'text/csv; charset=utf-8', 200, $filename);
    }

    /**
     * @param array<int, string> $lines
     */
    private function simplePdf(string $title, array $lines): string
    {
        $text = $title . "\n\n" . implode("\n", $lines);
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 12 Tf 50 760 Td 14 TL (" .
            str_replace("\n", ") Tj T* (", $escaped) .
            ") Tj ET";
        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj',
            '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj',
            '5 0 obj << /Length ' . strlen($stream) . ' >> stream' . "\n" . $stream . "\nendstream endobj",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        }

        $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }
}
