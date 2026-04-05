<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Application;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Router;

$config = [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'mysql',
        'name' => getenv('DB_NAME') ?: 'widget_app',
        'user' => getenv('DB_USER') ?: 'widget',
        'pass' => getenv('DB_PASSWORD') ?: 'widget_secret',
    ],
    'jwt_secret' => getenv('JWT_SECRET') ?: 'dev-secret-change-me',
    'app_url' => rtrim(getenv('APP_URL') ?: 'http://localhost:8080', '/'),
];

$db = Database::fromConfig($config['db']);
$router = new Router();
$app = new Application($router, $db, $config);

$app->registerRoutes();

$request = Request::fromGlobals();
try {
    $response = $router->dispatch($request);
} catch (Throwable $e) {
    $response = Response::json(['error' => 'internal_error', 'message' => $e->getMessage()], 500);
}

$response->send();
