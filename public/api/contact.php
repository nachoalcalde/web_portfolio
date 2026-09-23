<?php
/**
 * Contact endpoint for the enquiry flow in src/pages/contact.astro.
 *
 * It lives in public/, so `astro build` copies it to dist/api/contact.php and
 * it uploads with everything else to public_html. Hostinger runs it as PHP from
 * the same origin as the page: no CORS, no third party, no key in the browser.
 *
 * It accepts one JSON POST, checks it, and sends it.
 *
 * Sending goes through authenticated SMTP when the credentials file is there,
 * and falls back to the server's own mailer when it is not. That is not a
 * preference: mail() leaves from the web server, which the domain's SPF does
 * not authorise, and Gmail files it as spam with an "via srv...main-hosting.eu"
 * note. Through SMTP the message leaves from the mail servers SPF names and is
 * signed, which is what keeps it in the inbox.
 *
 * The credentials live in a file one level above public_html, out of reach of
 * the web server and out of the repository. See smtp_config().
 */

declare(strict_types=1);

// A warning printed into the response would make it unparseable as JSON, and
// the page would show the error screen over a message that did go out.
@ini_set('display_errors', '0');

/** Where the messages land. */
const TO = 'ialcaldecid@gmail.com';

/**
 * The only pages allowed to post here. Browsers send Origin on every POST, so
 * a form on someone else's site cannot use visitors' browsers to post in bulk.
 */
const ALLOWED_ORIGINS = ['https://nachoalcaldecid.com', 'https://www.nachoalcaldecid.com'];

/** Named in EHLO. Fixed, because SERVER_NAME can echo the request's Host. */
const SITE_HOST = 'nachoalcaldecid.com';

/**
 * Fallback sender, used only when there are no SMTP credentials. With them,
 * the sender is the mailbox that authenticates, because a From: that does not
 * match the mailbox is the misalignment the whole exercise is about.
 */
const FROM = 'no-reply@nachoalcaldecid.com';

/**
 * What shows as the sender in the inbox. Presentation, not a credential, so it
 * lives here and the credentials file has no say in it: with no name at all,
 * the mail client falls back to printing the raw mailbox address.
 */
const FROM_NAME = 'Web Portfolio';

/** Where the credentials file is looked for, relative to public_html. */
const SECRETS_FILE = 'contact-secrets.php';

const SMTP_HOST = 'smtp.hostinger.com';
const SMTP_PORT = 465;
const SMTP_TIMEOUT = 15;

const MAX_BODY_BYTES = 20000;
/** Nobody reads five questions and answers them in three seconds. */
const MIN_ELAPSED_MS = 3000;
const RATE_LIMIT = 5;
const RATE_WINDOW_SECONDS = 3600;
/**
 * Across every address at once. The per-address limit does nothing against
 * many addresses; this caps what they can put in the inbox in a day.
 */
const GLOBAL_LIMIT = 40;
const GLOBAL_WINDOW_SECONDS = 86400;

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** @return void */
function respond(int $status, array $body)
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

/**
 * Silent success. Used for the traps: a bot that is told it failed tries
 * again with the trap removed, and one that is told it worked goes away.
 *
 * @return void
 */
function pretend_ok()
{
    // Shaped and timed like a real send, or the difference is the tell.
    usleep(random_int(500000, 900000));
    respond(200, ['ok' => true, 'via' => 'smtp']);
}

/** @return void */
function fail(int $status, string $error)
{
    respond($status, ['ok' => false, 'error' => $error]);
}

function cut(string $value, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

function field(array $data, string $key, int $max): string
{
    $value = isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';

    // Invalid UTF-8 is dropped rather than passed on to a header encoder.
    if (preg_match('//u', $value) !== 1) {
        return '';
    }

    // Control characters have no business in an answer; line breaks and tabs
    // stay, and one_line() takes the breaks out where a header needs it.
    $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}]/u', '', $value);

    return cut(trim($value), $max);
}

/** Headers are line-based: a newline in a value is an injected header. */
function one_line(string $value): string
{
    return trim((string) preg_replace('/[\r\n\t\x{2028}\x{2029}]+/u', ' ', $value));
}

function has_non_ascii(string $value): bool
{
    return preg_match('/[\x80-\xFF]/', $value) === 1;
}

/** Plain ASCII travels as it is; anything else goes as an encoded word. */
function encode_header(string $value): string
{
    if (!has_non_ascii($value)) {
        return $value;
    }

    return function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($value, 'UTF-8', 'B')
        : '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/**
 * One bucket of timestamps per key, in the system temp dir so nothing writable
 * ends up under the web root. The file stays locked from the read to the
 * write, or a burst of parallel requests would all read the same count and
 * all get through.
 */
function over_limit(string $key, int $limit, int $window): bool
{
    $path = sys_get_temp_dir() . '/portfolio-contact-' . hash('sha256', $key) . '.json';
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        // Better to let a message through than to lock everyone out because
        // the temp dir is full or read-only.
        return false;
    }

    flock($handle, LOCK_EX);
    $now = time();
    $stored = json_decode((string) stream_get_contents($handle), true);
    $hits = array_values(array_filter(is_array($stored) ? $stored : [], function ($seen) use ($now, $window) {
        return is_int($seen) && $seen > $now - $window;
    }));

    $limited = count($hits) >= $limit;
    if (!$limited) {
        $hits[] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($hits));
        fflush($handle);
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return $limited;
}

/**
 * Every folder the credentials file is accepted in: the one holding
 * public_html, and the two above it, which is what the hPanel file manager
 * shows as the account's home depending on where you started. All of them are
 * outside the web root, which is the part that matters.
 *
 * @return string[]
 */
function secrets_candidates(): array
{
    $root = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($root === '') {
        return [];
    }

    $paths = [];
    $folder = $root;
    for ($up = 0; $up < 3; $up++) {
        $folder = dirname($folder);
        if ($folder === '' || $folder === '/' || $folder === '.') {
            break;
        }
        $paths[] = $folder . '/' . SECRETS_FILE;
    }

    $home = (string) getenv('HOME');
    if ($home !== '') {
        $paths[] = $home . '/' . SECRETS_FILE;
    }

    return array_values(array_unique($paths));
}

/**
 * Reads the mailbox credentials from the first candidate folder that has them.
 * The file returns an array:
 *
 *     <?php return ['user' => 'buzon@nachoalcaldecid.com', 'pass' => '…'];
 *
 * Without it, or with it half filled in, sending falls back to mail().
 *
 * @return array<string,mixed>|null
 */
function smtp_config()
{
    $path = null;
    foreach (secrets_candidates() as $candidate) {
        if (is_readable($candidate)) {
            $path = $candidate;
            break;
        }
    }

    if ($path === null) {
        return null;
    }

    $config = require $path;
    if (!is_array($config) || empty($config['user']) || empty($config['pass'])) {
        return null;
    }

    // The sender name is overwritten rather than defaulted: an empty one left
    // in the credentials file would print the mailbox address in the inbox.
    return ['name' => FROM_NAME] + $config + [
        'host' => SMTP_HOST,
        'port' => SMTP_PORT,
    ];
}

/** The display name of an address, quoted or encoded as the header needs. */
function address(string $name, string $email): string
{
    if ($name === '') {
        return $email;
    }

    // An encoded word must stand bare; a plain name has to be quoted, or a
    // comma in it would read as a second address.
    $display = has_non_ascii($name)
        ? encode_header($name)
        : '"' . str_replace(['"', '\\'], '', $name) . '"';

    return $display . ' <' . $email . '>';
}

/**
 * The message as it goes on the wire, headers and all. SMTP needs the whole
 * thing; mail() takes the headers apart from the body, hence the two pieces.
 *
 * @return array{0:string,1:string} headers and body, both CRLF
 */
function compose(string $from, string $fromName, string $subject, string $body, string $replyTo): array
{
    $headers = implode("\r\n", [
        'From: ' . address($fromName, $from),
        'To: ' . TO,
        'Reply-To: ' . $replyTo,
        'Subject: ' . encode_header($subject),
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . substr(strrchr($from, '@') ?: '@local', 1) . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ]);

    return [$headers, encode_body($body)];
}

/**
 * The body as base64 in 76-character CRLF lines. Nothing the visitor typed
 * reaches the wire as it is, so no line of theirs can be too long for a mail
 * server, start with a dot, or end the SMTP transaction early.
 */
function encode_body(string $body): string
{
    $body = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $body);

    return rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
}

/**
 * Reads one reply. It can span several lines, and only the last one has a
 * space after the code: that is what says the server has finished talking.
 *
 * @param resource $socket
 */
function smtp_read($socket): string
{
    $reply = '';
    while (($line = fgets($socket, 1024)) !== false) {
        $reply .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    return $reply;
}

/** @param resource $socket */
function smtp_say($socket, string $command): string
{
    fwrite($socket, $command . "\r\n");

    return smtp_read($socket);
}

/**
 * Connects and authenticates, and writes down every step it got through so a
 * failure says where it stopped rather than just "no".
 *
 * @param array<string,string> $trace
 * @return resource|null
 */
function smtp_connect(array $config, array &$trace)
{
    $errno = 0;
    $errstr = '';
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $socket = @stream_socket_client(
        'ssl://' . $config['host'] . ':' . $config['port'],
        $errno,
        $errstr,
        SMTP_TIMEOUT,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        $trace['connect'] = 'failed: ' . $errstr . ' (' . $errno . ')';

        return null;
    }

    stream_set_timeout($socket, SMTP_TIMEOUT);
    // Every step is the reply's three-digit code, which is the whole story and
    // never carries the password.
    $steps = [
        'greeting' => [null, '220'],
        'ehlo' => ['EHLO ' . SITE_HOST, '250'],
        'auth' => ['AUTH LOGIN', '334'],
        'user' => [base64_encode((string) $config['user']), '334'],
        'pass' => [base64_encode((string) $config['pass']), '235'],
    ];

    foreach ($steps as $name => $step) {
        list($command, $expected) = $step;
        $reply = $command === null ? smtp_read($socket) : smtp_say($socket, $command);
        $trace[$name] = substr(trim($reply), 0, 3);

        if (strpos($reply, $expected) !== 0) {
            $trace['stopped_at'] = $name;
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);

            return null;
        }
    }

    return $socket;
}

/**
 * A small SMTP client: connect, authenticate, hand over one message. Written
 * here rather than pulled in as a library so the endpoint stays one file that
 * uploads with the rest of the site.
 *
 * @param array<string,string> $trace
 */
function smtp_send(array $config, string $subject, string $body, string $replyTo, array &$trace = []): bool
{
    $socket = smtp_connect($config, $trace);
    if ($socket === null) {
        return false;
    }

    $ok = strpos(smtp_say($socket, 'MAIL FROM:<' . $config['user'] . '>'), '250') === 0;
    if ($ok) {
        $ok = strpos(smtp_say($socket, 'RCPT TO:<' . TO . '>'), '250') === 0;
    }
    if ($ok) {
        $ok = strpos(smtp_say($socket, 'DATA'), '354') === 0;
    }

    if ($ok) {
        list($headers, $text) = compose(
            (string) $config['user'],
            (string) $config['name'],
            $subject,
            $body,
            $replyTo
        );

        fwrite($socket, $headers . "\r\n\r\n" . $text . "\r\n.\r\n");
        $ok = strpos(smtp_read($socket), '250') === 0;
    }

    $trace['delivery'] = $ok ? 'accepted' : 'refused';

    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);

    return $ok;
}

/**
 * Returns how the message went out: 'smtp', 'mail', or '' when it did not go
 * at all. The endpoint reports it back, so a message that quietly fell back to
 * the web server can be told apart from one that went out properly without
 * reading the headers of whatever arrived.
 */
function send_message(string $subject, string $body, string $name, string $email): string
{
    $replyTo = address($name, $email);

    $config = smtp_config();
    if ($config !== null && smtp_send($config, $subject, $body, $replyTo)) {
        return 'smtp';
    }

    // Either there are no credentials or the mail server would not take it.
    // Delivering through the web server is worse, not nothing: it arrives, it
    // just tends to arrive in spam.
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'From: ' . address(FROM_NAME, FROM),
        'Reply-To: ' . $replyTo,
    ]);

    // -f sets the envelope sender, which is the address SPF is checked against.
    // Some hosts refuse the fifth argument outright, hence the second attempt.
    // mail() wants plain LF between lines on Linux.
    $encoded = str_replace("\r\n", "\n", encode_body($body));
    $sent = @mail(TO, encode_header($subject), $encoded, $headers, '-f' . FROM);
    if (!$sent) {
        $sent = @mail(TO, encode_header($subject), $encoded, $headers);
    }

    return $sent ? 'mail' : '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    fail(405, 'Method not allowed');
}

// A request that says where it comes from has to come from here. Sec-Fetch-Site
// covers browsers that leave Origin out; scripts can forge both, which is what
// the rate limits are for.
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
if (($origin !== '' && !in_array($origin, ALLOWED_ORIGINS, true))
    || ($site !== '' && $site !== 'same-origin')) {
    fail(403, 'Forbidden');
}

// Only JSON. A form elsewhere can post text/plain across sites without asking;
// application/json makes the browser ask first, and nothing here says yes.
$type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($type, 'application/json') !== 0) {
    fail(415, 'Unsupported content type');
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_BODY_BYTES) {
    fail(413, 'Too large');
}

$raw = (string) file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
if ($raw === '') {
    fail(400, 'Empty request');
}
if (strlen($raw) > MAX_BODY_BYTES) {
    fail(413, 'Too large');
}

$data = json_decode($raw, true, 4);
if (!is_array($data)) {
    fail(400, 'Malformed request');
}

if (field($data, 'trap', 100) !== '') {
    pretend_ok();
}

$elapsed = isset($data['elapsed']) && is_numeric($data['elapsed']) ? (int) $data['elapsed'] : 0;
if ($elapsed < MIN_ELAPSED_MS) {
    pretend_ok();
}

$name = one_line(field($data, 'name', 120));
$email = one_line(field($data, 'email', 254));
$organisation = one_line(field($data, 'organisation', 160));
$message = field($data, 'message', 5000);

$topics = [];
if (isset($data['topics']) && is_array($data['topics'])) {
    foreach (array_slice($data['topics'], 0, 10) as $topic) {
        if (is_string($topic) && trim($topic) !== '') {
            $topics[] = one_line(cut(trim($topic), 80));
        }
    }
}

if ($name === '' || $message === '' || $topics === []) {
    fail(422, 'Missing answers');
}
// FILTER_VALIDATE_EMAIL still lets through quoted local parts and the like;
// a reply address has no need for anything past the plain shape.
if (!filter_var($email, FILTER_VALIDATE_EMAIL)
    || preg_match('/^[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Za-z0-9.-]+$/', $email) !== 1) {
    fail(422, 'That email is not valid');
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
if (over_limit('ip:' . $ip, RATE_LIMIT, RATE_WINDOW_SECONDS)) {
    fail(429, 'Too many messages from here');
}
if (over_limit('all', GLOBAL_LIMIT, GLOBAL_WINDOW_SECONDS)) {
    fail(429, 'Too many messages today');
}

// The message goes first because the inbox preview is the first line of the
// body: with the labels up there, every enquiry previews as "Name: … Email: …"
// and they all look the same. Who wrote it reads underneath, as a signature.
$when = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('j M Y, H:i');

$body = implode("\n", [
    $message,
    '',
    '--',
    $name . ' · ' . $email,
    $organisation !== '' ? $organisation : 'No organisation given',
    'About: ' . implode(', ', $topics),
    '',
    $when . ' · nachoalcaldecid.com/contact · IP ' . $ip,
]);

$subject = 'Portfolio contact — ' . cut($name, 60)
    . ($organisation !== '' ? ' (' . cut($organisation, 40) . ')' : '');

$via = send_message($subject, $body, $name, $email);
if ($via === '') {
    fail(502, 'The mailer refused it');
}

respond(200, ['ok' => true, 'via' => $via]);
