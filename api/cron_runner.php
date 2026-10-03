<?php
// api/cron_runner.php

// 1. Load the .env file manually
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$db   = getenv('DB_NAME') ?: 'ai_sentinel';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed.\n");
}

// 2. Find pending tasks whose time has arrived
$stmt = $pdo->prepare("SELECT id FROM automation_tasks WHERE status = 'pending' AND execution_time <= NOW()");
$stmt->execute();
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($tasks)) {
    echo "No pending tasks to execute right now.\n";
    exit;
}

foreach ($tasks as $task) {
    $task_id = $task['id'];

    // 3. Lock the task (change status to 'running')
    $updateStmt = $pdo->prepare("UPDATE automation_tasks SET status = 'running' WHERE id = ?");
    $updateStmt->execute([$task_id]);

    // 4. Trigger Node.js passing ONLY the Task ID
    $node_script = realpath(__DIR__ . '/../engine/run_task.js');
    
    // Command to run Node asynchronously in the background (Linux/Mac style for Fedora)
    $command = "node \"$node_script\" $task_id > /dev/null 2>&1 &";
    shell_exec($command);

    echo "Fired Task ID: $task_id to Node.js engine.\n";
}
?>