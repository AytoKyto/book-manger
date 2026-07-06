<?php

declare(strict_types=1);

namespace App;

/** Tiny regex-based router. No framework needed for this route count. */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, params:array<int,string>, handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('#\{(\w+)\}#', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $matchedPath = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $matchedPath = true;
            if ($route['method'] !== $method) {
                continue;
            }

            array_shift($matches);
            $args = array_combine($route['params'], $matches);

            try {
                ($route['handler'])($args);
            } catch (\Throwable $e) {
                Response::json(['error' => $e->getMessage()], 500);
            }
            return;
        }

        Response::json(['error' => $matchedPath ? 'method_not_allowed' : 'not_found'], $matchedPath ? 405 : 404);
    }
}
