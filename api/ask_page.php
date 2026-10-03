<?php
// api/ask_page.php — Chat with a completed task's page
header('Content-Type: application/json');

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($k, $v) = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v));
    }
}

$apiKey = getenv('GEMINI_API_KEY');
if (!$apiKey) { echo json_encode(['error' => 'Missing GEMINI_API_KEY']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$taskId   = (int)($input['task_id'] ?? 0);
$question = trim($input['question'] ?? '');

if (!$taskId || !$question) { echo json_encode(['error' => 'Missing task_id or question']); exit; }

try {
    $pdo = new PDO(
        "mysql:host=" . (getenv('DB_HOST') ?: '127.0.0.1') . ";dbname=" . (getenv('DB_NAME') ?: 'ai_sentinel') . ";charset=utf8mb4",
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) { echo json_encode(['error' => 'db']); exit; }

$stmt = $pdo->prepare("
    SELECT task_name, target_url, action_intent, page_context, result_summary, status
    FROM automation_tasks WHERE id = ?
");
$stmt->execute([$taskId]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) { echo json_encode(['error' => 'Task not found']); exit; }
if ($task['status'] !== 'completed' || !$task['page_context']) {
    echo json_encode(['error' => 'Chat is only available after a task completes successfully.']);
    exit;
}

$stmt = $pdo->prepare("SELECT role, message FROM task_conversations WHERE task_id = ? ORDER BY id ASC LIMIT 30");
$stmt->execute([$taskId]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pdo->prepare("INSERT INTO task_conversations (task_id, role, message) VALUES (?, 'user', ?)")
    ->execute([$taskId, $question]);

$ctx = substr($task['page_context'], 0, 25000);
$origAnswer = $task['result_summary'] ?: '(none)';

$systemPrompt = "You are an assistant helping the user understand a webpage that an AI automation bot just visited.\n\n"
    . "TASK: {$task['task_name']}\n"
    . "TARGET URL: {$task['target_url']}\n"
    . "USER'S ORIGINAL GOAL: {$task['action_intent']}\n"
    . "ORIGINAL ANSWER THE BOT PRODUCED: {$origAnswer}\n\n"
    . "CAPTURED PAGE CONTENT (after login):\n{$ctx}\n\n"
    . "RULES:\n"
    . "- Answer based on the captured page content above.\n"
    . "- If the user asks about the original goal, use the ORIGINAL ANSWER as ground truth.\n"
    . "- If the answer isn't in the content, say so honestly.\n"
    . "- Be concise and specific. Quote real numbers/names from the page.";

$contents = [];
foreach ($history as $h) {
    $contents[] = [
        'role' => $h['role'] === 'user' ? 'user' : 'model',
        'parts' => [['text' => $h['message']]]
    ];
}
$contents[] = ['role' => 'user', 'parts' => [['text' => $question]]];

$models = ['gemini-3.5-flash-lite', 'gemini-3.8-flash'];
$answer = null;
$lastErr = null;

foreach ($models as $model) {
    $payload = [
        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'generationConfig' => ['temperature' => 0.6, 'maxOutputTokens' => 1024],
    ];

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 45,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($code === 200 && isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        $answer = $data['candidates'][0]['content']['parts'][0]['text'];
        break;
    }
    $lastErr = $data['error']['message'] ?? ('HTTP ' . $code);
}

if (!$answer) {
    echo json_encode(['error' => 'AI unavailable: ' . ($lastErr ?: 'unknown')]);
    exit;
}

$pdo->prepare("INSERT INTO task_conversations (task_id, role, message) VALUES (?, 'assistant', ?)")
    ->execute([$taskId, $answer]);

echo json_encode(['answer' => $answer, 'role' => 'assistant']);
