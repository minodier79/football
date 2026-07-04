<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\ErrorHandler;
use App\Http\AuthMiddleware;
use App\Http\Router;

header('Content-Type: application/json');

$container = require __DIR__ . '/../bootstrap.php';

// ⚙️ mode debug (via .env)

$debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

// 🔥 enregistre le handler global
(new ErrorHandler($debug))->register();

$auth = new AuthMiddleware($_ENV['API_TOKEN']);

// Router
$router = new Router($container);

// Dispatch de la requête HTTP
$router->dispatch(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI']
);

