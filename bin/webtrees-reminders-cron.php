#!/usr/bin/env php
<?php

/**
 * Daily cron client for the webtrees "Event reminders" module.
 *
 *   webtrees-reminders-cron.php dry     builds every digest, sends NO mail (default)
 *   webtrees-reminders-cron.php send    builds and sends
 *
 * The token is sent in a POST body (never in the URL) and never appears on a
 * command line (visible to other users in `ps`): it lives in a private config
 * file and travels only inside the HTTP request body.
 * Each subscriber is processed in its own request, with that subscriber's own
 * privileges (see src/ReminderCron.php).
 *
 * Config file. Default location:
 *   Linux/macOS: /etc/webtrees-reminders-cron.conf
 *   Windows:     %APPDATA%\webtrees-reminders-cron.conf
 * or the path in the WEBTREES_REMINDERS_CONFIG environment variable. Contents
 * (copy both values from the module's administration page: "Cron endpoint" and
 * the sample configuration file shown below it):
 *   CRON_URL="https://example.com/webtrees/index.php?route=/module/_mitalteli-reminders_/Cron"
 *   CRON_TOKEN="...the module's cron token..."
 *
 * Diagnostics: whenever an HTTP exchange does not return 200, a one-line message
 * with the failing step, the HTTP status and a hint goes to STDERR, in BOTH modes
 * (a silent cron failure is worse than a noisy one). It never contains the token,
 * the CSRF token or the response body. In "dry" mode (an administrator at a
 * terminal) it adds more detail: the URL used, the transport error and a
 * sanitised excerpt of the response body. In "send" mode (unattended, output
 * usually mailed by cron) that detail is withheld, because a server error page can
 * leak paths, versions or configuration to a mailbox.
 *
 * Requires PHP 8.1+ with the "openssl" extension (for https). No other
 * dependency: it does not need curl, wget or a shell.
 */

declare(strict_types=1);

// This file lives inside the webroot of webtrees: never run it from the web.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const WEBTREES_REMINDERS_XREF_PATTERN = '/^[A-Za-z0-9]{1,20}$/';

function fail(string $message, int $code = 2): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

/**
 * Reads KEY="value" lines (the format of the former shell config). The file is
 * parsed, never executed.
 *
 * @return array<string,string>
 */
function readConfig(string $file): array
{
    $values = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (preg_match('/^(?:export\s+)?([A-Z][A-Z0-9_]*)=(?:"([^"]*)"|\'([^\']*)\'|(\S*))\s*(?:#.*)?$/', $line, $m) === 1) {
            $values[$m[1]] = $m[2] . ($m[3] ?? '') . ($m[4] ?? '');
        }
    }

    return $values;
}

/**
 * One HTTP request, no redirects (webtrees answers a bad/expired CSRF token
 * with a 302: fail instead of following it) and no retries (a retried POST
 * could send a digest twice).
 *
 * @param array<string,string> $cookies
 *
 * @return array{status:int, body:string, cookies:array<string,string>, error:string}
 */
function request(string $method, string $url, array $cookies = [], ?string $body = null): array
{
    $headers = ['Accept: text/plain', 'User-Agent: webtrees-reminders-cron'];

    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $http = [
        'method'          => $method,
        'header'          => implode("\r\n", $headers),
        'timeout'         => 120,
        'follow_location' => 0,
        'max_redirects'   => 0,
        'ignore_errors'   => true,
    ];

    if ($body !== null) {
        $http['header']  .= "\r\nContent-Type: application/x-www-form-urlencoded";
        $http['content']  = $body;
    }

    $content = @file_get_contents($url, false, stream_context_create(['http' => $http]));
    $error   = $content === false ? (string) (error_get_last()['message'] ?? '') : '';

    $response_headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);

    if ($content === false || $response_headers === []) {
        return ['status' => 0, 'body' => '', 'cookies' => [], 'error' => $error];
    }

    $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $response_headers[0], $m) === 1 ? (int) $m[1] : 0;
    $set    = [];

    foreach ($response_headers as $header) {
        if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $header, $m) === 1) {
            $set[$m[1]] = $m[2];
        }
    }

    return ['status' => $status, 'body' => $content, 'cookies' => $set, 'error' => ''];
}

/**
 * Hint for an unexpected HTTP status. Static text only: nothing from the response.
 */
function hint(int $status): string
{
    return match (true) {
        $status === 0 => 'no HTTP response: check the host name, the network, the firewall and the TLS certificate (on Windows PHP needs openssl.cafile set in php.ini)',
        $status >= 301 && $status <= 308 => 'redirect: CRON_URL is probably wrong (http instead of https, a missing sub-folder, a language/login redirect) or webtrees rejected the CSRF token; copy "Cron endpoint" again from the module settings',
        $status === 400 => 'bad request: the module rejected the request fields',
        $status === 403 => 'forbidden: CRON_TOKEN is wrong, was regenerated, or no token is set in the module settings (or a firewall/bot blocker rejected the client)',
        $status === 404 => 'not found: CRON_URL is wrong or the module is disabled; the URL changed in version 1.1.0, so copy "Cron endpoint" again from the module settings',
        $status === 405 || $status === 406 => 'the web server or webtrees refused this kind of request: check the URL and any bot blocker/firewall rules',
        $status === 429 => 'too many requests: a rate limiter is throttling the cron',
        $status >= 500 => 'server error: look at the webtrees log (Control panel > Website logs) and the web server error log',
        default => 'unexpected status',
    };
}

/**
 * Report an exchange that did not return 200 (see the header: what is shown, and why).
 *
 * @param array{status:int, body:string, cookies:array<string,string>, error:string} $response
 */
function diagnose(string $step, string $url, array $response): void
{
    global $verbose;

    $status = (int) $response['status'];

    fwrite(STDERR, sprintf("ERR   %s: HTTP %s - %s\n", $step, $status === 0 ? 'none' : (string) $status, hint($status)));

    if (!$verbose) {
        return;
    }

    fwrite(STDERR, "      url: {$url}\n");

    if ($response['error'] !== '') {
        fwrite(STDERR, '      transport error: ' . sanitize($response['error']) . "\n");
    }

    if ($response['body'] !== '') {
        fwrite(STDERR, '      response body (first 300 characters): ' . sanitize(mb_substr($response['body'], 0, 300)) . "\n");
    }
}

/** One printable line: control characters (terminal escape sequences) are removed. */
function sanitize(string $text): string
{
    return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '');
}

/**
 * call(step, url, token, fields) -> response body, or null if the exchange failed.
 * A fresh session per call: GET gives the session cookie + CSRF token, POST uses both.
 *
 * @param array<string,string> $fields
 */
function call(string $step, string $url, string $token, array $fields): ?string
{
    $first = request('GET', $url);
    $csrf  = trim($first['body']);

    if ($first['status'] !== 200) {
        diagnose($step . ' (CSRF request)', $url, $first);

        return null;
    }

    if (preg_match('/^[A-Za-z0-9]{16,128}$/', $csrf) !== 1) {
        // Status 200 but not our endpoint (e.g. a login page or a cache): never print the body.
        fwrite(STDERR, "ERR   {$step} (CSRF request): HTTP 200 but the answer is not a CSRF token - CRON_URL does not point to the module's cron endpoint\n");

        return null;
    }

    $post = request('POST', $url, $first['cookies'], http_build_query(['_csrf' => $csrf, 'token' => $token] + $fields));

    if ($post['status'] !== 200) {
        diagnose($step, $url, $post);

        return null;
    }

    return $post['body'];
}

// ---------------------------------------------------------------------------

$mode = $argv[1] ?? 'dry';

if (!in_array($mode, ['dry', 'send'], true)) {
    fail('usage: ' . basename($argv[0] ?? 'webtrees-reminders-cron.php') . ' [dry|send]');
}

// Extra diagnostic detail only for an administrator running it by hand.
$verbose = $mode === 'dry';
$windows = PHP_OS_FAMILY === 'Windows';

$default_config = $windows
    ? (string) (getenv('APPDATA') ?: getenv('USERPROFILE') ?: '.') . '\\webtrees-reminders-cron.conf'
    : '/etc/webtrees-reminders-cron.conf';

$config_file = getenv('WEBTREES_REMINDERS_CONFIG') ?: $default_config;

// Upgrade path: the files used to be called wt-reminders-cron.*
if (!is_file($config_file) && !$windows && is_file('/etc/wt-reminders-cron.conf')) {
    fwrite(STDERR, "warn  using the legacy /etc/wt-reminders-cron.conf: rename it to {$config_file} and update CRON_URL\n");
    $config_file = '/etc/wt-reminders-cron.conf';
}

if (!is_file($config_file) || !is_readable($config_file)) {
    fail("cannot read {$config_file}");
}

// The file contains the secret: only trust it if it is ours and not readable by anyone else.
// (Windows has no POSIX owner/mode: restrict the file with icacls instead, see the README.)
if (!$windows) {
    if (function_exists('posix_geteuid') && fileowner($config_file) !== posix_geteuid()) {
        fail("refusing {$config_file}: not owned by the user running this script");
    }

    if (!in_array(fileperms($config_file) & 0777, [0600, 0400], true)) {
        fail("refusing {$config_file}: mode must be 600 or 400");
    }
}

$config = readConfig($config_file);
$url    = $config['CRON_URL'] ?? '';
$token  = $config['CRON_TOKEN'] ?? '';

if ($url === '') {
    fail("CRON_URL is missing in {$config_file}");
}

if ($token === '') {
    fail("CRON_TOKEN is missing in {$config_file}");
}

if (str_contains($token, '<<<') || str_contains($token, 'PASTE')) {
    fail("CRON_TOKEN in {$config_file} is still the placeholder: paste the token that the module settings page showed when it was generated (generate a new one there if it was lost)");
}

if (preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) !== 1) {
    fail('CRON_TOKEN has an unexpected format (32-128 characters: letters, digits, "-" and "_")');
}

// The token travels in the body: never over plain HTTP (unless explicitly allowed, e.g. a local test).
$insecure = $config['ALLOW_INSECURE_HTTP'] ?? (getenv('ALLOW_INSECURE_HTTP') ?: '0');

if (!str_starts_with($url, 'https://') && $insecure !== '1') {
    fail('refusing a non-HTTPS CRON_URL (set ALLOW_INSECURE_HTTP=1 only for local tests)');
}

$send = $mode === 'send' ? '1' : '0';

$subscribers = call('list subscribers', $url, $token, ['action' => 'list']);

if ($subscribers === null) {
    fail('could not list subscribers (see the message above)', 1);
}

$subscribers = trim($subscribers);

if ($subscribers === '') {
    echo "no subscribers\n";
    exit(0);
}

$status = 0;

foreach (preg_split('/\R/', $subscribers) ?: [] as $xref) {
    if (preg_match(WEBTREES_REMINDERS_XREF_PATTERN, $xref) !== 1) {
        continue;
    }

    $out = call("run {$xref}", $url, $token, ['action' => 'run', 'xref' => $xref, 'send' => $send]);

    if ($out === null) {
        $status = 1;
        continue;
    }

    echo rtrim($out, "\n"), "\n";

    if (preg_match('/^(ERR|EXC|FAIL)/m', $out) === 1) {
        $status = 1;
    }
}

exit($status);
