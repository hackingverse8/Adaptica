<?php
// api/task_detail.php
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($k, $v) = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v));
    }
}

$taskId = (int)($_GET['id'] ?? 0);
if (!$taskId) { echo json_encode(['error' => 'missing_id']); exit; }

try {
    $pdo = new PDO(
        "mysql:host=" . (getenv('DB_HOST') ?: '127.0.0.1') . ";dbname=" . (getenv('DB_NAME') ?: 'ai_sentinel') . ";charset=utf8mb4",
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) { echo json_encode(['error' => 'db']); exit; }

$stmt = $pdo->prepare("
    SELECT id, task_name, target_url, login_id, status, action_intent,
           result_summary, execution_time, started_at, finished_at,
           page_screenshot, pending_action, approval_state
    FROM automation_tasks WHERE id = ?
");
$stmt->execute([$taskId]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$task) { echo json_encode(['error' => 'not_found']); exit; }

$stmt = $pdo->prepare("
    SELECT id, log_type, message, image_path, created_at
    FROM task_logs WHERE task_id = ? ORDER BY id ASC LIMIT 500
");
$stmt->execute([$taskId]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['task' => $task, 'logs' => $logs]);