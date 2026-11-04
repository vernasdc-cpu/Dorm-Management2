<?php
// Load .env (no composer dependency)
function loadEnv(string $path): void {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        [$k, $v] = [trim($parts[0]), trim($parts[1])];
        $v = trim($v, "\"' ");
        if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
    }
}
loadEnv(__DIR__ . '/../.env');

function envv(string $key, $default = null) {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

define('APP_ENV', envv('APP_ENV', 'production'));
define('APP_URL', rtrim(envv('APP_URL', ''), '/'));
define('APP_KEY', envv('APP_KEY', 'change-me'));

define('DB_HOST', envv('DB_HOST', 'localhost'));
define('DB_PORT', envv('DB_PORT', '3306'));
define('DB_NAME', envv('DB_NAME', 'heart_dorm_db'));
define('DB_USER', envv('DB_USER', 'root'));
define('DB_PASS', envv('DB_PASS', ''));

define('UPLOAD_MAX_MB', 5);
define('ALLOWED_DOC_EXT', ['pdf','doc','docx','jpg','jpeg','png']);
define('ALLOWED_IMG_EXT', ['jpg','jpeg','png','webp']);
