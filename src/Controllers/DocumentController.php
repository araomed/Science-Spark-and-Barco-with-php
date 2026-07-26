<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\TableRepository;
use App\Validation\Validator;
use finfo;

final class DocumentController extends ResourceController
{
    public function __construct(TableRepository $repository)
    {
        parent::__construct(
            $repository,
            [
                'title' => 'required|string|min:1|max:255',
                'category' => 'required|string|min:1|max:120',
                'file_path' => 'optional|string|max:500',
                'instrument_id' => 'optional|nullable|integer',
                'uploaded_by' => 'optional|nullable|string|max:255',
                'upload_date' => 'optional|nullable|date',
                'description' => 'optional|nullable|string|max:5000',
            ],
            [
                'title' => 'optional|string|min:1|max:255',
                'category' => 'optional|string|min:1|max:120',
                'instrument_id' => 'optional|nullable|integer',
                'uploaded_by' => 'optional|nullable|string|max:255',
                'upload_date' => 'optional|nullable|date',
                'description' => 'optional|nullable|string|max:5000',
            ]
        );
    }

    public function store(Request $request): void
    {
        $file = $request->files()['file'] ?? null;
        $body = $request->body();

        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $body['file_path'] = $this->storeUploadedFile($file);
        }

        $data = Validator::validate($body, [
            'title' => 'required|string|min:1|max:255',
            'category' => 'required|string|min:1|max:120',
            'file_path' => 'required|string|max:500',
            'instrument_id' => 'optional|nullable|integer',
            'uploaded_by' => 'optional|nullable|string|max:255',
            'upload_date' => 'optional|nullable|date',
            'description' => 'optional|nullable|string|max:5000',
        ]);

        if (!isset($data['upload_date'])) {
            $data['upload_date'] = date('Y-m-d');
        }

        if (!isset($data['uploaded_by'])) {
            $user = (array) $request->attribute('user', []);
            $data['uploaded_by'] = $user['username'] ?? null;
        }

        $record = $this->repository->create($data);

        Response::success($record, 'Document created', 201);
    }

    public function download(Request $request): void
    {
        $document = $this->repository->find($this->routeId($request));

        if ($document === null) {
            throw new HttpException('Record not found', 404);
        }

        $uploadRoot = realpath(Config::path('UPLOAD_PATH', 'storage/uploads'));
        $path = (string) $document['file_path'];

        if ($uploadRoot === false || $path === '') {
            throw new HttpException('Document file is not available', 404);
        }

        $absolutePath = realpath($uploadRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));

        if (
            $absolutePath === false
            || !str_starts_with($absolutePath, $uploadRoot)
            || !is_file($absolutePath)
        ) {
            throw new HttpException('Document file is not available', 404);
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath) ?: 'application/octet-stream';
        $downloadName = basename($path);

        Response::download($absolutePath, $downloadName, $mimeType);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function storeUploadedFile(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new HttpException('Upload failed', 422, ['file' => 'Upload error code ' . ($file['error'] ?? 'unknown')]);
        }

        $size = (int) ($file['size'] ?? 0);
        $maximumSize = Config::int('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);

        if ($size <= 0 || $size > $maximumSize) {
            throw new HttpException('Upload file size is not allowed', 422, [
                'file' => 'Maximum size is ' . $maximumSize . ' bytes.',
            ]);
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath) ?: '';
        $allowedMimeTypes = Config::csv('ALLOWED_UPLOAD_MIME_TYPES', [
            'application/pdf',
            'image/png',
            'image/jpeg',
            'text/plain',
            'text/csv',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);

        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            throw new HttpException('Upload file type is not allowed', 422, [
                'file' => 'Detected MIME type: ' . $mimeType,
            ]);
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $relativeDirectory = 'documents/' . date('Y/m');
        $uploadRoot = Config::path('UPLOAD_PATH', 'storage/uploads');
        $targetDirectory = $uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0775, true);
        }

        $relativePath = $relativeDirectory . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $targetPath = $uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (!move_uploaded_file($temporaryPath, $targetPath)) {
            throw new HttpException('Could not store uploaded file', 500);
        }

        return $relativePath;
    }
}
