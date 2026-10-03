<?php
// api/live_status.php — Polled by dashboard every 2s
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v));
    }
}

try {
    $pdo = new PDO(
        "mysql:host=" . (getenv('DB_HOST') ?: '127.0.0.1') .
        ";dbname=" . (getenv('DB_NAME') ?: 'ai_sentinel') .
        ";charset=utf8mb4",
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo json_encode([
        'running' => [], 'pending' => [], 'stats' => [],
        'recent_done' => [], 'recent_all' => [], 'error' => 'db'
    ]);
    exit;
}

try {
    $running = $pdo->query("
        SELECT t.id, t.task_name, t.target_url, t.started_at,
            TIMESTAMPDIFF(SECOND, t.started_at, NOW()) AS elapsed_seconds,
            (SELECT message    FROM task_logs WHERE task_id = t.id ORDER BY id DESC LIMIT 1) AS last_message,
            (SELECT log_type   FROM task_logs WHERE task_id = t.id ORDER BY id DESC LIMIT 1) AS last_type,
            (SELECT image_path FROM task_logs WHERE task_id = t.id AND image_path IS NOT NULL ORDER BY id DESC LIMIT 1) AS last_screenshot
        FROM automation_tasks t
        WHERE t.status = 'running'
        ORDER BY t.started_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $running = []; }

try {
    $pending = $pdo->query("
        SELECT id, task_name, target_url, execution_time
        FROM automation_tasks WHERE status='pending'
        ORDER BY execution_time ASC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $pending = []; }

$stats = ['running' => 0, 'pending' => 0, 'completed' => 0, 'failed' => 0, 'total' => 0];
try {
    foreach ($pdo->query("SELECT status, COUNT(*) c FROM automation_tasks GROUP BY status") as $row) {
        $stats[$row['status']] = (int)$row['c'];
        $stats['total'] += (int)$row['c'];
    }
} catch (PDOException $e) {}

try {
    $recentDone = $pdo->query("
        SELECT id, task_name, status, result_summary, finished_at
        FROM automation_tasks
        WHERE status IN ('completed','failed') AND finished_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ORDER BY finished_at DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $recentDone = []; }

try {
    $recentAll = $pdo->query("
        SELECT id, task_name, target_url, execution_time, status, result_summary
        FROM automation_tasks ORDER BY id DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $recentAll = []; }

echo json_encode([
    'running' => $running,
    'pending' => $pending,
    'stats' => $stats,
    'recent_done' => $recentDone,
    'recent_all' => $recentAll,
    'server_time' => date('c'),
]);