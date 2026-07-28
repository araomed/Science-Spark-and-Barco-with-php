<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/app.php';

$tests = [];

$test = static function (string $name, callable $callback) use (&$tests): void {
    $tests[$name] = $callback;
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$test('html escaping protects output', static function () use ($assert): void {
    $assert(h('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'HTML should be escaped.');
});

$test('empty values display consistently', static function () use ($assert): void {
    $assert(display(null) === 'Not set', 'Null values should use the shared placeholder.');
    $assert(display('') === 'Not set', 'Empty strings should use the shared placeholder.');
    $assert(display('active') === 'active', 'Real values should display unchanged.');
});

$test('database identifiers are restricted', static function () use ($assert): void {
    $assert(safe_identifier('maintenance_records') === 'maintenance_records', 'Valid identifier should pass.');

    try {
        safe_identifier('users; DROP TABLE users');
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('Unsafe identifier was accepted.');
});

$test('scan url uses configured app url', static function () use ($assert): void {
    $_ENV['APP_URL'] = 'http://example.test/';
    putenv('APP_URL=http://example.test/');

    $assert(scan_url(7) === 'http://example.test/scan/equipment/7', 'Scan URL should point to public equipment profile.');
});

$test('csrf field contains current token', static function () use ($assert): void {
    $token = csrf_token();
    $assert(str_contains(csrf_field(), $token), 'CSRF field should include the session token.');
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
