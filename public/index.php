<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Auth;
use App\Config;
use App\Controllers\AuthController;
use App\Controllers\BooksController;
use App\Controllers\ChaptersController;
use App\Controllers\RunsController;
use App\Response;
use App\Router;

Config::init(dirname(__DIR__));
Auth::start();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Static/PWA files are served directly by the web server in production; the
// built-in PHP dev server routes everything here, so hand those back too.
if ($path !== '/' && !str_starts_with($path, '/api/')) {
    $staticFile = __DIR__ . $path;
    if (is_file($staticFile)) {
        return false;
    }
}

if (!str_starts_with($path, '/api/')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/app.html');
    return;
}

$router = new Router();

$router->post('/api/login', fn () => AuthController::login());
$router->post('/api/logout', fn () => AuthController::logout());
$router->get('/api/status', fn () => AuthController::status());

$guarded = function (callable $handler): callable {
    return function (array $args = []) use ($handler) {
        Auth::requireLogin();
        $handler($args);
    };
};

$router->get('/api/agents', $guarded(fn () => Response::json(['agents' => array_values(array_map(
    fn ($a) => ['name' => $a->name, 'description' => $a->description, 'permissionMode' => $a->permissionMode],
    \App\Services\AgentTemplate::listAvailable()
))])));

$router->get('/api/books', $guarded(fn () => BooksController::index()));
$router->post('/api/books', $guarded(fn () => BooksController::create()));
$router->get('/api/books/{id}', $guarded(fn ($a) => BooksController::show($a)));
$router->delete('/api/books/{id}', $guarded(fn ($a) => BooksController::delete($a)));
$router->put('/api/books/{id}/context', $guarded(fn ($a) => BooksController::updateContext($a)));

$router->post('/api/books/{bookId}/chapters', $guarded(fn ($a) => ChaptersController::create($a)));
$router->post('/api/books/{bookId}/chapters/reorder', $guarded(fn ($a) => ChaptersController::reorder($a)));
$router->get('/api/books/{bookId}/chapters/{chapterId}', $guarded(fn ($a) => ChaptersController::show($a)));
$router->put('/api/books/{bookId}/chapters/{chapterId}', $guarded(fn ($a) => ChaptersController::update($a)));
$router->delete('/api/books/{bookId}/chapters/{chapterId}', $guarded(fn ($a) => ChaptersController::delete($a)));
$router->get('/api/books/{bookId}/chapters/{chapterId}/snapshots', $guarded(fn ($a) => ChaptersController::snapshots($a)));
$router->post('/api/books/{bookId}/chapters/{chapterId}/snapshots/{snapshotId}/restore', $guarded(fn ($a) => ChaptersController::restoreSnapshot($a)));

$router->post('/api/runs', $guarded(fn ($a) => RunsController::create($a)));
$router->get('/api/runs/{id}', $guarded(fn ($a) => RunsController::show($a)));
$router->get('/api/runs/{id}/events', $guarded(fn ($a) => RunsController::events($a)));
$router->get('/api/runs/{id}/diffs', $guarded(fn ($a) => RunsController::diffs($a)));
$router->post('/api/runs/{id}/diffs/{diffId}/decision', $guarded(fn ($a) => RunsController::decideDiff($a)));
$router->post('/api/runs/{id}/finalize', $guarded(fn ($a) => RunsController::finalize($a)));
$router->delete('/api/runs/{id}', $guarded(fn ($a) => RunsController::cancel($a)));

$router->dispatch($method, rtrim($path, '/') === '' ? '/' : $path);
