<?php

declare(strict_types=1);

use App\Config\Config;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Support\Logger;
use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);

$dotenv = Dotenv::createImmutable($projectRoot);
$dotenv->safeLoad();

$requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
$_SERVER['HTTP_X_REQUEST_ID'] = $requestId;

header('X-Request-Id: ' . $requestId);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$allowedOrigins = Config::csv(
    'CORS_ALLOWED_ORIGINS',
    array_filter([Config::string('FRONTEND_URL', '')])
);

if (is_string($origin) && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    Response::noContent();
    return;
}

$request = null;

try {
    $request = Request::fromGlobals();
    $router = require $projectRoot . '/routes/api.php';

    if (!$router instanceof Router) {
        throw new RuntimeException('Route file did not return a router.');
    }

    $router->dispatch($request);
} catch (HttpException $exception) {
    Response::error(
        $exception->getMessage(),
        $exception->statusCode(),
        $exception->errors()
    );
} catch (PDOException $exception) {
    Logger::exception($exception, $request);

    $safeDatabaseErrors = [
        '23503' => ['Referenced record does not exist', 409],
        '23505' => ['A record with this unique value already exists', 409],
    ];

    [$message, $status] = $safeDatabaseErrors[$exception->getCode()]
        ?? ['Database operation failed', 500];

    $errors = Config::bool('APP_DEBUG', false)
        ? ['detail' => $exception->getMessage()]
        : [];

    Response::error($message, $status, $errors);
} catch (Throwable $exception) {
    Logger::exception($exception, $request);

    $errors = Config::bool('APP_DEBUG', false)
        ? [
            'exception' => $exception::class,
            'detail' => $exception->getMessage(),
        ]
        : [];

    Response::error('Internal server error', 500, $errors);
}
