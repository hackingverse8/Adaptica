<?php
// api/chat_history.php
header('Content-Type: application/json');

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($k, $v) = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v));
    }
}

$taskId = (int)($_GET['task_id'] ?? 0);
if (!$taskId) { echo json_encode(['messages' => []]); exit; }

try {
    $pdo = new PDO(
        "mysql:host=" . (getenv('DB_HOST') ?: '127.0.0.1') . ";dbname=" . (getenv('DB_NAME') ?: 'ai_sentinel') . ";charset=utf8mb4",
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) { echo json_encode(['messages' => []]); exit; }

$stmt = $pdo->prepare("SELECT role, message, created_at FROM task_conversations WHERE task_id = ? ORDER BY id ASC LIMIT 100");
$stmt->execute([$taskId]);
echo json_encode(['messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);