#!/usr/bin/env php
<?php

/**
 * Daily cron client for the webtrees "Reminders" module.
 *
 *   wt-reminders-cron.php dry     builds every digest, sends NO mail (default)
 *   wt-reminders-cron.php send    builds and sends
 *
 * The token is sent in a POST body (never in the URL) and never appears on a
 * command line (visible to other users in `ps`): it lives in a mode-600 config
 * file and travels only inside the HTTP request body.
 * Each subscriber is processed in its own request, with that subscriber's own
 * privileges (see src/ReminderCron.php).
 *
 * Config file (default /etc/wt-reminders-cron.conf, or $WT_REMINDERS_CONFIG):
 *   CRON_URL="https://example.com/webtrees/index.php?route=/reminders-cron"
 *   CRON_TOKEN="…the module's cron_token…"
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

const WT_REMINDERS_XREF_PATTERN = '/^[A-Za-z0-9]{1,20}$/';

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
 * @return array{status:int, body:string, cookies:array<string,string>}
 */
function request(string $method, string $url, array $cookies = [], ?string $body = null): array
{
    $headers = ['Accept: text/plain', 'User-Agent: wt-reminders-cron'];

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

    $response_headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);

    if ($content === false || $response_headers === []) {
        return ['status' => 0, 'body' => '', 'cookies' => []];
    }

    $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $response_headers[0], $m) === 1 ? (int) $m[1] : 0;
    $set    = [];

    foreach ($response_headers as $header) {
        if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $header, $m) === 1) {
            $set[$m[1]] = $m[2];
        }
    }

    return ['status' => $status, 'body' => $content, 'cookies' => $set];
}

/**
 * call(url, token, fields) -> response body, or null if the HTTP exchange failed.
 * A fresh session per call: GET gives the session cookie + CSRF token, POST uses both.
 *
 * @param array<string,string> $fields
 */
function call(string $url, string $token, array $fields): ?string
{
    $first = request('GET', $url);
    $csrf  = trim($first['body']);

    if ($first['status'] < 200 || $first['status'] > 299 || preg_match('/^[A-Za-z0-9]{16,128}$/', $csrf) !== 1) {
        fwrite(STDERR, "unexpected answer to the CSRF request\n");

        return null;
    }

    $post = request('POST', $url, $first['cookies'], http_build_query(['_csrf' => $csrf, 'token' => $token] + $fields));

    return $post['status'] >= 200 && $post['status'] <= 299 ? $post['body'] : null;
}

// ---------------------------------------------------------------------------

$mode = $argv[1] ?? 'dry';

if (!in_array($mode, ['dry', 'send'], true)) {
    fail('usage: ' . basename($argv[0] ?? 'wt-reminders-cron.php') . ' [dry|send]');
}

$config_file = getenv('WT_REMINDERS_CONFIG') ?: '/etc/wt-reminders-cron.conf';

if (!is_file($config_file) || !is_readable($config_file)) {
    fail("cannot read {$config_file}");
}

// The file contains the secret: only trust it if it is ours and not readable by anyone else.
if (function_exists('posix_geteuid') && fileowner($config_file) !== posix_geteuid()) {
    fail("refusing {$config_file}: not owned by the user running this script");
}

if (!in_array(fileperms($config_file) & 0777, [0600, 0400], true)) {
    fail("refusing {$config_file}: mode must be 600 or 400");
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

if (preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) !== 1) {
    fail('CRON_TOKEN has an unexpected format');
}

// The token travels in the body: never over plain HTTP (unless explicitly allowed, e.g. a local test).
$insecure = $config['ALLOW_INSECURE_HTTP'] ?? (getenv('ALLOW_INSECURE_HTTP') ?: '0');

if (!str_starts_with($url, 'https://') && $insecure !== '1') {
    fail('refusing a non-HTTPS CRON_URL (set ALLOW_INSECURE_HTTP=1 only for local tests)');
}

$send = $mode === 'send' ? '1' : '0';

$subscribers = call($url, $token, ['action' => 'list']);

if ($subscribers === null) {
    fail('could not list subscribers (bad token, URL or CSRF?)', 1);
}

$subscribers = trim($subscribers);

if ($subscribers === '') {
    echo "no subscribers\n";
    exit(0);
}

$status = 0;

foreach (preg_split('/\R/', $subscribers) ?: [] as $xref) {
    if (preg_match(WT_REMINDERS_XREF_PATTERN, $xref) !== 1) {
        continue;
    }

    $out = call($url, $token, ['action' => 'run', 'xref' => $xref, 'send' => $send]);

    if ($out === null) {
        fwrite(STDERR, "ERR   {$xref} — request failed\n");
        $status = 1;
        continue;
    }

    echo rtrim($out, "\n"), "\n";

    if (preg_match('/^(ERR|EXC|FAIL)/m', $out) === 1) {
        $status = 1;
    }
}

exit($status);
