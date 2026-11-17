<?php
declare(strict_types=1);

namespace app\Core;

class Router
{
    private array $routes = ['GET' => [], 'POST' => []];
    private $fallback = null;

    public function get(string $path, string $action): void { $this->add('GET', $path, $action); }
    public function post(string $path, string $action): void { $this->add('POST', $path, $action); }
    public function fallback(string $action): void { $this->fallback = $action; }

    private function add(string $method, string $path, string $action): void
    {
        // {slug} 或 {slug:\w+} → 命名捕获组
        $regex = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', function ($m) {
            $name = $m[1];
            $pattern = $m[2] ?? '[^/]+';
            return '(?P<' . $name . '>' . $pattern . ')';
        }, rtrim($path, '/'));
        $regex = $regex === '' ? '/' : $regex;
        $this->routes[$method][$regex] = $action;
    }

    public function dispatch(Request $request): void
    {
        $uri = rtrim($request->uri(), '/');
        if ($uri === '') $uri = '/';
        $method = $request->method();

        foreach ($this->routes[$method] ?? [] as $regex => $action) {
            if (preg_match('#^' . $regex . '$#u', $uri, $m)) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                $this->invoke($action, $params, $request);
                return;
            }
        }
        if ($this->fallback) { $this->invoke($this->fallback, [], $request); return; }
        http_response_code(404);
        echo '404';
    }

    private function invoke(string $action, array $params, Request $request): void
    {
        [$ctl, $method] = explode('@', $action);
        $class = 'app\\Controllers\\' . $ctl;
        if (!class_exists($class)) {
            Logger::error('ROUTE', 'controller missing: ' . $class);
            http_response_code(500); echo '500 Controller Missing'; exit;
        }
        $obj = new $class();
        if (!method_exists($obj, $method)) {
            http_response_code(404); echo '404'; exit;
        }
        try {
            $obj->$method($request, $params);
        } catch (\Throwable $e) {
            Logger::error('ROUTE', $e->getMessage(), ['action' => $action, 'file' => $e->getFile(), 'line' => $e->getLine()]);
            if (str_starts_with($action, 'Admin')) {
                if (Session::get('admin_user')) {
                    Session::flash('error', '操作失败：' . $e->getMessage());
                    ResponseRedirect('/admin');
                } else {
                    echo 'Error';
                }
            } else {
                throw $e;
            }
        }
    }
}

function ResponseRedirect(string $to): void
{
    header('Location: ' . $to, true, 302);
    exit;
}
