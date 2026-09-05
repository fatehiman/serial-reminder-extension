<?php
declare(strict_types=1);

/**
 * Command line tool.
 *
 *   php bin/sr.php migrate
 *   php bin/sr.php user:add <username> [password]
 *   php bin/sr.php user:list
 *   php bin/sr.php user:token <username>          rotate the API key
 *   php bin/sr.php user:password <username> <pw>
 *   php bin/sr.php providers
 *   php bin/sr.php check [--serial=<id>] [--force] [--quiet]
 *   php bin/sr.php notify:test ["message"]        send one Telegram message
 *   php bin/sr.php serials [<username>]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require dirname(__DIR__) . '/bootstrap.php';

use SR\Auth;
use SR\Checker;
use SR\Db;
use SR\Notify;
use SR\Providers;
use SR\Serials;

$argvList = $argv;
array_shift($argvList);
$command = array_shift($argvList) ?? 'help';

$flags = [];
$args  = [];
foreach ($argvList as $a) {
    if (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, true);
        $flags[$k] = $v;
    } else {
        $args[] = $a;
    }
}

function out(string $s = ''): void
{
    fwrite(STDOUT, $s . "\n");
}

Db::migrate();

switch ($command) {

    case 'migrate':
        out('Schema is up to date: ' . SR\Config::get('db_path'));
        break;

    case 'user:add':
        $username = $args[0] ?? exitWith('usage: user:add <username> [password]');
        $password = $args[1] ?? bin2hex(random_bytes(6));
        if (Db::one('SELECT id FROM users WHERE username = ?', [$username])) {
            exitWith("User '$username' already exists.");
        }
        $user = Auth::createUser($username, $password);
        out('User created.');
        out('  username : ' . $user['username']);
        out('  password : ' . $password);
        out('  API key  : ' . $user['api_token']);
        out('');
        out('Put the API key in the extension settings. Save all of this in CREDENTIALS.md.');
        break;

    case 'user:list':
        foreach (Db::all('SELECT id, username, api_token, created_at, last_login_at FROM users ORDER BY id') as $u) {
            out(sprintf('#%d  %-16s key=%s  created=%s  last_login=%s',
                $u['id'], $u['username'], $u['api_token'], $u['created_at'], $u['last_login_at'] ?? '-'));
        }
        break;

    case 'user:token':
        $username = $args[0] ?? exitWith('usage: user:token <username>');
        $u = Db::one('SELECT * FROM users WHERE username = ?', [$username]) ?? exitWith('No such user.');
        out('New API key: ' . Auth::rotateToken((int) $u['id']));
        break;

    case 'user:password':
        $username = $args[0] ?? exitWith('usage: user:password <username> <password>');
        $password = $args[1] ?? exitWith('usage: user:password <username> <password>');
        $u = Db::one('SELECT * FROM users WHERE username = ?', [$username]) ?? exitWith('No such user.');
        Auth::setPassword((int) $u['id'], $password);
        out('Password changed.');
        break;

    case 'providers':
        foreach (Providers::all() as $name => $p) {
            out(sprintf('%-12s v%-3d %-8s %s',
                $name, $p['version'], $p['enabled'] ? 'enabled' : 'off',
                implode(', ', (array) ($p['matches'] ?? []))));
        }
        out('bundle hash: ' . Providers::bundleHash());
        break;

    case 'check':
        $serialId = isset($flags['serial']) ? (int) $flags['serial'] : null;
        $minAge   = isset($flags['force']) ? 0 : 55;
        $verbose  = !isset($flags['quiet']);
        $t0    = microtime(true);
        $stats = Checker::runAll($serialId, $minAge, $verbose);
        out(sprintf('checked=%d added=%d notified=%d errors=%d pruned=%d in %.1fs',
            $stats['checked'], $stats['added'], $stats['notified'] ?? 0, $stats['errors'],
            $stats['pruned'] ?? 0, microtime(true) - $t0));
        exit($stats['errors'] > 0 ? 1 : 0);

    // Send one message through the notifier, to prove config.php is right.
    case 'notify:test':
        if (!Notify::enabled()) {
            exitWith("notify.url is empty in config.php, so nothing is ever sent.");
        }
        $text = $args[0] ?? "Serial Reminder\nThis is a test message.\n"
                          . rtrim((string) SR\Config::get('app_url', ''), '/') . '/dashboard';
        $err  = Notify::send($text);
        out($err === null ? 'sent' : 'FAILED: ' . $err);
        exit($err === null ? 0 : 1);

    case 'serials':
        $username = $args[0] ?? null;
        $user = $username !== null
            ? (Db::one('SELECT * FROM users WHERE username = ?', [$username]) ?? exitWith('No such user.'))
            : (Db::one('SELECT * FROM users ORDER BY id LIMIT 1') ?? exitWith('No users yet.'));
        foreach (Serials::listForUser((int) $user['id'], 'all') as $s) {
            out(sprintf('%-3d %-9s %-32s last=%-8s next=%-8s new=%-3d %s',
                $s['id'], $s['provider'],
                mb_strimwidth($s['title'], 0, 32, '…'),
                $s['lastWatched']['label'] ?? '-',
                $s['nextEpisode']['label'] ?? '-',
                $s['unwatchedCount'],
                $s['checkError'] ? 'ERR: ' . $s['checkError'] : ''));
        }
        break;

    default:
        out(trim((string) file_get_contents(__FILE__, false, null, 0, 900)));
}

function exitWith(string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}
