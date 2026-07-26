<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /**
     * @var array<int, array{
     *     method: string,
     *     path: string,
     *     regex: string,
     *     parameters: array<int, string>,
     *     handler: callable,
     *     middleware: array<int, callable>
     * }>
     */
    private array $routes = [];

    public function get(
        string $path,
        callable $handler,
        array $middleware = []
    ): void {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(
        string $path,
        callable $handler,
        array $middleware = []
    ): void {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(
        string $path,
        callable $handler,
        array $middleware = []
    ): void {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(
        string $path,
        callable $handler,
        array $middleware = []
    ): void {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(
        string $path,
        callable $handler,
        array $middleware = []
    ): void {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    private function add(
        string $method,
        string $path,
        callable $handler,
        array $middleware = []
    ): void {
        $method = strtoupper($method);
        $path = $this->normalizePath($path);

        [$regex, $parameters] = $this->compilePath($path);

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'regex' => $regex,
            'parameters' => $parameters,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): void
    {
        $path = $this->normalizePath($request->path());
        $method = $request->method();
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $allowedMethods[] = $route['method'];

            if ($route['method'] !== $method) {
                continue;
            }

            $parameters = [];

            foreach ($route['parameters'] as $parameter) {
                if (isset($matches[$parameter])) {
                    $parameters[$parameter] = $matches[$parameter];
                }
            }

            $request = $request->withRouteParameters($parameters);
            $handler = $this->buildPipeline(
                $route['handler'],
                $route['middleware']
            );

            $handler($request);

            return;
        }

        if ($allowedMethods !== []) {
            $allowedMethods = array_values(array_unique($allowedMethods));

            if (!headers_sent()) {
                header('Allow: ' . implode(', ', $allowedMethods));
            }

            Response::error(
                'Method not allowed',
                405,
                ['allowed_methods' => $allowedMethods]
            );

            return;
        }

        Response::error('Route not found', 404);
    }

    private function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function compilePath(string $path): array
    {
        $parameters = [];
        $segments = explode('/', trim($path, '/'));
        $compiledSegments = [];

        if ($path === '/') {
            return ['#^/$#', []];
        }

        foreach ($segments as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches) === 1) {
                $parameters[] = $matches[1];
                $compiledSegments[] = '(?P<' . $matches[1] . '>[^/]+)';
                continue;
            }

            $compiledSegments[] = preg_quote($segment, '#');
        }

        return ['#^/' . implode('/', $compiledSegments) . '$#', $parameters];
    }

    private function buildPipeline(
        callable $handler,
        array $middleware
    ): callable {
        $pipeline = static function (Request $request) use ($handler): void {
            $handler($request);
        };

        foreach (array_reverse($middleware) as $layer) {
            $next = $pipeline;
            $pipeline = static function (Request $request) use (
                $layer,
                $next
            ): void {
                $layer($request, $next);
            };
        }

        return $pipeline;
    }
}
