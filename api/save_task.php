<?php
// api/save_task.php
header('Content-Type: application/json');

// 1. Load the .env file variables manually (so we don't need Composer for PHP)
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue; // Skip comments
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}

// 2. Database Connection using ENV variables
$host = getenv('DB_HOST') ?: '127.0.0.1';
$db   = getenv('DB_NAME') ?: 'ai_sentinel';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Database connection failed."]);
    exit;
}

// 3. Process the incoming form data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task_name = $_POST['task_name'] ?? 'Untitled Task';
    $target_url = $_POST['target_url'] ?? '';
    $login_id = $_POST['login_id'] ?? '';
    $raw_password = $_POST['password'] ?? '';
    $action_intent = $_POST['action_intent'] ?? '';
    $execution_time = $_POST['execution_time'] ?? date('Y-m-d H:i:s');

    // 4. Encrypt the password using AES-256-CBC
    $encryption_key = getenv('ADAPTICA_SECRET_KEY');
    
    if (!$encryption_key) {
        echo json_encode(["status" => "error", "message" => "Server configuration error: Missing encryption key."]);
        exit;
    }

    // Ensure the key is exactly 32 bytes for aes-256
    $encryption_key = substr(hash('sha256', $encryption_key, true), 0, 32); 
    
    // Generate a secure random Initialization Vector (IV)
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    
    // Encrypt the password
    $encrypted_pass = openssl_encrypt($raw_password, 'aes-256-cbc', $encryption_key, 0, $iv);
    
    // Combine the encrypted password and the base64-encoded IV, then encode the whole string
    // Node.js will decode this payload, split it by '::', and decrypt it later.
    $vault_payload = base64_encode($encrypted_pass . '::' . base64_encode($iv));

    // 5. Save securely to MySQL
    try {
        $stmt = $pdo->prepare("INSERT INTO automation_tasks 
            (task_name, target_url, login_id, encrypted_password, action_intent, execution_time, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        
        $stmt->execute([
            $task_name, $target_url, $login_id, $vault_payload, $action_intent, $execution_time
        ]);

        echo json_encode([
            "status" => "success", 
            "message" => "Credentials vaulted securely.", 
            "task_id" => $pdo->lastInsertId()
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            "status" => "error", 
            "message" => "Failed to save task to database."
        ]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method. Expected POST."]);
}
?>