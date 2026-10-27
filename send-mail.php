<?php
// Email API for wedding invitations: emails RSVPs and guestbook wishes through the SMTP server the site sends, in
// the email template the site names (templates/<name>/; see templates/README.md). One copy serves any number of sites.
//
// The invitation itself is a static site (GitHub Pages); this script runs on its own server and is called
// cross-site, so it only answers browsers on the origins listed in ALLOWED_ORIGINS (plus localhost for development).
// It has its OWN .env, separate from any site's: next to this script (api/.env; .htaccess keeps it from being
// served), or one folder above it, outside the web root (safest). No dependencies: a small SMTP client
// (STARTTLS / implicit TLS) is at the bottom.
//
// One script, many invitations: each site sends its whole mail setup (SMTP_HOST, SMTP_PORT, SMTP_SECURE, SMTP_USER,
// SMTP_PASS, SMTP_FROM, SMTP_TO) as "credentials", a token encrypted with MAIL_ENCRYPTION_KEY (see EMAIL.md and
// scripts/mail-credentials.mjs). So the server's .env only needs MAIL_ENCRYPTION_KEY and ALLOWED_ORIGINS. Any SMTP_*
// value in .env is used only for a request that sends no token.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Vary: Origin');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- config -------------------------------------------------------------------------------

function load_env(): array
{
    // its own .env first (beside the script), then one or two folders up (outside the web root)
    foreach ([__DIR__ . '/.env', dirname(__DIR__) . '/.env', dirname(__DIR__, 2) . '/.env'] as $path) {
        if (!is_readable($path)) continue;
        $env = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            }
            $env[$key] = $value;
        }
        return $env;
    }
    return [];
}

$env = load_env();

// ---- CORS: only the invitation's own site may call this from a browser ------------------------

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = array_filter(array_map(fn ($o) => rtrim(trim($o), '/'), explode(',', $env['ALLOWED_ORIGINS'] ?? '')));
$isLocal = (bool) preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin);
if ($origin !== '') {
    if (!$isLocal && !in_array($origin, $allowed, true)) respond(403, ['ok' => false, 'error' => 'origin']);
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok' => false, 'error' => 'method']);

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) respond(400, ['ok' => false, 'error' => 'invalid']);

// ---- this invitation's mail setup (encrypted), if it sent one --------------------------------

if (isset($input['credentials']) && $input['credentials'] !== '') {
    $credentials = is_string($input['credentials']) ? decrypt_credentials($input['credentials'], $env['MAIL_ENCRYPTION_KEY'] ?? '') : null;
    if ($credentials === null) {
        error_log('send-mail: credentials could not be decrypted (is MAIL_ENCRYPTION_KEY the same as the site\'s?)');
        respond(400, ['ok' => false, 'error' => 'credentials']);
    }
    $env = array_merge($env, $credentials);
}

foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM', 'SMTP_TO'] as $key) {
    if (empty($env[$key])) {
        error_log("send-mail: missing $key (not in the site's credentials token, nor in .env)");
        respond(500, ['ok' => false, 'error' => 'config']);
    }
}
// SMTP_FROM / SMTP_TO need real addresses ("Name <you@example.com>" or "you@example.com"); real servers reject anything else
$recipientList = array_filter(array_map('trim', explode(',', $env['SMTP_TO'])));
foreach (['SMTP_FROM' => [$env['SMTP_FROM']], 'SMTP_TO' => $recipientList] as $key => $values) {
    foreach ($values as $value) {
        if (!filter_var(address($value), FILTER_VALIDATE_EMAIL)) {
            error_log("send-mail: $key has no valid email address (\"$value\"); use \"Name <you@example.com>\"");
            respond(500, ['ok' => false, 'error' => 'config']);
        }
    }
}

// ---- input --------------------------------------------------------------------------------

// Milliseconds between opening the invitation and sending. Bots post instantly; a guest needs at least a couple
// of seconds to type a name. Rejected openly (never a silent "ok"), so a real guest would at least see an error.
if ((int) ($input['elapsed'] ?? 0) < 2000) {
    error_log('send-mail: rejected a submission sent too quickly after the page opened');
    respond(400, ['ok' => false, 'error' => 'invalid']);
}

function field(array $input, string $key, int $max): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value)) return '';
    $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
    return mb_substr($value, 0, $max);
}

$type = field($input, 'type', 10);
$name = field($input, 'name', 80);
if ($name === '' || !in_array($type, ['rsvp', 'wish'], true)) respond(400, ['ok' => false, 'error' => 'invalid']);

// The site names its email template (a folder in templates/); an unknown or missing name gets templates/default
[$templateDir, $templateData] = load_template(field($input, 'template', 64));
date_default_timezone_set(is_string($templateData['timezone'] ?? null) && in_array($templateData['timezone'], timezone_identifiers_list(), true) ? $templateData['timezone'] : 'Africa/Cairo');
$quote = null;
if ($type === 'rsvp') {
    $attending = field($input, 'attending', 3) === 'no' ? 'no' : 'yes';
    $guests = max(1, min(10, (int) field($input, 'guests', 3)));
    $subject = $attending === 'yes'
        ? "Attending: $name ($guests " . ($guests === 1 ? 'guest' : 'guests') . ')'
        : "Not attending: $name";
    $badge = $attending === 'yes'
        ? ['attending', 'سيحضر إن شاء الله', 'Attending']
        : ['not_attending', 'لن يتمكن من الحضور', 'Not attending'];
    $rows = [['الاسم', 'Name', $name], ['الحضور', 'Attending', $attending === 'yes' ? 'نعم · Yes' : 'لا · No']];
    if ($attending === 'yes') $rows[] = ['عدد الحضور', 'Guests', (string) $guests];
} else {
    $quote = field($input, 'text', 1000);
    if ($quote === '') respond(400, ['ok' => false, 'error' => 'invalid']);
    $subject = "Wishes from $name";
    $badge = ['wish', 'تهنئة جديدة', 'New wishes'];
    $rows = [['من', 'From', $name]];
}
$rows[] = ['الوقت', 'Received', date('j M Y · g:i A')];
[$text, $html] = render_email($templateDir, $templateData, $badge, $rows, $quote);

// ---- rate limit: 6 messages per visitor per 10 minutes --------------------------------------

// Behind Traefik every request arrives from Traefik's private address, so the guest's own address comes from
// X-Forwarded-For; it's only trusted when the direct peer is a private (proxy) address.
$client = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!filter_var($client, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $client = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
}
$bucket = sys_get_temp_dir() . '/wedding-mail-' . hash('sha256', $client);
$now = time();
$hits = array_filter(
    is_readable($bucket) ? (json_decode((string) file_get_contents($bucket), true) ?: []) : [],
    fn ($t) => is_int($t) && $t > $now - 600,
);
if (count($hits) >= 6) respond(429, ['ok' => false, 'error' => 'rate']);
$hits[] = $now;
@file_put_contents($bucket, json_encode(array_values($hits)), LOCK_EX);

// ---- send ---------------------------------------------------------------------------------

try {
    smtp_send($env, $subject, $text, $html);
    respond(200, ['ok' => true]);
} catch (Throwable $e) {
    error_log('send-mail: ' . $e->getMessage());
    respond(502, ['ok' => false, 'error' => 'send']);
}

// ---- email templates ----------------------------------------------------------------------
// Each invitation has a folder in templates/ (see templates/README.md):
//   email.html      the HTML email      email.txt      the plain-text version (optional)
//   template.json   its names, date, venue, timezone and badge colours, all usable in the two files above
// A folder without its own email.html / email.txt uses default's.

function load_template(string $name): array
{
    $base = __DIR__ . '/templates/';
    if ($name !== '' && (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name) || !is_dir($base . $name))) {
        error_log("send-mail: no template \"$name\" in templates/; using default");
        $name = '';
    }
    $dir = $base . ($name === '' ? 'default' : $name);
    $data = json_decode((string) @file_get_contents("$dir/template.json"), true);
    return [$dir, is_array($data) ? $data : []];
}

function render_email(string $dir, array $data, array $badge, array $rows, ?string $quote): array
{
    [$kind, $badgeAr, $badgeEn] = $badge;
    $colours = is_array($data['badges'][$kind] ?? null) ? $data['badges'][$kind] : [];
    $data = array_merge($data, [
        'badge_ar' => $badgeAr,
        'badge_en' => $badgeEn,
        'badge_ink' => (string) ($colours['ink'] ?? '#300A13'),
        'badge_bg' => (string) ($colours['bg'] ?? '#E9D3A0'),
        'guest' => $rows[0][2],
        'quote' => $quote ?? '',
        'rows' => array_map(fn ($row, $i) => ['label_ar' => $row[0], 'label_en' => $row[1], 'value' => $row[2], 'first' => $i === 0], $rows, array_keys($rows)),
    ]);

    $file = function (string $name) use ($dir): ?string {
        foreach ([$dir, __DIR__ . '/templates/default'] as $folder) {
            if (is_readable("$folder/$name")) return (string) file_get_contents("$folder/$name");
        }
        return null;
    };
    $html = $file('email.html');
    if ($html === null) throw new RuntimeException('no email.html in templates/');
    $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html; // the template's own notes stay out of the email
    $text = $file('email.txt') ?? "{{badge_ar}} · {{badge_en}}\n\n{{#rows}}{{label_ar}} / {{label_en}}: {{value}}\n{{/rows}}{{#quote}}\n«{{quote}}»\n{{/quote}}";

    return [
        render_template($text, $data, fn (string $v) => $v),
        render_template($html, $data, fn (string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
    ];
}

/**
 * A small Mustache-style template engine:
 *   {{key}}               the value, passed through $escape (HTML-escaped for email.html)
 *   {{#key}} … {{/key}}   repeated for each item of a list (the item's fields join the values), or shown once if
 *                         the value is set and not empty / false
 *   {{^key}} … {{/key}}   shown only if the value is missing, empty or false
 */
function render_template(string $template, array $data, callable $escape): string
{
    $template = preg_replace_callback('/\{\{([#^])(\w+)\}\}(.*?)\{\{\/\2\}\}/s', function (array $m) use ($data, $escape): string {
        $value = $data[$m[2]] ?? null;
        if ($m[1] === '^') return empty($value) ? render_template($m[3], $data, $escape) : '';
        if (is_array($value) && array_is_list($value)) {
            return implode('', array_map(fn ($item) => render_template($m[3], array_merge($data, (array) $item), $escape), $value));
        }
        return empty($value) ? '' : render_template($m[3], $data, $escape);
    }, $template) ?? $template;
    return preg_replace_callback('/\{\{(\w+)\}\}/', fn (array $m): string =>
        isset($data[$m[1]]) && is_scalar($data[$m[1]]) ? $escape((string) $data[$m[1]]) : '', $template) ?? $template;
}

// ---- encrypted credentials -----------------------------------------------------------------

/**
 * Token = base64url( 12-byte IV | 16-byte GCM tag | AES-256-GCM ciphertext of
 *         {"host","port","secure","user","pass","from","to"} ).
 * Returns the SMTP_* values, or null if the key is missing or wrong, or the token was tampered with.
 * host / port / secure may be missing (tokens made before they were added); .env then supplies them.
 */
function decrypt_credentials(string $token, string $encodedKey): ?array
{
    $key = base64_decode($encodedKey, true);
    $raw = base64_decode(strtr($token, '-_', '+/'), true);
    if ($key === false || strlen($key) !== 32 || $raw === false || strlen($raw) <= 28) return null;

    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    $data = $plain === false ? null : json_decode($plain, true);
    if (!is_array($data)) return null;

    $out = [];
    foreach (['user' => 'SMTP_USER', 'pass' => 'SMTP_PASS', 'from' => 'SMTP_FROM', 'to' => 'SMTP_TO'] as $field => $name) {
        if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') return null;
        $out[$name] = $data[$field];
    }
    foreach (['host' => 'SMTP_HOST', 'port' => 'SMTP_PORT', 'secure' => 'SMTP_SECURE'] as $field => $name) {
        if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') $out[$name] = $data[$field];
    }
    // a hostname or IP, and a real port
    if (isset($out['SMTP_HOST']) && !preg_match('/^[A-Za-z0-9.-]{1,253}$/', $out['SMTP_HOST'])) return null;
    if (isset($out['SMTP_PORT']) && !(ctype_digit($out['SMTP_PORT']) && (int) $out['SMTP_PORT'] >= 1 && (int) $out['SMTP_PORT'] <= 65535)) return null;
    return $out;
}

// ---- minimal SMTP client -------------------------------------------------------------------

function address(string $value): string
{
    return preg_match('/<([^>]+)>/', $value, $m) ? trim($m[1]) : trim($value);
}

function encode_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function encode_mailbox(string $value): string
{
    // "Display Name <addr>" -> encoded display name + address
    if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $value, $m) && $m[1] !== '') {
        return encode_header($m[1]) . ' <' . trim($m[2]) . '>';
    }
    return '<' . address($value) . '>';
}

function smtp_send(array $env, string $subject, string $text, string $html): void
{
    $host = $env['SMTP_HOST'];
    $port = (int) $env['SMTP_PORT'];
    $implicitTls = $port === 465 || in_array(strtolower($env['SMTP_SECURE'] ?? ''), ['true', '1', 'ssl', 'tls'], true);

    $socket = @stream_socket_client(
        ($implicitTls ? 'ssl://' : 'tcp://') . "$host:$port",
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]),
    );
    if (!$socket) throw new RuntimeException("connect failed: $errstr ($errno)");
    stream_set_timeout($socket, 15);

    $read = function () use ($socket): array {
        $text = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return [(int) substr($text, 0, 3), $text];
    };
    $command = function (?string $line, array $expect) use ($socket, $read): string {
        if ($line !== null) fwrite($socket, $line . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $expect, true)) {
            $shown = $line !== null && str_starts_with($line, 'AUTH') ? 'AUTH' : ($line ?? 'greeting');
            throw new RuntimeException("SMTP $shown -> $text");
        }
        return $text;
    };

    $command(null, [220]);
    $helo = 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $features = $command($helo, [250]);

    if (!$implicitTls && stripos($features, 'STARTTLS') !== false) {
        $command('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS failed');
        }
        $command($helo, [250]);
    }

    $command('AUTH LOGIN', [334]);
    $command(base64_encode($env['SMTP_USER']), [334]);
    $command(base64_encode($env['SMTP_PASS']), [235]);

    $from = address($env['SMTP_FROM']);
    $recipients = array_filter(array_map('trim', explode(',', $env['SMTP_TO'])));
    $command("MAIL FROM:<$from>", [250]);
    foreach ($recipients as $to) $command('RCPT TO:<' . address($to) . '>', [250, 251]);

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . encode_mailbox($env['SMTP_FROM']),
        'To: ' . implode(', ', array_map(fn ($to) => '<' . address($to) . '>', $recipients)),
        'Subject: ' . encode_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
        'MIME-Version: 1.0',
    ];
    // plain-text and HTML versions of the same message; mail apps show the best one they support
    $boundary = 'b' . bin2hex(random_bytes(12));
    $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";
    $part = fn (string $type, string $content) => "--$boundary\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($content));
    $message = $part('text/plain', $text) . $part('text/html', $html) . "--$boundary--\r\n";
    $command('DATA', [354]);
    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $message . ".\r\n");
    $command(null, [250]);
    $command('QUIT', [221]);
    fclose($socket);
}
