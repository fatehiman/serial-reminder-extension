<?php
declare(strict_types=1);

namespace SR;

/**
 * Two ways in:
 *   - API key  (Authorization: Bearer <token> / X-Api-Key)  -> used by the extension
 *   - session cookie                                        -> used by the dashboard
 *
 * The extension never sends the password. To open the dashboard without a login
 * form it trades its API key for a one-time ticket (see Auth::makeTicket()).
 */
final class Auth
{
    public const COOKIE = 'sr_session';

    private static ?array $user = null;

    /* ---------------------------------------------------------------- users */

    public static function createUser(string $username, string $password, ?string $displayName = null): array
    {
        $token = sr_token(24);
        Db::q(
            'INSERT INTO users (username, password_hash, api_token, display_name) VALUES (?, ?, ?, ?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), $token, $displayName]
        );
        return self::userById(Db::insertId()) ?? throw new \RuntimeException('user insert failed');
    }

    public static function userById(int $id): ?array
    {
        return Db::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function userByToken(string $token): ?array
    {
        return Db::one('SELECT * FROM users WHERE api_token = ?', [$token]);
    }

    public static function rotateToken(int $userId): string
    {
        $token = sr_token(24);
        Db::q('UPDATE users SET api_token = ? WHERE id = ?', [$token, $userId]);
        return $token;
    }

    public static function setPassword(int $userId, string $password): void
    {
        Db::q('UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $userId]);
    }

    /* ------------------------------------------------------------- API auth */

    /** Read the API key from the request, whichever header style was used. */
    public static function bearerToken(): ?string
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                $headers[strtolower($k)] = $v;
            }
        }
        $auth = $headers['authorization']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if (preg_match('/^Bearer\s+(\S+)$/i', (string) $auth, $m)) {
            return $m[1];
        }
        $key = $headers['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
        return $key !== null && $key !== '' ? (string) $key : null;
    }

    /**
     * For /api/* — dies with 401 when the key is missing or wrong.
     *
     * The dashboard's own JavaScript has a session cookie instead of an API key,
     * so it is allowed in too, but only when it sends the X-SR-Dashboard header.
     * A custom header forces a CORS preflight, which another website cannot pass,
     * so this cannot be abused as a cross-site request (CSRF).
     */
    public static function requireApiUser(): array
    {
        $token = self::bearerToken();
        if ($token !== null) {
            $user = self::userByToken($token);
            if ($user === null) {
                sr_fail('Invalid API key.', 401);
            }
            return $user;
        }

        if (!empty($_SERVER['HTTP_X_SR_DASHBOARD'])) {
            $user = self::currentUser();
            if ($user !== null) {
                return $user;
            }
            sr_fail('Session expired. Please log in again.', 401);
        }

        sr_fail('Missing API key. Send it as "Authorization: Bearer <key>".', 401);
    }

    /* --------------------------------------------------------- web sessions */

    public static function startSession(int $userId): string
    {
        $id   = sr_token(32);
        $days = (int) Config::get('session_days', 30);
        Db::q(
            'INSERT INTO sessions (id, user_id, expires_at, user_agent, ip) VALUES (?, ?, ?, ?, ?)',
            [
                $id,
                $userId,
                gmdate('Y-m-d H:i:s', time() + $days * 86400),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ]
        );
        setcookie(self::COOKIE, $id, [
            'expires'  => time() + $days * 86400,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        Db::q("UPDATE users SET last_login_at = datetime('now') WHERE id = ?", [$userId]);
        return $id;
    }

    public static function endSession(): void
    {
        $id = $_COOKIE[self::COOKIE] ?? null;
        if ($id) {
            Db::q('DELETE FROM sessions WHERE id = ?', [$id]);
        }
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
        self::$user = null;
    }

    public static function currentUser(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = $_COOKIE[self::COOKIE] ?? null;
        if (!$id) {
            return null;
        }
        $row = Db::one(
            "SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.expires_at > datetime('now')",
            [$id]
        );
        return self::$user = $row;
    }

    public static function requireWebUser(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        return $user;
    }

    /* ------------------------------------------------ one-time login tickets */

    /** Valid for 60 seconds, single use. The extension opens /auth/t/<ticket>. */
    public static function makeTicket(int $userId): string
    {
        self::gc();
        $id = sr_token(24);
        Db::q('INSERT INTO login_tickets (id, user_id, expires_at) VALUES (?, ?, ?)',
            [$id, $userId, gmdate('Y-m-d H:i:s', time() + 60)]);
        return $id;
    }

    public static function redeemTicket(string $ticket): ?array
    {
        $row = Db::one(
            "SELECT * FROM login_tickets
             WHERE id = ? AND used_at IS NULL AND expires_at > datetime('now')",
            [$ticket]
        );
        if ($row === null) {
            return null;
        }
        Db::q("UPDATE login_tickets SET used_at = datetime('now') WHERE id = ?", [$ticket]);
        return self::userById((int) $row['user_id']);
    }

    public static function gc(): void
    {
        Db::q("DELETE FROM login_tickets WHERE expires_at < datetime('now', '-1 day')");
        Db::q("DELETE FROM sessions WHERE expires_at < datetime('now')");
    }
}
