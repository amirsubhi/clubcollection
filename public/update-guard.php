<?php

/*
 * The swap guard: the first lines of public/index.php and artisan check storage/framework/updating before anything
 * from bootstrap/ or vendor/ is loaded, because those folders are renamed while an update switches versions. It lives
 * in public/ (never swapped; the update's last step replaces it) and is reachable without signing in, so it is small,
 * reads only fixed paths (never a path from the request) and, while the guard file exists, answers with the members'
 * pause page whenever anything is unclear.
 *
 * While the guard file exists a web request gets one of:
 * - the updating admin's valid maintenance bypass cookie: back to /update/ until the switch is done, then on into the
 *   portal (the admin's Watch strip, the health check);
 * - a POST to /webhook/toyyibpay or /webhook/billplz: saved to storage/app/updater/webhooks/ and answered 503 (the
 *   portal replays it once it is open again);
 * - everyone else: the pause page, 503 with Retry-After: 120.
 * If no step has run for 10 minutes, the request first undoes the abandoned update (ClubPortalUpdater\Recovery), which
 * needs no token and only ever puts the installed release back.
 *
 * Written so that an old PHP (5.6 / 7.x) can still parse and run it, like bootstrap/preflight.php: no short arrays,
 * type declarations, ?? operator, arrow functions, match or named arguments. CI runs php -l on it under PHP 5.6 and 7.4.
 */

if (! function_exists('clubportal_update_guard_decide')) {
    /**
     * What to do with this request: null (no guard file, or the admin's bypass cookie after the switch: carry on),
     * array('action' => 'to_updater'), array('action' => 'save_webhook') or array('action' => 'pause').
     * Only reads files. Recovery is not run here (clubportal_update_guard_recover).
     *
     * @param string      $basePath     the app folder (the parent of public/)
     * @param string      $method       the request method
     * @param string      $path         the request path without the query string
     * @param string|null $bypassCookie the laravel_maintenance cookie, if any
     */
    function clubportal_update_guard_decide($basePath, $method, $path, $bypassCookie)
    {
        if (! is_file($basePath.'/storage/framework/updating')) {
            return null;
        }
        if (clubportal_update_guard_cookie_valid($basePath, $bypassCookie)) {
            if (clubportal_update_guard_switched($basePath)) {
                return null;
            }

            return array('action' => 'to_updater');
        }
        if (is_string($method) && strtoupper($method) === 'POST' && clubportal_update_guard_webhook_path($path) !== null) {
            return array('action' => 'save_webhook');
        }

        return array('action' => 'pause');
    }

    /**
     * Undoes an abandoned update: the guard file is up and no step ran for 10 minutes. Needs PHP 8.4 and the updater
     * library; takes the update lock (another request holding it means nothing happens). Returns 'paused' (the guard
     * is still up), 'continue' (no guard file, or it was lifted without putting files back) or 'rolled_back' (this
     * request put the old files back: it must not go on into the app, whose code may already be cached).
     * A failure is kept in the journal as recovery_error for the updater page.
     */
    function clubportal_update_guard_recover($basePath)
    {
        $guard = $basePath.'/storage/framework/updating';
        if (! is_file($guard)) {
            return 'continue';
        }
        $idle = clubportal_update_guard_idle_seconds($basePath);
        if ($idle === null || $idle < 600) {
            return 'paused';
        }
        $lib = dirname(__FILE__).'/update/lib/load.php';
        if (! defined('PHP_VERSION_ID') || PHP_VERSION_ID < 80400 || ! is_file($lib)) {
            return 'paused';
        }
        $before = clubportal_update_guard_swap_state($basePath);
        $error = null;
        try {
            require_once $lib;
            ClubPortalUpdater\Recovery::runIfIdle($basePath);
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        if ($error !== null) {
            @error_log('Club Portal: undoing the abandoned update failed: '.$error);
            if (class_exists('ClubPortalUpdater\\Recovery', false)) {
                ClubPortalUpdater\Recovery::noteFailure($basePath, $error);
            }
        }
        clearstatcache();
        if (is_file($guard)) {
            return 'paused';
        }
        if ($before !== 'rolled_back' && clubportal_update_guard_swap_state($basePath) === 'rolled_back') {
            return 'rolled_back';
        }

        return 'continue';
    }

    /** The journal's swap state (meta.swap.state), or null. */
    function clubportal_update_guard_swap_state($basePath)
    {
        $journal = clubportal_update_guard_read_json($basePath.'/storage/app/updater/journal.json');

        return is_array($journal) && isset($journal['meta']['swap']['state']) && is_string($journal['meta']['swap']['state'])
            ? $journal['meta']['swap']['state'] : null;
    }

    /**
     * What to answer after this request put the old files back: a GET or HEAD is sent to the same address again (a
     * fresh request runs the restored code); anything else gets the pause page, which reloads the home page in 2 s.
     * The address is used only when it is a plain path on this site (never //host or a backslash).
     */
    function clubportal_update_guard_after_rollback($method, $uri)
    {
        $method = is_string($method) ? strtoupper($method) : '';
        if ($method !== 'GET' && $method !== 'HEAD') {
            return array('action' => 'reload_pause');
        }
        $location = '/';
        if (is_string($uri) && preg_match('~^/(?![/\\\\])[^\\\\\x00-\x20\x7f]*$~', $uri) === 1) {
            $location = $uri;
        }

        return array('action' => 'reload', 'location' => $location);
    }

    /**
     * Seconds since the update last moved: the journal's last_step_at, or the guard file's age when there is no journal
     * (or it has no time yet). Null when the journal exists but cannot be read: then nothing is undone.
     */
    function clubportal_update_guard_idle_seconds($basePath)
    {
        $journal = clubportal_update_guard_read_json($basePath.'/storage/app/updater/journal.json');
        if ($journal === false) {
            return null;
        }
        if (is_array($journal) && isset($journal['last_step_at']) && is_int($journal['last_step_at'])) {
            return max(0, time() - $journal['last_step_at']);
        }
        $mtime = @filemtime($basePath.'/storage/framework/updating');

        return is_int($mtime) ? max(0, time() - $mtime) : null;
    }

    /** True once the journal has the PHP cache step done: the new version is the one being served. */
    function clubportal_update_guard_switched($basePath)
    {
        $journal = clubportal_update_guard_read_json($basePath.'/storage/app/updater/journal.json');

        return is_array($journal) && isset($journal['steps']['opcache']['state'])
            && $journal['steps']['opcache']['state'] === 'done';
    }

    /**
     * Laravel's maintenance bypass cookie, checked as Illuminate\Foundation\Http\MaintenanceModeBypassCookie::isValid
     * does, with the secret from storage/framework/down. No secret (or an empty one) means no cookie is valid.
     */
    function clubportal_update_guard_cookie_valid($basePath, $cookie)
    {
        if (! is_string($cookie) || $cookie === '' || ! function_exists('hash_equals') || ! function_exists('hash_hmac')) {
            return false;
        }
        $down = clubportal_update_guard_read_json($basePath.'/storage/framework/down');
        if (! is_array($down) || ! isset($down['secret']) || ! is_string($down['secret']) || $down['secret'] === '') {
            return false;
        }
        $payload = json_decode((string) base64_decode($cookie), true);
        if (! is_array($payload) || ! isset($payload['expires_at']) || ! is_numeric($payload['expires_at'])
            || ! isset($payload['mac']) || ! is_string($payload['mac'])) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', (string) $payload['expires_at'], $down['secret']), $payload['mac'])
            && (int) $payload['expires_at'] >= time();
    }

    /**
     * The payment callback path this request is for, or null. Exactly /webhook/toyyibpay or /webhook/billplz (one
     * trailing slash allowed). A portal installed in a subfolder of the domain is not supported for saving callbacks.
     */
    function clubportal_update_guard_webhook_path($path)
    {
        if (! is_string($path)) {
            return null;
        }
        foreach (array('/webhook/toyyibpay', '/webhook/billplz') as $known) {
            if ($path === $known || $path === $known.'/') {
                return $known;
            }
        }

        return null;
    }

    /**
     * Saves a payment callback for the portal to replay: storage/app/updater/webhooks/<microtime>-<random>.json with
     * {path, body, headers, query, at}. Refused (false) for another path, a body over 64 KB or not UTF-8, or when 200 are
     * already waiting. The file name and folder never come from the request.
     *
     * @param string $path   the request path
     * @param mixed  $body   the raw body (false when it could not be read)
     * @param mixed  $server $_SERVER
     */
    function clubportal_update_guard_save_webhook($basePath, $path, $body, $server)
    {
        $known = clubportal_update_guard_webhook_path($path);
        if ($known === null || ! is_string($body) || strlen($body) > 65536) {
            return false;
        }
        $dir = $basePath.'/storage/app/updater/webhooks';
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            return false;
        }
        $names = @scandir($dir);
        if (! is_array($names)) {
            return false;
        }
        $waiting = 0;
        foreach ($names as $name) {
            if (substr($name, -5) === '.json') {
                $waiting++;
            }
        }
        if ($waiting >= 200) {
            return false;
        }
        $json = json_encode(array(
            'path' => $known,
            'body' => $body,
            'headers' => clubportal_update_guard_webhook_headers($server),
            'query' => clubportal_update_guard_webhook_query($server),
            'at' => time(),
        ));
        $random = clubportal_update_guard_random_hex(6);
        if (! is_string($json) || $random === null) {
            return false;
        }
        $parts = explode(' ', microtime());
        $name = $parts[1].substr($parts[0], 2, 6).'-'.$random.'.json';
        $tmp = $dir.'/.'.$random.'.tmp';
        if (@file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);

            return false;
        }
        @chmod($tmp, 0640);
        if (! @rename($tmp, $dir.'/'.$name)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /** The headers the callback handlers read: content-type, x-signature, x-toyyibpay-* and x-billplz-*. */
    function clubportal_update_guard_webhook_headers($server)
    {
        $out = array();
        if (! is_array($server)) {
            return $out;
        }
        foreach ($server as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }
            if ($key === 'CONTENT_TYPE' || $key === 'HTTP_CONTENT_TYPE') {
                $out['content-type'] = $value;

                continue;
            }
            if (strpos($key, 'HTTP_') !== 0) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            if ($name === 'x-signature' || strpos($name, 'x-toyyibpay-') === 0 || strpos($name, 'x-billplz-') === 0) {
                $out[$name] = $value;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * The request's query string, kept so the replay can show ToyyibPay's per-bill webhook_token (it travels in the
     * URL, not the body). Empty when there is none, it is not text, or it is over 1 KB.
     */
    function clubportal_update_guard_webhook_query($server)
    {
        $query = is_array($server) && isset($server['QUERY_STRING']) ? $server['QUERY_STRING'] : '';
        if (! is_string($query) || strlen($query) > 1024 || preg_match('/[^\x21-\x7e]/', $query)) {
            return '';
        }

        return $query;
    }

    /** Random hex, or null when this PHP has no safe random source. */
    function clubportal_update_guard_random_hex($bytes)
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes($bytes));
            } catch (Exception $e) {
                return null;
            } catch (Throwable $e) {
                return null;
            }
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $raw = openssl_random_pseudo_bytes($bytes, $strong);
            if (is_string($raw) && $strong) {
                return bin2hex($raw);
            }
        }

        return null;
    }

    /**
     * The response for a decision: array('status' => int, 'headers' => array(name => value), 'body' => string).
     * The pause page is storage/app/updater/pause.html (rendered in the portal's language before the pause), or a
     * built-in one in Bahasa Malaysia and English when it is missing.
     */
    function clubportal_update_guard_response($basePath, $decision)
    {
        $action = is_array($decision) && isset($decision['action']) ? $decision['action'] : 'pause';
        if ($action === 'reload') {
            $location = isset($decision['location']) && is_string($decision['location']) ? $decision['location'] : '/';

            return array('status' => 302, 'headers' => array('Location' => $location, 'Cache-Control' => 'no-store'), 'body' => '');
        }
        if ($action === 'to_updater') {
            return array('status' => 302, 'headers' => array('Location' => '/update/', 'Cache-Control' => 'no-store'), 'body' => '');
        }
        $headers = array('Retry-After' => '120', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex', 'X-Content-Type-Options' => 'nosniff');
        if ($action === 'save_webhook') {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';

            return array('status' => 503, 'headers' => $headers, 'body' => "The portal is being updated; try again in a few minutes.\n");
        }
        $pause = $basePath.'/storage/app/updater/pause.html';
        $html = is_file($pause) ? @file_get_contents($pause) : false;
        if (! is_string($html) || trim($html) === '') {
            $html = clubportal_update_guard_fallback_page();
        }
        if ($action === 'reload_pause') {
            $html = str_replace('content="30;url=/"', 'content="2;url=/"', $html);
            if (strpos($html, 'content="2;url=/"') === false) {
                $html = str_replace('content="30;url=/"', 'content="2;url=/"', clubportal_update_guard_fallback_page());
            }
            $headers['Retry-After'] = '2';
        }
        $headers['Content-Type'] = 'text/html; charset=UTF-8';

        return array('status' => 503, 'headers' => $headers, 'body' => $html);
    }

    /** The members' pause page when pause.html is missing: the approved J6 wording, with "Portal" for the portal name. */
    function clubportal_update_guard_fallback_page()
    {
        return '<!DOCTYPE html>'
            .'<html lang="ms"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta http-equiv="refresh" content="30;url=/">'
            .'<title>Portal sedang dikemas kini</title>'
            .'<style>body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f4f6f8;color:#1f2933;line-height:1.5}'
            .'main{max-width:34rem;margin:12vh auto;padding:2rem 1.5rem;background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08)}'
            .'h1{font-size:1.4rem;margin:0 0 .75rem}h2{font-size:1.1rem;margin:0 0 .5rem}p{margin:0 0 .75rem}'
            .'hr{border:0;border-top:1px solid #e4e7eb;margin:1.5rem 0}</style></head><body><main>'
            .'<section lang="ms"><h1>Portal sedang dikemas kini</h1>'
            .'<p>Kembali dalam beberapa minit. Halaman ini akan dimuat semula sendiri.</p>'
            .'<p>Jika anda baru membuat bayaran, bayaran anda selamat. Resit akan dipaparkan sebaik sahaja portal dibuka semula.</p>'
            .'<p>Jika anda sedang menghantar borang atau slip pindahan, sila hantar semula apabila kami kembali.</p></section>'
            .'<hr><section lang="en"><h2>Portal is being updated</h2>'
            .'<p>Back in a few minutes; this page reloads by itself.</p>'
            .'<p>If you&#39;ve just paid, your payment is safe. Your receipt will show as soon as the portal is open again.</p>'
            .'<p>If you were sending a form or a transfer slip, please send it again when we&#39;re back.</p></section>'
            .'</main></body></html>';
    }

    /** Sends a response built by clubportal_update_guard_response (headers, then the body). */
    function clubportal_update_guard_send($response)
    {
        if (! headers_sent()) {
            if (function_exists('http_response_code')) {
                http_response_code($response['status']);
            } else {
                header('HTTP/1.1 '.$response['status']);
            }
            foreach ($response['headers'] as $name => $value) {
                header($name.': '.$value);
            }
        }
        echo $response['body'];
    }

    /** Reads a small JSON file: null when it is missing, false when it cannot be read or parsed. */
    function clubportal_update_guard_read_json($file)
    {
        if (! is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : false;
    }

    /** public/index.php: returns when the request may carry on; otherwise answers it and exits. */
    function clubportal_update_guard_web($basePath)
    {
        if (! is_file($basePath.'/storage/framework/updating')) {
            return;
        }
        $decision = array('action' => 'pause');
        try {
            $recovered = clubportal_update_guard_recover($basePath);
            $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
            $path = $uri;
            $query = strpos($path, '?');
            if ($query !== false) {
                $path = substr($path, 0, $query);
            }
            if ($recovered === 'rolled_back') {
                // A payment callback on the request that put the old files back is saved first, like any other.
                $after = clubportal_update_guard_after_rollback($method, $uri);
                if (strtoupper($method) === 'POST' && clubportal_update_guard_webhook_path($path) !== null) {
                    clubportal_update_guard_save_webhook($basePath, $path, clubportal_update_guard_read_body(), $_SERVER);
                    $after = array('action' => 'save_webhook');
                }
                clubportal_update_guard_send(clubportal_update_guard_response($basePath, $after));
                exit;
            }
            $cookie = isset($_COOKIE['laravel_maintenance']) && is_string($_COOKIE['laravel_maintenance']) ? $_COOKIE['laravel_maintenance'] : null;
            $decision = clubportal_update_guard_decide($basePath, $method, $path, $cookie);
            if ($decision === null) {
                return;
            }
            if ($decision['action'] === 'save_webhook') {
                clubportal_update_guard_save_webhook($basePath, $path, clubportal_update_guard_read_body(), $_SERVER);
            }
        } catch (Exception $e) {
            $decision = array('action' => 'pause');
        } catch (Throwable $e) {
            $decision = array('action' => 'pause');
        }
        clubportal_update_guard_send(clubportal_update_guard_response($basePath, $decision));
        exit;
    }

    /** The request body, at most one byte over the 64 KB limit (so a bigger one is refused); false when unreadable. */
    function clubportal_update_guard_read_body()
    {
        $body = false;
        $in = @fopen('php://input', 'rb');
        if ($in !== false) {
            $body = stream_get_contents($in, 65537);
            fclose($in);
        }

        return $body;
    }

    /**
     * True when this command was started by `php artisan app:update` to finish the switch: the environment variable
     * CLUBPORTAL_UPDATER_PASS equals the one-time secret in storage/app/updater/cli-pass (written by app:update just
     * before it starts its child commands, deleted when they end). Anything else is false.
     */
    function clubportal_update_guard_cli_pass($basePath)
    {
        $given = function_exists('getenv') ? getenv('CLUBPORTAL_UPDATER_PASS') : false;
        if (! is_string($given) || $given === '') {
            return false;
        }
        $file = $basePath.'/storage/app/updater/cli-pass';
        if (! is_file($file)) {
            return false;
        }
        $raw = @file_get_contents($file);
        $secret = is_string($raw) ? trim($raw) : '';
        if (strlen($secret) < 32) {
            return false;
        }
        if (function_exists('hash_equals')) {
            return hash_equals($secret, $given);
        }

        return $secret === $given;
    }

    /**
     * artisan: while the guard file exists, print one line and stop (exit 0), unless app:update started this command
     * with its one-time pass (clubportal_update_guard_cli_pass). Never undoes anything (web only).
     */
    function clubportal_update_guard_cli($basePath)
    {
        if (! is_file($basePath.'/storage/framework/updating')) {
            return;
        }
        if (clubportal_update_guard_cli_pass($basePath)) {
            return;
        }
        echo "Club Portal is being updated; try again in a few minutes.\n";
        exit(0);
    }
}
