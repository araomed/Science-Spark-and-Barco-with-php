<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\TableRepository;
use App\Validation\Validator;

class ResourceController
{
    /**
     * @param array<string, string> $createRules
     * @param array<string, string> $updateRules
     */
    public function __construct(
        protected readonly TableRepository $repository,
        protected readonly array $createRules = [],
        protected readonly array $updateRules = []
    ) {
    }

    public function index(Request $request): void
    {
        $result = $this->repository->paginate($request->query());

        Response::success($result['data'], null, 200, $result['meta']);
    }

    public function show(Request $request): void
    {
        $record = $this->repository->find($this->routeId($request));

        if ($record === null) {
            throw new HttpException('Record not found', 404);
        }

        Response::success($record);
    }

    public function store(Request $request): void
    {
        $data = $this->createRules === []
            ? $request->body()
            : Validator::validate($request->body(), $this->createRules);

        $record = $this->repository->create($data);

        Response::success($record, 'Record created', 201);
    }

    public function update(Request $request): void
    {
        $data = $this->updateRules === []
            ? $request->body()
            : Validator::validate($request->body(), $this->updateRules);

        $record = $this->repository->update($this->routeId($request), $data);

        if ($record === null) {
            throw new HttpException('Record not found', 404);
        }

        Response::success($record, 'Record updated');
    }

    public function destroy(Request $request): void
    {
        if (!$this->repository->delete($this->routeId($request))) {
            throw new HttpException('Record not found', 404);
        }

        Response::success(null, 'Record deleted');
    }

    protected function routeId(Request $request): int
    {
        $id = filter_var($request->route('id'), FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            throw new HttpException('Invalid record ID', 422);
        }

        return $id;
    }
}
