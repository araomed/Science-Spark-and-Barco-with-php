<?php

declare(strict_types=1);

use App\Auth\JwtService;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Validation\Validator;

require dirname(__DIR__) . '/vendor/autoload.php';

$tests = [];

$test = static function (string $name, callable $callback) use (&$tests): void {
    $tests[$name] = $callback;
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$test('request rejects malformed json', static function () use ($assert): void {
    try {
        Request::parseJsonBody('{"missing":');
    } catch (HttpException $exception) {
        $assert($exception->statusCode() === 400, 'Malformed JSON should be a 400.');
        return;
    }

    throw new RuntimeException('Malformed JSON did not throw.');
});

$test('validator returns field errors', static function () use ($assert): void {
    try {
        Validator::validate(['email' => 'not-an-email'], [
            'email' => 'required|email',
        ]);
    } catch (ValidationException $exception) {
        $assert(isset($exception->errors()['email']), 'Email error was expected.');
        return;
    }

    throw new RuntimeException('Invalid email did not throw.');
});

$test('router matches route parameters', static function () use ($assert): void {
    $router = new Router();
    $router->get('/api/items/{id}', static function (Request $request): void {
        Response::success(['id' => $request->route('id')]);
    });

    ob_start();
    $router->dispatch(Request::create('GET', '/api/items/42'));
    $payload = json_decode((string) ob_get_clean(), true);

    $assert($payload['success'] === true, 'Route should succeed.');
    $assert($payload['data']['id'] === '42', 'Route parameter should be captured.');
});

$test('router distinguishes 405 from 404', static function () use ($assert): void {
    $router = new Router();
    $router->get('/api/items', static function (): void {
        Response::success();
    });

    ob_start();
    $router->dispatch(Request::create('POST', '/api/items'));
    $payload = json_decode((string) ob_get_clean(), true);

    $assert($payload['success'] === false, '405 should be an error response.');
    $assert($payload['message'] === 'Method not allowed', 'Expected 405 message.');
});

$test('jwt service creates and decodes tokens', static function () use ($assert): void {
    $_ENV['JWT_SECRET'] = 'test-secret-for-local-automated-tests-only-32chars';
    $_ENV['APP_URL'] = 'http://127.0.0.1:8080';
    $_ENV['JWT_EXPIRATION_MINUTES'] = '5';

    $service = new JwtService();
    $token = $service->createToken([
        'id' => 7,
        'username' => 'tester',
        'email' => 'tester@example.com',
        'role' => 'admin',
    ]);
    $payload = $service->decode($token['access_token']);

    $assert((string) $payload['sub'] === '7', 'JWT subject should be the user ID.');
});

$passed = 0;

foreach ($tests as $name => $callback) {
    try {
        $callback();
        echo '[PASS] ' . $name . PHP_EOL;
        $passed++;
    } catch (Throwable $throwable) {
        echo '[FAIL] ' . $name . ': ' . $throwable->getMessage() . PHP_EOL;
        exit(1);
    }
}

echo $passed . ' tests passed.' . PHP_EOL;
