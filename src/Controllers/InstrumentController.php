<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\TableRepository;
use App\Validation\Validator;

final class InstrumentController extends ResourceController
{
    public function __construct(TableRepository $repository)
    {
        parent::__construct(
            $repository,
            [
                'name' => 'required|string|min:1|max:255',
                'model' => 'optional|nullable|string|max:255',
                'serial_number' => 'optional|nullable|string|max:255',
                'manufacturer' => 'optional|nullable|string|max:255',
                'location' => 'optional|nullable|string|max:255',
                'status' => 'optional|nullable|string|max:80',
                'purchase_date' => 'optional|nullable|date',
                'customer_id' => 'optional|nullable|integer',
            ],
            [
                'name' => 'optional|string|min:1|max:255',
                'model' => 'optional|nullable|string|max:255',
                'serial_number' => 'optional|nullable|string|max:255',
                'manufacturer' => 'optional|nullable|string|max:255',
                'location' => 'optional|nullable|string|max:255',
                'status' => 'optional|nullable|string|max:80',
                'purchase_date' => 'optional|nullable|date',
                'customer_id' => 'optional|nullable|integer',
            ]
        );
    }

    public function store(Request $request): void
    {
        $data = Validator::validate($request->body(), $this->createRules);
        unset($data['qr_code_path']);

        $record = $this->repository->create($data);
        $record = $this->repository->update(
            (int) $record['id'],
            ['qr_code_path' => $this->targetUrl((int) $record['id'])]
        );

        Response::success($record, 'Equipment created with QR code', 201);
    }

    public function qr(Request $request): void
    {
        $instrument = $this->repository->find($this->routeId($request));

        if ($instrument === null) {
            throw new HttpException('Record not found', 404);
        }

        Response::success([
            'instrument_id' => $instrument['id'],
            'qr_code_path' => $instrument['qr_code_path'],
            'target_url' => $this->targetUrl((int) $instrument['id']),
        ]);
    }

    private function targetUrl(int $instrumentId): string
    {
        $frontendUrl = rtrim(Config::string('FRONTEND_URL', ''), '/');

        return $frontendUrl === ''
            ? '/dashboard?instrument=' . $instrumentId
            : $frontendUrl . '/dashboard?instrument=' . $instrumentId;
    }
}
