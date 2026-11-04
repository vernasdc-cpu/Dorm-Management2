<?php
// scripts/mail_test.php — Verify outgoing mail settings from the CLI.
// Usage (host):      php scripts/mail_test.php you@example.com
// Usage (docker):    docker exec heart-dorm-app php /var/www/html/scripts/mail_test.php you@example.com

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/helpers.php';

$to = trim((string)($argv[1] ?? ''));
if ($to === '') {
    fwrite(STDERR, "Usage: php scripts/mail_test.php recipient@example.com\n");
    exit(2);
}

$host = trim((string)envv('MAIL_HOST', ''));
$port = (string)envv('MAIL_PORT', '');
$enc  = (string)envv('MAIL_ENCRYPTION', '');
$from = (string)envv('MAIL_FROM', '');

echo "Transport : " . ($host !== '' ? "SMTP {$host}:{$port} ({$enc})" : 'PHP mail() (no MAIL_HOST set)') . "\n";
echo "From      : {$from}\n";
echo "To        : {$to}\n";

$isLocalCatcher = in_array(strtolower($host), ['mailpit', 'localhost', '127.0.0.1'], true) && $port === '1025';
if ($isLocalCatcher) {
    echo "\nNOTE: MAIL_HOST is the local Mailpit catcher. Messages are stored in Mailpit\n";
    echo "      (http://localhost:8025) and are NEVER delivered to a real inbox.\n";
    echo "      Point MAIL_HOST/MAIL_USER/MAIL_PASS at a real SMTP provider to deliver mail.\n";
}

$error = null;
$sent = send_app_mail($to, 'Heart Dorm mail test ' . date('Y-m-d H:i:s'), "This is a test message from the dormitory system.\r\n", $error);

echo "\nResult    : " . ($sent ? 'ACCEPTED by the mail server' : 'FAILED') . "\n";
if ($error) echo "Error     : {$error}\n";
if ($sent && $isLocalCatcher) echo "Open http://localhost:8025 to read the captured message.\n";

exit($sent ? 0 : 1);
