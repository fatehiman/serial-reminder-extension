<?php
declare(strict_types=1);

/**
 * Single front controller. Everything (dashboard + API) comes through here.
 */

// On the server the app lives in ../app (a sibling of public_html); in a local
// checkout public/ sits inside server/, so bootstrap.php is one level up.
$bootstrap = dirname(__DIR__) . '/app/bootstrap.php';
if (!is_file($bootstrap)) {
    $bootstrap = dirname(__DIR__) . '/bootstrap.php';
}
require $bootstrap;

use SR\Auth;
use SR\Api;
use SR\Db;
use SR\Serials;

$path   = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path   = '/' . trim(rawurldecode($path), '/');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// The API is called from the extension, which has no page origin of its own.
if (str_starts_with($path, '/api')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    Api::dispatch($method, $path);
    sr_fail('Unknown API endpoint: ' . $path, 404);
}

/* ------------------------------------------------------------------- pages */

switch (true) {

    case $path === '/health':
        header('Content-Type: text/plain; charset=utf-8');
        try {
            Db::one('SELECT 1 AS ok');
            echo "ok\n";
        } catch (Throwable $e) {
            http_response_code(500);
            echo "db error\n";
        }
        exit;

    case $path === '/':
        header('Location: ' . (Auth::currentUser() ? '/dashboard' : '/login'));
        exit;

    case $path === '/login':
        $error = null;
        if ($method === 'POST') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $user     = Db::one('SELECT * FROM users WHERE username = ?', [$username]);
            if ($user !== null && password_verify($password, $user['password_hash'])) {
                Auth::startSession((int) $user['id']);
                header('Location: /dashboard');
                exit;
            }
            // Same message either way, so the form does not reveal valid usernames.
            $error = 'Wrong username or password.';
            usleep(400_000);
        }
        render('login', ['error' => $error]);
        exit;

    case $path === '/logout':
        Auth::endSession();
        header('Location: /login');
        exit;

    // The extension opens this after trading its API key for a one-time ticket.
    case (bool) preg_match('~^/auth/t/([A-Za-z0-9_-]{10,})$~', $path, $m):
        $user = Auth::redeemTicket($m[1]);
        if ($user === null) {
            http_response_code(410);
            render('message', [
                'title' => 'This link has expired',
                'body'  => 'Open the dashboard again from the extension, or log in with your password.',
            ]);
            exit;
        }
        Auth::startSession((int) $user['id']);
        header('Location: /dashboard');
        exit;

    case $path === '/dashboard':
        $user    = Auth::requireWebUser();
        $status  = (string) ($_GET['status'] ?? 'watching');
        render('dashboard', [
            'user'    => $user,
            'status'  => $status,
            'serials' => Serials::listForUser((int) $user['id'], $status),
        ]);
        exit;

    case $path === '/settings':
        $user   = Auth::requireWebUser();
        $notice = null;
        if ($method === 'POST' && ($_POST['action'] ?? '') === 'rotate_token') {
            Auth::rotateToken((int) $user['id']);
            $user   = Auth::userById((int) $user['id']);
            $notice = 'New API key created. Put it in the extension settings on every computer.';
        }
        if ($method === 'POST' && ($_POST['action'] ?? '') === 'password') {
            $new = (string) ($_POST['password'] ?? '');
            if (strlen($new) < 8) {
                $notice = 'Password must be at least 8 characters.';
            } else {
                Auth::setPassword((int) $user['id'], $new);
                $notice = 'Password changed.';
            }
        }
        render('settings', ['user' => $user, 'notice' => $notice]);
        exit;

    default:
        http_response_code(404);
        render('message', ['title' => 'Not found', 'body' => 'No page at ' . e($path) . '.']);
        exit;
}

function render(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    $viewFile = APP_ROOT . '/views/' . $view . '.php';
    require APP_ROOT . '/views/layout.php';
}
