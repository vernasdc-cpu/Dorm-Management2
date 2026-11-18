<?php
require_once __DIR__ . '/../config/database.php';
if (APP_ENV !== 'testing' || getenv('ANNOUNCEMENT_MIGRATION_TEST') !== '1') {
    throw new RuntimeException('Run only against a disposable test database.');
}
$pdo = db();
if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn() !== 0) {
    throw new RuntimeException('The test database must be empty.');
}
$pdo->exec('CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL)');
$pdo->exec("INSERT INTO users (name) VALUES ('Test author')");
$sql = file_get_contents(__DIR__ . '/../database/30-announcements.sql');
if ($sql === false) throw new RuntimeException('Could not read the migration.');
$migrate = static function () use ($pdo, $sql): void {
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) $pdo->exec($statement);
};
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$migrate();
$pdo->exec("INSERT INTO announcements (title,message,author_id,audience,is_published,published_at) VALUES ('Test','Message',1,'all',1,NOW())");
$migrate();
$assert((int)$pdo->query('SELECT COUNT(*) FROM announcements WHERE is_published=1')->fetchColumn() === 1, 'Fresh migration did not preserve the announcement.');
$pdo->exec('INSERT INTO announcement_reads (announcement_id,user_id) VALUES (1,1)');

$pdo->exec('DROP TABLE announcement_reads');
$pdo->exec('DROP TABLE announcements');
$pdo->exec("CREATE TABLE announcements (
    id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(160) NOT NULL, message TEXT NOT NULL,
    author_id INT NULL, audience ENUM('all','residents','specific') NOT NULL DEFAULT 'all',
    target_roles TEXT NULL, is_published TINYINT(1) NOT NULL DEFAULT 0,
    important_deadline DATE NULL, attachment_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at TIMESTAMP NULL
)");
$pdo->exec("INSERT INTO announcements (title,message,audience) VALUES ('Existing','Message','specific')");
$migrate();
$migrate();
$pdo->exec("INSERT INTO announcements (title,message,audience,target_floor) VALUES ('Floor','Message','floor','3rd')");
$assert((int)$pdo->query("SELECT COUNT(*) FROM announcements WHERE title='Existing' AND audience='specific'")->fetchColumn() === 1, 'Legacy announcement was changed.');
$assert((int)$pdo->query("SELECT COUNT(*) FROM announcements WHERE audience='floor' AND target_floor='3rd'")->fetchColumn() === 1, 'Floor audience was not added.');
$assert((int)$pdo->query('SELECT COUNT(*) FROM announcement_reads')->fetchColumn() === 0, 'Announcement reads table is unavailable.');
echo "Announcement migration tests passed.\n";
