<?php
// dashboard.php — Professional 2D Bold Design
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($k, $v) = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v));
    }
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$db   = getenv('DB_NAME') ?: 'ai_sentinel';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$SCREENSHOTS_URL = getenv('SCREENSHOTS_URL') ?: '/storage/screenshots';

$stats = ['total'=>0,'running'=>0,'pending'=>0,'completed'=>0,'failed'=>0,'success_rate'=>0];
$recent_tasks = [];
$trend_labels = []; $trend_data = [];

for ($i = 13; $i >= 0; $i--) {
    $trend_labels[] = date('d M', strtotime("-$i days"));
    $trend_data[date('Y-m-d', strtotime("-$i days"))] = 0;
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    foreach ($pdo->query("SELECT status, COUNT(*) c FROM automation_tasks GROUP BY status") as $row) {
        $stats[$row['status']] = (int)$row['c'];
        $stats['total'] += (int)$row['c'];
    }
    $done = $stats['completed'] + $stats['failed'];
    if ($done > 0) $stats['success_rate'] = round(($stats['completed'] / $done) * 100);

    $recent_tasks = $pdo->query("SELECT id, task_name, target_url, execution_time, status, result_summary FROM automation_tasks ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT DATE(execution_time) d, COUNT(*) c FROM automation_tasks WHERE execution_time >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) GROUP BY DATE(execution_time)");
    foreach ($stmt as $row) if (isset($trend_data[$row['d']])) $trend_data[$row['d']] = (int)$row['c'];

} catch (PDOException $e) {}

$final_trend_data = array_values($trend_data);
$status_chart_data = [$stats['completed'], $stats['pending'], $stats['failed'], $stats['running']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Adaptica — Automation Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
tailwind.config = {
    theme: {
        extend: {
            fontFamily: { sans: ['Inter','sans-serif'] },
            colors: {
                ink: { 50:'#f7f8fa', 100:'#eef0f4', 200:'#e1e4ea', 300:'#c8cdd6', 400:'#9aa2b1', 500:'#6b7280', 600:'#4b5563', 700:'#374151', 800:'#1f2937', 900:'#111827' },
                brand: { red:'#c92a42', redHover:'#a82338', redLight:'#fdf2f4' }
            }
        }
    }
}
</script>
<style>
    * { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
    body { background: #f7f8fa; color: #111827; }

    .card-flat {
        background: #ffffff;
        border: 1.5px solid #e1e4ea;
        border-radius: 12px;
        transition: border-color 0.15s ease;
    }
    .card-flat:hover { border-color: #c8cdd6; }
    .card-interactive { cursor: pointer; }
    .card-interactive:hover { border-color: #111827; }

    .icon-tile {
        width: 44px; height: 44px;
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        color: #fff;
    }

    .modal-input {
        width: 100%; padding: 0.75rem 1rem;
        border: 1.5px solid #e1e4ea; border-radius: 10px;
        font-size: 0.875rem; background: #f7f8fa;
        transition: all 0.15s; color: #111827; font-weight: 500;
    }
    .modal-input:focus { outline: none; border-color: #111827; background: #ffffff; }
    .modal-input::placeholder { color: #9aa2b1; font-weight: 400; }

    .badge {
        padding: 0.3rem 0.7rem; border-radius: 6px;
        font-size: 0.68rem; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.05em;
        display: inline-flex; align-items: center; gap: 0.3rem;
        border: 1.5px solid transparent;
    }
    .badge-pending   { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .badge-running   { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
    .badge-completed { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
    .badge-failed    { background: #fee2e2; color: #991b1b; border-color: #fecaca; }

    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #c8cdd6; border-radius: 6px; }
    ::-webkit-scrollbar-thumb:hover { background: #9aa2b1; }

    @keyframes pulse-dot { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
    .pulse-dot { animation: pulse-dot 1.5s ease-in-out infinite; }

    @keyframes slide-in { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
    .slide-in { animation: slide-in 0.3s ease-out; }

    @keyframes chat-pop { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
    .chat-pop { animation: chat-pop 0.25s ease-out; }

    @keyframes dots { 0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; } 40% { transform: scale(1); opacity: 1; } }
    .typing-dot { animation: dots 1.2s infinite; }
    .typing-dot:nth-child(2) { animation-delay: 0.15s; }
    .typing-dot:nth-child(3) { animation-delay: 0.3s; }

    .suggestion-chip { transition: all 0.15s ease; }
    .suggestion-chip:hover { border-color: #111827; background: #f7f8fa; }

    .gallery-nav { transition: all 0.15s ease; }
    .gallery-nav:hover:not(:disabled) { background: #c92a42; border-color: #c92a42; }
    .gallery-nav:disabled { opacity: 0.3; cursor: not-allowed; }

    .tab-active { color: #c92a42; border-color: #c92a42; }
    .tab-inactive { color: #6b7280; border-color: transparent; }
    .tab-inactive:hover { color: #111827; }

    .chat-bubble strong { font-weight: 800; color: #111827; }
    .chat-bubble em { font-style: italic; color: #374151; }
    .chat-bubble code { background: #eef0f4; padding: 1px 6px; border-radius: 4px; font-family: ui-monospace, monospace; font-size: 0.85em; color: #c92a42; font-weight: 600; }
    .chat-bubble ul, .chat-bubble ol { padding-left: 1.25rem; margin: 6px 0; }
    .chat-bubble li { margin: 3px 0; }
    .chat-bubble p { margin: 6px 0; }
    .chat-bubble p:first-child { margin-top: 0; }
    .chat-bubble p:last-child { margin-bottom: 0; }

    .scroll-area {
        flex: 1 1 0% !important;
        min-height: 0 !important;
        overflow-y: auto !important;
        overscroll-behavior: contain;
    }

    .log-line { padding: 4px 8px; border-radius: 6px; }
    .log-line:hover { background: #f7f8fa; }

    .status-strip {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px 12px; border-radius: 8px;
        font-size: 11px; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.05em;
        border: 1.5px solid;
    }
    .status-idle   { background: #f7f8fa; color: #6b7280; border-color: #e1e4ea; }
    .status-active { background: #fee2e2; color: #991b1b; border-color: #fecaca; }

    .dot { width: 8px; height: 8px; border-radius: 50%; }

    /* Left panel (screenshot area) — soft background to separate from right */
    .modal-left-panel { background: #eef0f4; }

    /* Image wrapper — dark stage with rounded corners */
    .image-stage {
        background: #0f1320;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.15), inset 0 0 60px rgba(0,0,0,0.35);
    }
</style>
</head>
<body class="min-h-screen py-8 lg:py-10">

<div class="max-w-[1440px] mx-auto px-6 sm:px-10 lg:px-16 xl:px-20">

    <header class="mb-8 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-5">
        <img src="logo.png" alt="Adaptica" class="h-10 w-auto"
             onerror="this.onerror=null; this.outerHTML='<h1 class=\'text-3xl font-black tracking-tight text-ink-900\'>Adaptica</h1>';">

        <div class="flex items-center gap-3 flex-wrap w-full lg:w-auto">
            <div id="liveIndicator" class="status-strip status-idle">
                <span class="dot bg-ink-400"></span> <span id="liveLabel">Idle</span>
            </div>
            <button onclick="openModal()" class="bg-ink-900 hover:bg-black text-white rounded-[10px] px-5 py-2.5 text-sm font-extrabold flex items-center gap-2 transition-all active:scale-[0.98] uppercase tracking-wide">
                <i class="fa-solid fa-plus"></i> New Task
            </button>
        </div>
    </header>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="card-flat p-5">
            <div class="flex items-start justify-between mb-4">
                <div class="icon-tile" style="background:#c92a42;"><i class="fa-solid fa-layer-group"></i></div>
                <span class="text-[10px] font-extrabold text-ink-400 uppercase tracking-widest">All Time</span>
            </div>
            <div class="text-4xl font-black text-ink-900 tracking-tight" id="kpiTotal"><?= $stats['total'] ?></div>
            <div class="text-xs font-bold text-ink-500 mt-1.5">Total tasks</div>
        </div>

        <div class="card-flat p-5">
            <div class="flex items-start justify-between mb-4">
                <div class="icon-tile" style="background:#2563eb;"><i class="fa-solid fa-bolt"></i></div>
                <span class="text-[10px] font-extrabold text-ink-400 uppercase tracking-widest">Live</span>
            </div>
            <div class="text-4xl font-black text-ink-900 tracking-tight" id="kpiRunning"><?= $stats['running'] ?></div>
            <div class="text-xs font-bold text-ink-500 mt-1.5">Running now</div>
        </div>

        <div class="card-flat p-5">
            <div class="flex items-start justify-between mb-4">
                <div class="icon-tile" style="background:#059669;"><i class="fa-solid fa-check"></i></div>
                <span class="text-[10px] font-extrabold text-ink-400 uppercase tracking-widest">Done</span>
            </div>
            <div class="text-4xl font-black text-ink-900 tracking-tight" id="kpiCompleted"><?= $stats['completed'] ?></div>
            <div class="text-xs font-bold text-ink-500 mt-1.5">Successful</div>
        </div>

        <div class="card-flat p-5">
            <div class="flex items-start justify-between mb-4">
                <div class="icon-tile" style="background:#d97706;"><i class="fa-solid fa-star"></i></div>
                <span class="text-[10px] font-extrabold text-ink-400 uppercase tracking-widest">Rate</span>
            </div>
            <div class="text-4xl font-black text-ink-900 tracking-tight"><?= $stats['success_rate'] ?><span class="text-2xl text-ink-400">%</span></div>
            <div class="text-xs font-bold text-ink-500 mt-1.5">Success rate</div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div onclick="openModal()" class="card-flat card-interactive p-5 flex justify-between items-center group">
            <div class="flex items-center gap-4">
                <div class="icon-tile" style="background:#c92a42; width:52px; height:52px; border-radius:12px;">
                    <i class="fa-solid fa-plus text-lg"></i>
                </div>
                <div>
                    <h3 class="font-black text-lg text-ink-900 leading-tight">Add New Task</h3>
                    <p class="text-sm text-ink-500 font-medium mt-0.5">Tell the AI what to do online</p>
                </div>
            </div>
            <i class="fa-solid fa-arrow-right text-ink-300 group-hover:text-ink-900 group-hover:translate-x-1 transition-all"></i>
        </div>

        <div onclick="scrollToHistory()" class="card-flat card-interactive p-5 flex justify-between items-center group">
            <div class="flex items-center gap-4">
                <div class="icon-tile" style="background:#111827; width:52px; height:52px; border-radius:12px;">
                    <i class="fa-solid fa-clock-rotate-left text-lg"></i>
                </div>
                <div>
                    <h3 class="font-black text-lg text-ink-900 leading-tight">View History</h3>
                    <p class="text-sm text-ink-500 font-medium mt-0.5">Browse past tasks and results</p>
                </div>
            </div>
            <i class="fa-solid fa-arrow-down text-ink-300 group-hover:text-ink-900 group-hover:translate-y-1 transition-all"></i>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="card-flat p-5">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-red-500 pulse-dot"></span>
                    <h3 class="font-extrabold text-ink-900 text-sm uppercase tracking-wide">Live Now</h3>
                </div>
                <span class="text-xs font-black text-white bg-red-500 px-2.5 py-0.5 rounded-md" id="liveCount">0</span>
            </div>
            <div id="runningList" class="space-y-2.5">
                <div class="text-center text-xs text-ink-400 py-8">
                    <i class="fa-solid fa-satellite-dish text-3xl mb-2 block opacity-30"></i>
                    <span class="font-bold">Nothing running</span>
                </div>
            </div>
        </div>

        <div class="card-flat p-5">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2.5">
                    <i class="fa-solid fa-hourglass-half text-amber-500"></i>
                    <h3 class="font-extrabold text-ink-900 text-sm uppercase tracking-wide">Up Next</h3>
                </div>
                <span class="text-xs font-black text-white bg-amber-500 px-2.5 py-0.5 rounded-md" id="pendingCount">0</span>
            </div>
            <div id="pendingList" class="space-y-2">
                <div class="text-center text-xs text-ink-400 py-8">
                    <i class="fa-solid fa-hourglass text-3xl mb-2 block opacity-30"></i>
                    <span class="font-bold">Queue is empty</span>
                </div>
            </div>
        </div>

        <div class="card-flat p-5">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2.5">
                    <i class="fa-solid fa-flag-checkered text-emerald-600"></i>
                    <h3 class="font-extrabold text-ink-900 text-sm uppercase tracking-wide">Just Finished</h3>
                </div>
                <span class="text-xs font-black text-white bg-emerald-500 px-2.5 py-0.5 rounded-md" id="doneCount">0</span>
            </div>
            <div id="resultsList" class="space-y-2">
                <div class="text-center text-xs text-ink-400 py-8">
                    <i class="fa-solid fa-check-double text-3xl mb-2 block opacity-30"></i>
                    <span class="font-bold">Nothing yet</span>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="lg:col-span-2 card-flat p-6">
            <div class="flex items-start justify-between mb-5">
                <div>
                    <h3 class="font-black text-ink-900 text-lg">Activity Trend</h3>
                    <p class="text-xs text-ink-500 font-medium mt-0.5">Tasks scheduled over the last 14 days</p>
                </div>
                <span class="px-3 py-1 rounded-lg bg-brand-redLight text-brand-red text-[10px] font-extrabold uppercase tracking-widest border border-rose-100">14D</span>
            </div>
            <div class="relative h-[240px]"><canvas id="trendChart"></canvas></div>
        </div>

        <div class="card-flat p-6">
            <h3 class="font-black text-ink-900 text-lg">Status</h3>
            <p class="text-xs text-ink-500 font-medium mt-0.5 mb-4">Current distribution</p>
            <div class="relative w-full h-[200px]"><canvas id="pieStatus"></canvas></div>
        </div>
    </div>

    <div id="recentSection" class="card-flat p-6 mb-6 scroll-mt-6">
        <div class="flex justify-between items-center mb-5">
            <div>
                <h3 class="font-black text-ink-900 text-lg">Recent Activity</h3>
                <p class="text-xs text-ink-500 font-medium mt-0.5">Click any row to view its replay</p>
            </div>
            <span class="text-[10px] font-extrabold text-emerald-700 flex items-center gap-1.5 uppercase tracking-widest bg-emerald-50 border border-emerald-200 px-3 py-1 rounded-lg">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 pulse-dot"></span>
                Live
            </span>
        </div>
        <div class="space-y-2" id="historyList">
            <?php if (empty($recent_tasks)): ?>
                <div class="text-center p-8 text-ink-400 bg-ink-50 rounded-xl border-2 border-dashed border-ink-200" id="emptyHistoryMsg">
                    <i class="fa-regular fa-folder-open text-3xl mb-2 block"></i>
                    <div class="font-bold">No tasks yet — click "New Task" to begin</div>
                </div>
            <?php else: foreach ($recent_tasks as $task):
                $sc = 'badge-pending';
                if ($task['status']==='completed') $sc='badge-completed';
                if ($task['status']==='failed')    $sc='badge-failed';
                if ($task['status']==='running')   $sc='badge-running';
            ?>
            <div class="group flex items-center justify-between p-3.5 hover:bg-ink-50 rounded-lg border border-transparent hover:border-ink-200 transition cursor-pointer" onclick="openLivePreview(<?= (int)$task['id'] ?>)">
                <div class="flex items-center gap-3.5 min-w-0">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:#111827; color:#fff;">
                        <i class="fa-solid fa-robot text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="font-bold text-sm text-ink-900 truncate"><?= htmlspecialchars($task['task_name']) ?></div>
                        <div class="text-xs text-ink-500 flex gap-3 mt-0.5 flex-wrap font-medium">
                            <span><i class="fa-solid fa-link text-ink-400 mr-1"></i><?= htmlspecialchars(parse_url($task['target_url'], PHP_URL_HOST) ?? '') ?></span>
                            <span><i class="fa-regular fa-clock text-ink-400 mr-1"></i><?= date('M d, H:i', strtotime($task['execution_time'])) ?></span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <span class="badge <?= $sc ?>"><?= ucfirst($task['status']) ?></span>
                    <i class="fa-solid fa-chevron-right text-ink-300 group-hover:text-ink-900 group-hover:translate-x-0.5 transition"></i>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="mt-8 pb-4 text-center text-xs text-ink-400 font-bold">
        Adaptica · <span id="footerClock"><?= date('H:i:s') ?></span>
    </div>
</div>

<div id="deployModal" class="fixed inset-0 bg-ink-900/50 backdrop-blur-sm z-50 hidden flex justify-center items-center opacity-0 transition-opacity duration-200 p-4">
    <div class="bg-white rounded-2xl w-full max-w-2xl overflow-hidden transform scale-95 transition-transform duration-200 border border-ink-200" id="modalContent" style="box-shadow: 0 24px 48px -12px rgba(0,0,0,0.25);">
        <div class="px-6 py-5 border-b border-ink-100 flex justify-between items-center bg-ink-50">
            <div class="flex items-center gap-3">
                <div class="icon-tile" style="background:#c92a42; width:44px; height:44px; border-radius:10px;">
                    <i class="fa-solid fa-robot"></i>
                </div>
                <div>
                    <h2 class="text-lg font-black text-ink-900">New Task</h2>
                    <p class="text-xs text-ink-500 font-medium">Tell the AI what to do online</p>
                </div>
            </div>
            <button onclick="closeModal()" class="text-ink-400 hover:text-ink-900 hover:bg-ink-100 p-2.5 rounded-lg transition"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="p-6 max-h-[70vh] overflow-y-auto">
            <form id="taskForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-[10px] font-extrabold text-ink-500 uppercase tracking-widest mb-2">Task Name</label>
                    <input type="text" name="task_name" class="modal-input" placeholder="e.g., Delete test user" required>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-[10px] font-extrabold text-ink-500 uppercase tracking-widest mb-2">Target URL</label>
                    <input type="url" name="target_url" class="modal-input" placeholder="https://example.com/login" required>
                </div>
                <div>
                    <label class="block text-[10px] font-extrabold text-ink-500 uppercase tracking-widest mb-2">Username</label>
                    <input type="text" name="login_id" class="modal-input" placeholder="user@company.com" required>
                </div>
                <div>
                    <label class="block text-[10px] font-extrabold text-ink-500 uppercase tracking-widest mb-2">Password</label>
                    <div class="relative">
                        <input type="password" id="passwordInput" name="password" class="modal-input pr-10" placeholder="••••••••" required>
                        <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 px-3 text-ink-400 hover:text-brand-red flex items-center"><i class="fa-regular fa-eye"></i></button>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-[10px] font-extrabold text-ink-500 uppercase tracking-widest mb-2">When should this run?</label>
                    <input type="text" name="execution_time" id="execTime" class="modal-input bg-white cursor-pointer" placeholder="Select date & time" required>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-[10px] font-extrabold text-ink-500 uppercase tracking-widest mb-2">
                        What should the bot do? <span class="text-brand-red normal-case font-bold">— anything, it will figure it out</span>
                    </label>
                    <textarea name="action_intent" rows="3" class="modal-input resize-none" placeholder="e.g., Log in, search for john@example.com, and delete that user." required></textarea>
                </div>
                <div class="md:col-span-2 pt-2">
                    <button type="submit" id="submitBtn" class="w-full bg-ink-900 hover:bg-black text-white font-black py-3.5 px-4 rounded-xl transition-all flex justify-center items-center gap-2 active:scale-[0.99] uppercase tracking-wide text-sm">
                        <i class="fa-solid fa-lock"></i> Save & Schedule Task
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="liveModal" class="fixed inset-0 bg-ink-900/90 backdrop-blur-sm z-50 hidden opacity-0 transition-opacity duration-200">
    <div class="w-full h-full p-3 sm:p-5 lg:p-6">
        <div class="bg-white rounded-2xl w-full h-full flex flex-col overflow-hidden transform scale-95 transition-transform duration-200 border border-ink-200" id="liveModalContent">

            <div class="px-5 sm:px-7 py-4 border-b border-ink-100 bg-white shrink-0">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="relative flex h-2.5 w-2.5 shrink-0">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-500 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-sm sm:text-base font-black text-ink-900 truncate" id="liveTitle">Task Replay</h2>
                            <p class="text-[11px] text-ink-500 truncate font-semibold" id="liveSubtitle">Live execution view</p>
                        </div>
                        <span class="badge ml-2" id="liveBadge">—</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button onclick="copyTaskLink()" class="text-ink-400 hover:text-ink-900 hover:bg-ink-100 p-2.5 rounded-lg transition" title="Copy link">
                            <i class="fa-solid fa-link text-sm"></i>
                        </button>
                        <button onclick="closeLivePreview()" class="text-ink-400 hover:text-ink-900 hover:bg-ink-100 p-2.5 rounded-lg transition">
                            <i class="fa-solid fa-xmark text-lg"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- BODY: LEFT (light gray bg) | RIGHT (white bg) -->
            <div class="flex-1 grid grid-cols-1 lg:grid-cols-[1.35fr_1fr] overflow-hidden min-h-0">

                <!-- LEFT COLUMN: gray background, bordered edge -->
                <div class="modal-left-panel flex flex-col overflow-hidden border-r-2 border-ink-200 min-h-0">

                    <!-- IMAGE: takes remaining space, centered, with all-side margins -->
                    <div class="flex-1 min-h-0 p-5 flex items-center justify-center">
                        <div class="image-stage relative w-full h-full">

                            <img id="liveScreenshot" src="" class="hidden w-full h-full object-contain" alt="Live screenshot" onerror="handleImageError(this)">

                            <div id="liveScreenshotEmpty" class="absolute inset-0 flex flex-col items-center justify-center p-6">
                                <div class="w-16 h-16 mb-3 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center">
                                    <i class="fa-regular fa-image text-2xl text-ink-400"></i>
                                </div>
                                <p class="text-ink-200 text-sm font-bold">Waiting for screenshot…</p>
                                <p class="text-ink-500 text-xs mt-1 font-medium">The next capture will appear here automatically</p>
                            </div>

                            <button id="galleryPrev" class="gallery-nav absolute left-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-ink-900/80 border border-white/20 text-white flex items-center justify-center z-10" onclick="galleryPrev()" title="Previous (←)">
                                <i class="fa-solid fa-chevron-left text-sm"></i>
                            </button>
                            <button id="galleryNext" class="gallery-nav absolute right-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-ink-900/80 border border-white/20 text-white flex items-center justify-center z-10" onclick="galleryNext()" title="Next (→)">
                                <i class="fa-solid fa-chevron-right text-sm"></i>
                            </button>

                            <div id="galleryCounter" class="hidden absolute bottom-3 left-1/2 -translate-x-1/2 px-3 py-1.5 rounded-lg bg-ink-900/90 border border-white/10 text-xs text-white font-bold z-10">
                                <span id="galleryIndex">1</span> / <span id="galleryTotal">1</span>
                                <span id="galleryLabel" class="text-ink-400 ml-2 font-medium"></span>
                            </div>

                            <div id="screenshotToolbar" class="hidden absolute top-3 right-3 flex gap-1.5 z-10">
                                <button onclick="expandScreenshot()" class="px-2.5 py-1.5 rounded-md bg-ink-900/80 hover:bg-ink-900 text-white text-[10px] font-bold border border-white/10 transition uppercase tracking-wide">
                                    <i class="fa-solid fa-expand mr-1"></i> Expand
                                </button>
                                <a id="screenshotDownloadBtn" href="#" download class="px-2.5 py-1.5 rounded-md bg-ink-900/80 hover:bg-ink-900 text-white text-[10px] font-bold border border-white/10 transition uppercase tracking-wide">
                                    <i class="fa-solid fa-download mr-1"></i> Save
                                </a>
                            </div>

                            <div id="liveUrlChip" class="hidden absolute top-3 left-3 px-2.5 py-1.5 rounded-md bg-ink-900/80 border border-white/10 text-[11px] text-ink-200 font-mono max-w-[55%] truncate z-10"></div>
                        </div>
                    </div>

                    <!-- AI Reasoning card -->
                    <div class="shrink-0 px-5 pb-3">
                        <div class="rounded-xl border-[1.5px] border-ink-200 bg-white px-4 py-3 shadow-sm">
                            <div class="flex items-center gap-2 mb-1.5">
                                <i class="fa-solid fa-brain text-purple-500 text-xs"></i>
                                <div class="text-[10px] font-extrabold text-ink-500 uppercase tracking-widest">AI Reasoning</div>
                            </div>
                            <div id="liveReasoning" class="text-sm text-ink-700 leading-relaxed chat-bubble font-medium line-clamp-3">—</div>
                        </div>
                    </div>

                    <!-- Answer card (only when present) -->
                    <div id="liveAnswerBanner" class="shrink-0 px-5 pb-5 hidden">
                        <div class="rounded-xl border-[1.5px] border-emerald-200 bg-emerald-50 px-4 py-3 shadow-sm">
                            <div class="flex items-center gap-2 mb-1.5">
                                <i class="fa-solid fa-lightbulb text-emerald-600 text-xs"></i>
                                <div class="text-[10px] font-extrabold text-emerald-700 uppercase tracking-widest">Answer</div>
                            </div>
                            <div id="liveAnswerText" class="text-sm text-ink-800 leading-relaxed chat-bubble font-medium"></div>
                        </div>
                    </div>

                </div>

                <!-- RIGHT COLUMN: white background -->
                <div class="flex flex-col overflow-hidden bg-white" style="min-height:0; height:100%;">

                    <div class="flex border-b border-ink-200 bg-white shrink-0">
                        <button id="tabInfoBtn" onclick="switchPreviewTab('info')" class="flex-1 py-3.5 text-xs font-black uppercase tracking-wider border-b-2 tab-active">
                            <i class="fa-solid fa-circle-info mr-1"></i> Info
                        </button>
                        <button id="tabLogBtn" onclick="switchPreviewTab('log')" class="flex-1 py-3.5 text-xs font-black uppercase tracking-wider border-b-2 tab-inactive">
                            <i class="fa-solid fa-list-ul mr-1"></i> Activity
                        </button>
                        <button id="tabChatBtn" onclick="switchPreviewTab('chat')" class="flex-1 py-3.5 text-xs font-black uppercase tracking-wider border-b-2 tab-inactive">
                            <i class="fa-solid fa-comments mr-1"></i> Ask AI
                        </button>
                    </div>

                    <div id="paneInfo" class="scroll-area px-5 py-5 text-sm"></div>
                    <div id="paneLog" class="scroll-area px-4 py-3 space-y-0.5 text-xs font-mono" style="display:none;"></div>

                    <div id="paneChat" class="scroll-area flex-col bg-ink-50" style="display:none;">
                        <div class="px-5 py-3 bg-white border-b border-ink-100 flex items-center gap-3 shrink-0">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center shrink-0" style="background:#c92a42; color:#fff;">
                                <i class="fa-solid fa-robot text-xs"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-xs font-black text-ink-900">Page Assistant</div>
                                <div class="text-[10px] text-emerald-600 flex items-center gap-1 font-bold">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Online
                                </div>
                            </div>
                        </div>

                        <div id="chatMessages" class="flex-1 overflow-y-auto px-5 py-5 space-y-4 bg-white" style="min-height:0;"></div>

                        <div class="border-t border-ink-100 p-4 bg-white shrink-0">
                            <div class="flex gap-2 items-end">
                                <div class="flex-1 relative">
                                    <textarea id="chatInput" rows="1" placeholder="Ask about this page…" disabled
                                        class="w-full px-4 py-3 pr-10 text-sm border-[1.5px] border-ink-200 rounded-xl focus:outline-none focus:border-ink-900 disabled:bg-ink-50 disabled:cursor-not-allowed resize-none transition font-medium"
                                        style="max-height:120px;"></textarea>
                                    <div class="absolute right-3 bottom-3 text-[10px] text-ink-400 pointer-events-none font-bold">⏎</div>
                                </div>
                                <button id="chatSendBtn" onclick="sendChat()" disabled
                                    class="text-white px-5 py-3 rounded-xl transition shrink-0 font-bold" style="background:#c92a42;">
                                    <i class="fa-solid fa-paper-plane text-sm"></i>
                                </button>
                            </div>
                            <div id="chatHint" class="text-[10px] text-ink-400 mt-2 px-1 font-bold">Available after the bot finishes.</div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<div id="screenshotLightbox" class="fixed inset-0 bg-ink-900/97 z-[60] hidden opacity-0 transition-opacity duration-200 flex items-center justify-center p-4" onclick="closeLightbox(event)">
    <img id="lightboxImg" src="" class="max-w-full max-h-full object-contain rounded-lg" onclick="event.stopPropagation()">

    <button id="lightboxPrev" class="gallery-nav absolute left-6 top-1/2 -translate-y-1/2 w-14 h-14 rounded-full bg-white/10 hover:bg-brand-red border border-white/20 text-white flex items-center justify-center z-10" onclick="event.stopPropagation(); galleryPrev();" title="Previous (←)">
        <i class="fa-solid fa-chevron-left text-lg"></i>
    </button>
    <button id="lightboxNext" class="gallery-nav absolute right-6 top-1/2 -translate-y-1/2 w-14 h-14 rounded-full bg-white/10 hover:bg-brand-red border border-white/20 text-white flex items-center justify-center z-10" onclick="event.stopPropagation(); galleryNext();" title="Next (→)">
        <i class="fa-solid fa-chevron-right text-lg"></i>
    </button>

    <div class="absolute bottom-6 left-1/2 -translate-x-1/2 px-4 py-2 rounded-lg bg-white/10 backdrop-blur border border-white/20 text-sm text-white font-bold">
        <span id="lightboxIndex">1</span> / <span id="lightboxTotal">1</span>
    </div>

    <button class="absolute top-6 right-6 text-white hover:bg-white/10 p-3 rounded-lg transition border border-white/20" onclick="event.stopPropagation(); closeLightbox();">
        <i class="fa-solid fa-xmark text-2xl"></i>
    </button>

    <div class="absolute top-6 left-6 px-3 py-2 rounded-lg bg-white/10 backdrop-blur border border-white/20 text-xs text-white font-bold">
        <i class="fa-solid fa-keyboard mr-1"></i> ← → to navigate · Esc to close
    </div>
</div>

<script>
const $ = (id) => document.getElementById(id);
const SCREENSHOTS_URL_BASE = <?= json_encode($SCREENSHOTS_URL) ?>;
function escapeHtml(str=''){ return String(str).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function inlineMd(text) {
    return text
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*\n]+?)\*(?!\*)/g, '$1<em>$2</em>')
        .replace(/`([^`\n]+?)`/g, '<code>$1</code>');
}
function renderMarkdown(text) {
    if (!text) return '';
    let s = escapeHtml(text);
    const lines = s.split('\n');
    const out = [];
    let listBuffer = [], listType = null;
    const flushList = () => {
        if (!listBuffer.length) return;
        const tag = listType === 'ol' ? 'ol' : 'ul';
        out.push(`<${tag}>${listBuffer.join('')}</${tag}>`);
        listBuffer = []; listType = null;
    };
    for (const rawLine of lines) {
        const bullet = rawLine.match(/^\s*[-*•]\s+(.+)$/);
        const numbered = rawLine.match(/^\s*\d+\.\s+(.+)$/);
        if (bullet) {
            if (listType && listType !== 'ul') flushList();
            listType = 'ul'; listBuffer.push(`<li>${inlineMd(bullet[1])}</li>`);
        } else if (numbered) {
            if (listType && listType !== 'ol') flushList();
            listType = 'ol'; listBuffer.push(`<li>${inlineMd(numbered[1])}</li>`);
        } else {
            flushList();
            if (rawLine.trim() === '') out.push('<div class="h-1.5"></div>');
            else out.push(`<p>${inlineMd(rawLine)}</p>`);
        }
    }
    flushList();
    return out.join('');
}

function fixScreenshotUrl(raw) {
    if (!raw) return '';
    const filename = raw.split('/').pop();
    if (!filename) return raw;
    if (raw.startsWith(SCREENSHOTS_URL_BASE + '/')) return raw;
    const pagePath = window.location.pathname;
    const pageDir = pagePath.substring(0, pagePath.lastIndexOf('/'));
    if (SCREENSHOTS_URL_BASE.startsWith('/')) {
        if (SCREENSHOTS_URL_BASE.startsWith(pageDir + '/')) return SCREENSHOTS_URL_BASE + '/' + filename;
        return pageDir + '/' + SCREENSHOTS_URL_BASE.replace(/^\/+/, '') + '/' + filename;
    }
    return pageDir + '/' + SCREENSHOTS_URL_BASE + '/' + filename;
}
function handleImageError(img) {
    const current = img.getAttribute('src');
    if (!current) return;
    if (img.dataset.retried === '1') {
        img.classList.add('hidden');
        $('liveScreenshotEmpty').classList.remove('hidden');
        return;
    }
    img.dataset.retried = '1';
    const fixed = fixScreenshotUrl(current);
    if (fixed && fixed !== current) img.setAttribute('src', fixed);
}

const modal = $('deployModal'), modalContent = $('modalContent');
function openModal(){ modal.classList.remove('hidden'); setTimeout(()=>{ modal.classList.remove('opacity-0'); modalContent.classList.remove('scale-95'); modalContent.classList.add('scale-100'); },10); }
function closeModal(){ modal.classList.add('opacity-0'); modalContent.classList.remove('scale-100'); modalContent.classList.add('scale-95'); setTimeout(()=>modal.classList.add('hidden'),200); }
modal.addEventListener('click', e=>{ if (e.target===modal) closeModal(); });

document.addEventListener('DOMContentLoaded', () => {
    flatpickr("#execTime", { enableTime:true, dateFormat:"Y-m-d H:i:00", defaultDate:new Date(Date.now()+2*60000), minDate:"today", time_24hr:true });
    $('togglePassword').addEventListener('click', function(){
        const p=$('passwordInput'); const t=p.getAttribute('type')==='password'?'text':'password';
        p.setAttribute('type',t);
        this.innerHTML = t==='password' ? '<i class="fa-regular fa-eye"></i>' : '<i class="fa-regular fa-eye-slash text-brand-red"></i>';
    });
    const ci = $('chatInput');
    if (ci) {
        ci.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); } });
        ci.addEventListener('input', () => { ci.style.height = 'auto'; ci.style.height = Math.min(ci.scrollHeight, 120) + 'px'; });
    }
    setInterval(() => { $('footerClock').textContent = new Date().toLocaleTimeString(); }, 1000);
});

$('taskForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target, btn = $('submitBtn'), original = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Saving...'; btn.disabled = true;
    try {
        const res = await fetch('api/save_task.php', { method:'POST', body:new FormData(form) });
        const result = await res.json();
        if (result.status === 'success') {
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Saved';
            btn.style.background = '#059669';
            setTimeout(()=>{ closeModal(); form.reset(); setTimeout(()=>{ btn.innerHTML=original; btn.style.background=''; btn.disabled=false; },200); refreshLive(); }, 800);
        } else { alert('Error: '+result.message); btn.innerHTML=original; btn.disabled=false; }
    } catch { alert('Network error'); btn.innerHTML=original; btn.disabled=false; }
});

function statusBadgeClass(s) {
    if (s === 'completed') return 'badge-completed';
    if (s === 'failed')    return 'badge-failed';
    if (s === 'running')   return 'badge-running';
    return 'badge-pending';
}

let lastHistorySignature = '';
function renderHistory(tasks) {
    const list = $('historyList');
    if (!tasks || tasks.length === 0) {
        list.innerHTML = '<div class="text-center p-8 text-ink-400 bg-ink-50 rounded-xl border-2 border-dashed border-ink-200"><i class="fa-regular fa-folder-open text-3xl mb-2 block"></i><div class="font-bold">No tasks yet.</div></div>';
        lastHistorySignature = ''; return;
    }
    const sig = tasks.map(t => `${t.id}:${t.status}:${(t.result_summary||'').slice(0,40)}`).join('|');
    if (sig === lastHistorySignature) return;
    lastHistorySignature = sig;
    list.innerHTML = tasks.map(t => {
        const sc = statusBadgeClass(t.status);
        let host = ''; try { host = new URL(t.target_url).hostname; } catch(_) {}
        const timeStr = t.execution_time ? new Date(t.execution_time.replace(' ','T')).toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'}) : '';
        return `<div class="group flex items-center justify-between p-3.5 hover:bg-ink-50 rounded-lg border border-transparent hover:border-ink-200 transition cursor-pointer slide-in" onclick="openLivePreview(${t.id})">
            <div class="flex items-center gap-3.5 min-w-0">
                <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:#111827; color:#fff;">
                    <i class="fa-solid fa-robot text-sm"></i>
                </div>
                <div class="min-w-0">
                    <div class="font-bold text-sm text-ink-900 truncate">${escapeHtml(t.task_name)}</div>
                    <div class="text-xs text-ink-500 flex gap-3 mt-0.5 flex-wrap font-medium">
                        <span><i class="fa-solid fa-link text-ink-400 mr-1"></i>${escapeHtml(host)}</span>
                        <span><i class="fa-regular fa-clock text-ink-400 mr-1"></i>${timeStr}</span>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <span class="badge ${sc}">${t.status}</span>
                <i class="fa-solid fa-chevron-right text-ink-300 group-hover:text-ink-900 group-hover:translate-x-0.5 transition"></i>
            </div>
        </div>`;
    }).join('');
}

async function refreshLive() {
    try {
        const res = await fetch('api/live_status.php', { cache: 'no-store' });
        const data = await res.json();

        if (data.stats) {
            $('kpiRunning').textContent   = data.stats.running ?? 0;
            $('kpiCompleted').textContent = data.stats.completed ?? 0;
            $('kpiTotal').textContent     = data.stats.total ?? 0;
        }

        const runningCount = (data.running || []).length;
        const ind = $('liveIndicator');
        if (runningCount > 0) {
            ind.className = 'status-strip status-active';
            ind.innerHTML = '<span class="dot bg-red-500 pulse-dot"></span> <span>'+runningCount+' active</span>';
        } else {
            ind.className = 'status-strip status-idle';
            ind.innerHTML = '<span class="dot bg-ink-400"></span> <span>Idle</span>';
        }

        const rl = $('runningList');
        $('liveCount').textContent = runningCount;
        if (runningCount === 0) {
            rl.innerHTML = '<div class="text-center text-xs text-ink-400 py-8"><i class="fa-solid fa-satellite-dish text-3xl mb-2 block opacity-30"></i><span class="font-bold">Nothing running</span></div>';
        } else {
            rl.innerHTML = data.running.map(t => `
                <div class="border border-blue-200 bg-blue-50 rounded-lg p-3 cursor-pointer hover:border-blue-400 transition slide-in" onclick="openLivePreview(${t.id})">
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <div class="font-bold text-xs text-ink-900 truncate">${escapeHtml(t.task_name)}</div>
                        <span class="badge badge-running shrink-0">Live</span>
                    </div>
                    ${t.last_screenshot ? `<img src="${escapeHtml(fixScreenshotUrl(t.last_screenshot))}" class="w-full h-20 object-cover rounded-md border border-blue-200 mb-2" onerror="this.style.display='none'">` : ''}
                    <div class="text-[11px] text-blue-900 leading-snug line-clamp-2 font-medium">${escapeHtml(t.last_message || 'Starting...')}</div>
                    <div class="text-[10px] text-ink-500 mt-1.5 font-bold"><i class="fa-regular fa-clock mr-1"></i>${t.elapsed_seconds || 0}s</div>
                </div>`).join('');
        }

        const pl = $('pendingList');
        $('pendingCount').textContent = (data.pending || []).length;
        if (!data.pending || data.pending.length === 0) {
            pl.innerHTML = '<div class="text-center text-xs text-ink-400 py-8"><i class="fa-solid fa-hourglass text-3xl mb-2 block opacity-30"></i><span class="font-bold">Queue is empty</span></div>';
        } else {
            pl.innerHTML = data.pending.map(t => `
                <div class="border border-ink-200 rounded-lg p-3 hover:bg-ink-50 transition">
                    <div class="font-bold text-xs text-ink-900 truncate mb-1.5">${escapeHtml(t.task_name)}</div>
                    <div class="flex items-center justify-between text-[10px] text-ink-500 font-bold">
                        <span><i class="fa-regular fa-clock mr-1"></i>${new Date(t.execution_time).toLocaleString()}</span>
                        <span class="badge badge-pending">Queued</span>
                    </div>
                </div>`).join('');
        }

        const resEl = $('resultsList');
        const doneCount = (data.recent_done || []).length;
        $('doneCount').textContent = doneCount;
        if (doneCount === 0) {
            resEl.innerHTML = '<div class="text-center text-xs text-ink-400 py-8"><i class="fa-solid fa-check-double text-3xl mb-2 block opacity-30"></i><span class="font-bold">Nothing yet</span></div>';
        } else {
            resEl.innerHTML = data.recent_done.map(t => `
                <div class="border ${t.status==='completed'?'border-emerald-200 bg-emerald-50':'border-red-200 bg-red-50'} rounded-lg p-3 cursor-pointer hover:brightness-[0.97] transition" onclick="openLivePreview(${t.id})">
                    <div class="flex items-center justify-between gap-2 mb-1.5">
                        <div class="font-bold text-xs text-ink-900 truncate">${escapeHtml(t.task_name)}</div>
                        <span class="badge ${t.status==='completed'?'badge-completed':'badge-failed'} shrink-0">${t.status}</span>
                    </div>
                    <div class="text-[11px] text-ink-700 leading-snug line-clamp-2 font-medium">${escapeHtml((t.result_summary||'').slice(0,120))}</div>
                    <div class="text-[10px] text-ink-400 mt-1.5 font-bold">${t.finished_at ? new Date(t.finished_at).toLocaleTimeString() : ''}</div>
                </div>`).join('');
        }

        if (data.recent_all) renderHistory(data.recent_all);
    } catch (e) { /* silent */ }
}

let currentLiveTaskId = null;
let liveModalPollTimer = null;
let galleryScreenshots = [];
let galleryCurrentIndex = 0;
let galleryUserInteracted = false;
let chatUnlocked = false;   // NEW — tracks whether chat is unlocked for the current task

const liveModal = $('liveModal'), liveModalContent = $('liveModalContent');

function closeLivePreview() {
    liveModal.classList.add('opacity-0');
    liveModalContent.classList.remove('scale-100');
    liveModalContent.classList.add('scale-95');
    setTimeout(()=>liveModal.classList.add('hidden'),200);
    currentLiveTaskId = null;
    galleryScreenshots = []; galleryCurrentIndex = 0; galleryUserInteracted = false;
    chatUnlocked = false;
    if (liveModalPollTimer) { clearInterval(liveModalPollTimer); liveModalPollTimer = null; }
    switchPreviewTab('info');
}
liveModal.addEventListener('click', e=>{ if (e.target===liveModal) closeLivePreview(); });

document.addEventListener('keydown', e => {
    if (!$('screenshotLightbox').classList.contains('hidden')) {
        if (e.key === 'Escape') { e.preventDefault(); closeLightbox(); return; }
        if (e.key === 'ArrowLeft')  { e.preventDefault(); galleryPrev(); return; }
        if (e.key === 'ArrowRight') { e.preventDefault(); galleryNext(); return; }
        return;
    }
    if (!liveModal.classList.contains('hidden')) {
        if (e.key === 'Escape')     { e.preventDefault(); closeLivePreview(); return; }
        if (e.key === 'ArrowLeft')  { e.preventDefault(); galleryPrev(); return; }
        if (e.key === 'ArrowRight') { e.preventDefault(); galleryNext(); return; }
        return;
    }
    if (!modal.classList.contains('hidden')) {
        if (e.key === 'Escape') { e.preventDefault(); closeModal(); }
    }
});

async function openLivePreview(taskId) {
    currentLiveTaskId = taskId;
    galleryScreenshots = []; galleryCurrentIndex = 0; galleryUserInteracted = false;
    chatUnlocked = false;
    liveModal.classList.remove('hidden');
    setTimeout(()=>{ liveModal.classList.remove('opacity-0'); liveModalContent.classList.remove('scale-95'); liveModalContent.classList.add('scale-100'); },10);
    await refreshLiveModal(taskId);
    try {
        const tRes = await fetch('api/task_detail.php?id=' + taskId, { cache: 'no-store' });
        const tData = await tRes.json();
        const isCompleted = (tData.task?.status === 'completed');
        chatUnlocked = isCompleted;
        loadChatHistory(taskId, isCompleted);
        const st = tData.task?.status;
        switchPreviewTab((st === 'completed' || st === 'failed') ? 'info' : 'log');
    } catch (_) {
        switchPreviewTab('info');
    }
    if (liveModalPollTimer) clearInterval(liveModalPollTimer);
    liveModalPollTimer = setInterval(() => refreshLiveModal(taskId), 2000);
}

async function refreshLiveModal(taskId) {
    if (currentLiveTaskId !== taskId) return;
    try {
        const res = await fetch('api/task_detail.php?id='+taskId, { cache:'no-store' });
        const data = await res.json();
        if (data.error) return;

        $('liveTitle').textContent = data.task.task_name;
        $('liveSubtitle').textContent = (data.task.target_url || '').replace(/^https?:\/\//,'').split('/')[0];

        const badge = $('liveBadge');
        badge.textContent = data.task.status;
        badge.className = 'badge ' + statusBadgeClass(data.task.status);

        // FIX: unlock chat the moment the task completes (no page refresh needed)
        if (data.task.status === 'completed' && !chatUnlocked) {
            chatUnlocked = true;
            loadChatHistory(taskId, true);
        }

        const ansBanner = $('liveAnswerBanner');
        if (data.task.result_summary) {
            $('liveAnswerText').innerHTML = renderMarkdown(data.task.result_summary);
            ansBanner.classList.remove('hidden');
        } else {
            ansBanner.classList.add('hidden');
        }

        const newScreenshots = [];
        let reasoning = null;
        for (const e of data.logs) {
            if (e.log_type === 'reasoning') reasoning = e.message;
            if (e.image_path) newScreenshots.push({ url: e.image_path, label: e.message || 'capture', ts: e.created_at });
        }

        const prevLen = galleryScreenshots.length;
        galleryScreenshots = newScreenshots;

        if (galleryScreenshots.length > 0 && (!galleryUserInteracted || prevLen !== galleryScreenshots.length)) {
            if (!galleryUserInteracted) galleryCurrentIndex = galleryScreenshots.length - 1;
        }

        if (galleryCurrentIndex >= galleryScreenshots.length) galleryCurrentIndex = galleryScreenshots.length - 1;
        if (galleryCurrentIndex < 0) galleryCurrentIndex = 0;

        renderGallery();
        renderTaskInfo(data.task);
        $('liveReasoning').innerHTML = reasoning ? renderMarkdown(reasoning) : '—';

        const logEl = $('paneLog');
        logEl.innerHTML = data.logs.map(e => {
            const ts = new Date(e.created_at.replace(' ','T')).toLocaleTimeString([], {hour12:false});
            let icon = '•', cls = 'text-ink-600';
            if (e.log_type === 'status')     { icon='<i class="fa-solid fa-circle-info text-blue-500"></i>'; }
            if (e.log_type === 'action')     { icon='<i class="fa-solid fa-bolt text-amber-500"></i>'; }
            if (e.log_type === 'reasoning')  { icon='<i class="fa-solid fa-brain text-purple-500"></i>'; }
            if (e.log_type === 'screenshot') { icon='<i class="fa-solid fa-camera text-ink-400"></i>'; }
            if (e.log_type === 'error')      { icon='<i class="fa-solid fa-triangle-exclamation text-red-500"></i>'; cls='text-red-700'; }
            if (e.log_type === 'result')     { icon='<i class="fa-solid fa-flag-checkered text-emerald-500"></i>'; cls='text-emerald-700 font-semibold'; }
            return `<div class="log-line flex gap-2 items-start ${cls}"><span class="text-ink-400 shrink-0 font-semibold">${ts}</span><span class="shrink-0">${icon}</span><span class="break-words">${escapeHtml(e.message || '')}</span></div>`;
        }).join('');
        logEl.scrollTop = logEl.scrollHeight;
    } catch (e) { /* silent */ }
}

/* ---------- Task Info Renderer ---------- */
function copyText(text) {
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        const t = document.createElement('div');
        t.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 bg-ink-900 text-white text-xs px-4 py-2 rounded-lg z-[70] font-bold';
        t.textContent = '✓ Copied';
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 1200);
    });
}

function renderTaskInfo(t) {
    const box = $('paneInfo');
    if (!box || !t) return;

    const fmt = (iso) => {
        if (!iso) return '—';
        try {
            const d = new Date(String(iso).replace(' ', 'T'));
            return d.toLocaleString([], { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit' });
        } catch (_) { return iso; }
    };
    const fmtRel = (a, b) => {
        if (!a || !b) return '';
        try {
            const diff = (new Date(String(b).replace(' ', 'T')) - new Date(String(a).replace(' ', 'T'))) / 1000;
            if (diff < 0) return '';
            if (diff < 60) return `${Math.round(diff)}s`;
            if (diff < 3600) return `${Math.floor(diff/60)}m ${Math.round(diff%60)}s`;
            return `${Math.floor(diff/3600)}h ${Math.floor((diff%3600)/60)}m`;
        } catch (_) { return ''; }
    };

    const statusColor = ({
        completed: '#059669',
        failed: '#dc2626',
        running: '#2563eb',
        pending: '#d97706',
        awaiting_approval: '#d97706'
    }[t.status] || '#6b7280');

    const statusLabel = ({
        completed: 'Finished',
        failed: 'Failed',
        running: 'Running',
        pending: 'Queued',
        awaiting_approval: 'Awaiting approval'
    }[t.status] || t.status);

    const safeUser = escapeHtml(t.login_id || '').replace(/'/g, "\\'");
    const safeUrl  = escapeHtml(t.target_url || '').replace(/'/g, "\\'");

    box.innerHTML = `
        <div class="space-y-5">

            <div class="flex items-start justify-between gap-3 pb-4 border-b border-ink-100">
                <div class="min-w-0">
                    <div class="text-[10px] font-extrabold text-ink-500 uppercase tracking-widest mb-1">Task</div>
                    <div class="font-black text-lg text-ink-900 leading-tight">${escapeHtml(t.task_name || '—')}</div>
                </div>
                <span class="badge ${statusBadgeClass(t.status)} shrink-0 mt-4">${t.status}</span>
            </div>

            <div>
                <div class="text-[10px] font-extrabold text-ink-500 uppercase tracking-widest mb-2">
                    <i class="fa-solid fa-wand-magic-sparkles mr-1 text-brand-red"></i> Instruction / Prompt
                </div>
                <div class="bg-brand-redLight border-[1.5px] border-rose-100 rounded-xl p-4 text-sm text-ink-800 leading-relaxed font-medium">
                    ${escapeHtml(t.action_intent || '(no instruction)')}
                </div>
            </div>

            <div>
                <div class="text-[10px] font-extrabold text-ink-500 uppercase tracking-widest mb-2">
                    <i class="fa-solid fa-lock mr-1 text-blue-500"></i> Login Credentials
                </div>
                <div class="bg-ink-50 border-[1.5px] border-ink-200 rounded-xl p-4 space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-xs font-bold text-ink-500 uppercase tracking-wide">Username</div>
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="text-sm font-mono text-ink-900 truncate">${escapeHtml(t.login_id || '—')}</span>
                            ${t.login_id ? `<button onclick="copyText('${safeUser}')" class="text-ink-400 hover:text-brand-red transition p-1 shrink-0" title="Copy"><i class="fa-regular fa-copy text-xs"></i></button>` : ''}
                        </div>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-xs font-bold text-ink-500 uppercase tracking-wide">Password</div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-mono text-ink-900">••••••••••</span>
                            <span class="text-[9px] text-ink-400 font-bold italic uppercase tracking-wide">encrypted</span>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div class="text-[10px] font-extrabold text-ink-500 uppercase tracking-widest mb-2">
                    <i class="fa-solid fa-link mr-1 text-purple-500"></i> Target Website
                </div>
                <div class="bg-ink-50 border-[1.5px] border-ink-200 rounded-xl p-3 flex items-center justify-between gap-3">
                    <span class="text-xs font-mono text-ink-700 truncate" title="${escapeHtml(t.target_url || '')}">${escapeHtml(t.target_url || '—')}</span>
                    <div class="flex gap-1 shrink-0">
                        ${t.target_url ? `<button onclick="copyText('${safeUrl}')" class="text-ink-400 hover:text-brand-red transition p-1.5" title="Copy"><i class="fa-regular fa-copy text-xs"></i></button>` : ''}
                        ${t.target_url ? `<a href="${escapeHtml(t.target_url)}" target="_blank" class="text-ink-400 hover:text-brand-red transition p-1.5" title="Open in new tab"><i class="fa-solid fa-arrow-up-right-from-square text-xs"></i></a>` : ''}
                    </div>
                </div>
            </div>

            <div>
                <div class="text-[10px] font-extrabold text-ink-500 uppercase tracking-widest mb-3">
                    <i class="fa-solid fa-clock mr-1 text-amber-500"></i> Timeline
                </div>
                <div class="relative pl-6 space-y-5">

                    <div class="relative">
                        <div class="absolute -left-6 top-1 w-3.5 h-3.5 rounded-full border-2 border-white shadow-sm" style="background:#d97706;"></div>
                        <div class="absolute -left-[18px] top-[18px] w-0.5 h-[calc(100%+8px)] bg-ink-200"></div>
                        <div class="text-[10px] font-extrabold text-ink-400 uppercase tracking-widest mb-0.5">Scheduled</div>
                        <div class="text-sm font-bold text-ink-900">${fmt(t.execution_time)}</div>
                    </div>

                    <div class="relative">
                        <div class="absolute -left-6 top-1 w-3.5 h-3.5 rounded-full border-2 border-white shadow-sm" style="background:#2563eb;"></div>
                        ${t.finished_at ? `<div class="absolute -left-[18px] top-[18px] w-0.5 h-[calc(100%+8px)] bg-ink-200"></div>` : ''}
                        <div class="text-[10px] font-extrabold text-ink-400 uppercase tracking-widest mb-0.5">Started</div>
                        <div class="text-sm font-bold text-ink-900">
                            ${fmt(t.started_at)}
                            ${t.started_at && t.execution_time ? `<span class="text-xs text-ink-400 font-medium ml-2">+${fmtRel(t.execution_time, t.started_at)} after schedule</span>` : ''}
                        </div>
                    </div>

                    <div class="relative">
                        <div class="absolute -left-6 top-1 w-3.5 h-3.5 rounded-full border-2 border-white shadow-sm" style="background:${statusColor};"></div>
                        <div class="text-[10px] font-extrabold text-ink-400 uppercase tracking-widest mb-0.5">${statusLabel}</div>
                        <div class="text-sm font-bold text-ink-900">
                            ${t.finished_at ? fmt(t.finished_at) : (t.status === 'running' ? 'In progress…' : '—')}
                            ${t.finished_at && t.started_at ? `<span class="text-xs text-ink-400 font-medium ml-2">took ${fmtRel(t.started_at, t.finished_at)}</span>` : ''}
                        </div>
                    </div>
                </div>
            </div>

            ${t.result_summary ? `
            <div>
                <div class="text-[10px] font-extrabold text-ink-500 uppercase tracking-widest mb-2">
                    <i class="fa-solid fa-flag-checkered mr-1 text-emerald-500"></i> Result
                </div>
                <div class="bg-emerald-50 border-[1.5px] border-emerald-200 rounded-xl p-4 text-sm text-ink-800 leading-relaxed chat-bubble font-medium">
                    ${renderMarkdown(t.result_summary)}
                </div>
            </div>
            ` : ''}

        </div>
    `;
}

function renderGallery() {
    const img = $('liveScreenshot'), empty = $('liveScreenshotEmpty');
    const toolbar = $('screenshotToolbar'), counter = $('galleryCounter');
    const prev = $('galleryPrev'), next = $('galleryNext');
    const urlChip = $('liveUrlChip');

    if (galleryScreenshots.length === 0) {
        img.classList.add('hidden'); empty.classList.remove('hidden');
        toolbar.classList.add('hidden'); counter.classList.add('hidden');
        prev.classList.add('hidden'); next.classList.add('hidden');
        urlChip.classList.add('hidden'); return;
    }

    const shot = galleryScreenshots[galleryCurrentIndex];
    const fixedUrl = fixScreenshotUrl(shot.url);

    if (img.getAttribute('src') !== fixedUrl) {
        img.dataset.retried = '0';
        img.setAttribute('src', fixedUrl);
        $('screenshotDownloadBtn').href = fixedUrl;
    }

    img.classList.remove('hidden'); empty.classList.add('hidden');
    toolbar.classList.remove('hidden'); counter.classList.remove('hidden');
    urlChip.classList.remove('hidden');

    if (galleryScreenshots.length > 1) {
        prev.classList.remove('hidden'); next.classList.remove('hidden');
        prev.disabled = galleryCurrentIndex === 0;
        next.disabled = galleryCurrentIndex === galleryScreenshots.length - 1;
    } else {
        prev.classList.add('hidden'); next.classList.add('hidden');
    }

    $('galleryIndex').textContent = galleryCurrentIndex + 1;
    $('galleryTotal').textContent = galleryScreenshots.length;

    const label = shot.label || '';
    const labelEl = $('galleryLabel');
    if (label && !/^task_\d+/.test(label)) {
        labelEl.textContent = '· ' + label.slice(0, 40);
        labelEl.classList.remove('hidden');
    } else { labelEl.classList.add('hidden'); }

    urlChip.textContent = `Screenshot ${galleryCurrentIndex + 1} of ${galleryScreenshots.length}`;

    const lb = $('screenshotLightbox');
    if (!lb.classList.contains('hidden')) {
        $('lightboxImg').src = fixedUrl;
        $('lightboxIndex').textContent = galleryCurrentIndex + 1;
        $('lightboxTotal').textContent = galleryScreenshots.length;
        $('lightboxPrev').disabled = galleryCurrentIndex === 0;
        $('lightboxNext').disabled = galleryCurrentIndex === galleryScreenshots.length - 1;
        $('lightboxPrev').style.display = galleryScreenshots.length > 1 ? '' : 'none';
        $('lightboxNext').style.display = galleryScreenshots.length > 1 ? '' : 'none';
    }
}

function galleryPrev() {
    if (galleryCurrentIndex > 0) { galleryCurrentIndex--; galleryUserInteracted = true; renderGallery(); }
}
function galleryNext() {
    if (galleryCurrentIndex < galleryScreenshots.length - 1) { galleryCurrentIndex++; galleryUserInteracted = true; renderGallery(); }
}
function expandScreenshot() {
    const src = $('liveScreenshot').getAttribute('src'); if (!src) return;
    $('lightboxImg').src = src;
    $('lightboxIndex').textContent = galleryCurrentIndex + 1;
    $('lightboxTotal').textContent = galleryScreenshots.length;
    $('lightboxPrev').disabled = galleryCurrentIndex === 0;
    $('lightboxNext').disabled = galleryCurrentIndex === galleryScreenshots.length - 1;
    $('lightboxPrev').style.display = galleryScreenshots.length > 1 ? '' : 'none';
    $('lightboxNext').style.display = galleryScreenshots.length > 1 ? '' : 'none';

    const lb = $('screenshotLightbox');
    lb.classList.remove('hidden');
    setTimeout(()=>lb.classList.remove('opacity-0'), 10);
}
function closeLightbox(e) {
    if (e && e.target !== $('screenshotLightbox')) return;
    const lb = $('screenshotLightbox');
    lb.classList.add('opacity-0');
    setTimeout(()=>lb.classList.add('hidden'), 200);
}
function copyTaskLink() {
    if (!currentLiveTaskId) return;
    const url = window.location.origin + window.location.pathname + '?task=' + currentLiveTaskId;
    navigator.clipboard.writeText(url).then(() => {
        const toast = document.createElement('div');
        toast.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 bg-ink-900 text-white text-xs px-5 py-3 rounded-xl z-[70] font-bold';
        toast.textContent = '✓ Link copied';
        document.body.appendChild(toast);
        setTimeout(()=>toast.remove(), 1500);
    });
}

function switchPreviewTab(tab) {
    const infoBtn = $('tabInfoBtn'), logBtn = $('tabLogBtn'), chatBtn = $('tabChatBtn');
    const paneInfo = $('paneInfo'), paneLog = $('paneLog'), paneChat = $('paneChat');

    const activeCls = 'flex-1 py-3.5 text-xs font-black uppercase tracking-wider border-b-2 tab-active';
    const inactiveCls = 'flex-1 py-3.5 text-xs font-black uppercase tracking-wider border-b-2 tab-inactive';

    infoBtn.className = inactiveCls;
    logBtn.className  = inactiveCls;
    chatBtn.className = inactiveCls;

    paneInfo.style.display = 'none';
    paneLog.style.display  = 'none';
    paneChat.style.display = 'none';

    if (tab === 'info') {
        infoBtn.className = activeCls;
        paneInfo.style.display = 'block';
    } else if (tab === 'log') {
        logBtn.className = activeCls;
        paneLog.style.display = 'block';
    } else {
        chatBtn.className = activeCls;
        paneChat.style.display = 'flex';
        paneChat.style.flexDirection = 'column';
    }
}

let currentChatTaskId = null;
const SUGGESTIONS = [
    'Summarize this page in 3 bullets',
    'What are the key numbers shown?',
    'List every button or link I can click',
    'Are there any warnings or errors?'
];

function renderChatEmpty() {
    return `<div class="text-center py-6">
        <div class="w-14 h-14 mx-auto mb-4 rounded-2xl flex items-center justify-center" style="background:#fdf2f4; border:1.5px solid #fce7eb;">
            <i class="fa-solid fa-wand-magic-sparkles text-brand-red text-xl"></i>
        </div>
        <div class="text-sm font-black text-ink-900 mb-1">Ask anything about this page</div>
        <div class="text-xs text-ink-500 mb-6 font-medium">The AI has already read and understood it.</div>
        <div class="space-y-2 px-2">
            ${SUGGESTIONS.map(s => `
                <button onclick="useSuggestion(this)" class="suggestion-chip w-full text-left px-4 py-3 text-xs bg-white border-[1.5px] border-ink-200 rounded-xl text-ink-700 flex items-center gap-3 group font-semibold">
                    <i class="fa-solid fa-arrow-right text-[10px] text-ink-300 group-hover:text-brand-red transition"></i>
                    <span>${escapeHtml(s)}</span>
                </button>
            `).join('')}
        </div>
    </div>`;
}
function useSuggestion(btn) {
    const text = btn.querySelector('span').textContent.trim();
    $('chatInput').value = text;
    sendChat();
}

async function loadChatHistory(taskId, unlocked) {
    currentChatTaskId = taskId;
    const box = $('chatMessages'), input = $('chatInput'), btn = $('chatSendBtn'), hint = $('chatHint');
    if (!unlocked) {
        box.innerHTML = `<div class="text-center py-12">
            <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-ink-100 flex items-center justify-center">
                <i class="fa-solid fa-lock text-ink-400 text-xl"></i>
            </div>
            <div class="text-sm font-black text-ink-700">Chat unlocks on completion</div>
            <div class="text-xs text-ink-500 mt-1 font-medium">Wait for the task to finish, then come back.</div>
        </div>`;
        input.disabled = true; btn.disabled = true; btn.style.background = '#c8cdd6';
        hint.textContent = 'Available after the bot finishes.';
        return;
    }
    input.disabled = false; btn.disabled = false; btn.style.background = '#c92a42';
    hint.textContent = 'Shift + Enter for a new line.';
    try {
        const res = await fetch('api/chat_history.php?task_id=' + taskId, { cache: 'no-store' });
        const data = await res.json();
        if (!data.messages || data.messages.length === 0) { box.innerHTML = renderChatEmpty(); return; }
        box.innerHTML = data.messages.map((m, i) => renderBubble(m.role, m.message, m.created_at, i)).join('');
        box.scrollTop = box.scrollHeight;
    } catch (_) { box.innerHTML = renderChatEmpty(); }
}

function renderBubble(role, text, ts, idx) {
    const isUser = role === 'user';
    const timeStr = ts ? new Date(ts.replace(' ','T')).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : '';
    const avatar = isUser
        ? `<div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:#111827; color:#fff;"><i class="fa-solid fa-user text-[11px]"></i></div>`
        : `<div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:#c92a42; color:#fff;"><i class="fa-solid fa-robot text-[11px]"></i></div>`;
    const inner = isUser ? escapeHtml(text).replace(/\n/g,'<br>') : renderMarkdown(text);
    const bubble = isUser
        ? `<div class="text-white rounded-xl rounded-tr-sm px-4 py-2.5 max-w-[85%] text-sm leading-relaxed chat-pop font-medium" style="background:#c92a42;">${inner}</div>`
        : `<div class="chat-bubble bg-white border-[1.5px] border-ink-200 text-ink-800 rounded-xl rounded-tl-sm px-4 py-3 max-w-[85%] text-sm leading-relaxed chat-pop relative group font-medium">
               ${inner}
               <button onclick="copyBubbleText(this)" class="absolute -top-2 -right-2 opacity-0 group-hover:opacity-100 transition bg-white border-[1.5px] border-ink-200 rounded-full w-7 h-7 flex items-center justify-center text-ink-400 hover:text-brand-red" title="Copy">
                   <i class="fa-regular fa-copy text-[10px]"></i>
               </button>
           </div>`;
    const meta = `<div class="text-[10px] text-ink-400 mt-1.5 ${isUser ? 'text-right' : 'text-left'} px-1 font-bold">${timeStr}</div>`;
    return `<div class="flex gap-2.5 items-end ${isUser ? 'flex-row-reverse' : ''}">
        ${avatar}
        <div class="flex flex-col ${isUser ? 'items-end' : 'items-start'} max-w-[85%]">
            ${bubble}
            ${meta}
        </div>
    </div>`;
}

function copyBubbleText(btn) {
    const bubble = btn.parentElement;
    const text = bubble.innerText.replace('Copy', '').trim();
    navigator.clipboard.writeText(text);
    btn.innerHTML = '<i class="fa-solid fa-check text-[10px] text-emerald-500"></i>';
    setTimeout(()=>btn.innerHTML = '<i class="fa-regular fa-copy text-[10px]"></i>', 1200);
}

async function sendChat() {
    const input = $('chatInput'), btn = $('chatSendBtn'), box = $('chatMessages');
    const question = input.value.trim();
    if (!question || !currentChatTaskId) return;

    if (box.querySelector('.fa-wand-magic-sparkles') || box.querySelector('.fa-lock')) box.innerHTML = '';
    box.insertAdjacentHTML('beforeend', renderBubble('user', question, new Date().toISOString()));
    box.scrollTop = box.scrollHeight;

    const loadingId = 'loading_' + Date.now();
    box.insertAdjacentHTML('beforeend', `<div id="${loadingId}" class="flex gap-2.5 items-end">
        <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:#c92a42; color:#fff;">
            <i class="fa-solid fa-robot text-[11px]"></i>
        </div>
        <div class="bg-white border-[1.5px] border-ink-200 rounded-xl rounded-tl-sm px-4 py-3">
            <div class="flex gap-1">
                <span class="typing-dot w-1.5 h-1.5 rounded-full inline-block" style="background:#c92a42;"></span>
                <span class="typing-dot w-1.5 h-1.5 rounded-full inline-block" style="background:#c92a42;"></span>
                <span class="typing-dot w-1.5 h-1.5 rounded-full inline-block" style="background:#c92a42;"></span>
            </div>
        </div></div>`);
    box.scrollTop = box.scrollHeight;

    input.value = ''; input.style.height = 'auto';
    input.disabled = true; btn.disabled = true;

    try {
        const res = await fetch('api/ask_page.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_id: currentChatTaskId, question })
        });
        const data = await res.json();
        document.getElementById(loadingId)?.remove();
        if (data.error) {
            box.insertAdjacentHTML('beforeend', renderBubble('assistant', '⚠️ ' + data.error, new Date().toISOString()));
        } else {
            box.insertAdjacentHTML('beforeend', renderBubble('assistant', data.answer, new Date().toISOString()));
        }
    } catch (e) {
        document.getElementById(loadingId)?.remove();
        box.insertAdjacentHTML('beforeend', renderBubble('assistant', '⚠️ Network error. Please try again.', new Date().toISOString()));
    }
    input.disabled = false; btn.disabled = false;
    input.focus();
    box.scrollTop = box.scrollHeight;
}

refreshLive();
setInterval(refreshLive, 2000);

(function handleDeepLink() {
    const params = new URLSearchParams(window.location.search);
    const taskId = params.get('task');
    if (!taskId || isNaN(parseInt(taskId))) return;
    setTimeout(() => { openLivePreview(parseInt(taskId)); }, 400);
    const originalClose = closeLivePreview;
    window.closeLivePreview = function() {
        originalClose();
        const url = new URL(window.location.href);
        url.searchParams.delete('task');
        window.history.replaceState({}, '', url.pathname + (url.search ? url.search : ''));
    };
})();

Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#6b7280';
Chart.defaults.plugins.tooltip.backgroundColor = '#111827';
Chart.defaults.plugins.tooltip.padding = 12;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.plugins.tooltip.titleFont = { weight: '800' };

const ctxTrend = $('trendChart').getContext('2d');
const grad = ctxTrend.createLinearGradient(0,0,0,240);
grad.addColorStop(0,'rgba(201,42,66,.14)'); grad.addColorStop(1,'rgba(201,42,66,0)');
new Chart(ctxTrend, {
    type:'line',
    data:{
        labels:<?= json_encode($trend_labels) ?>,
        datasets:[{
            label:'Tasks',
            data:<?= json_encode($final_trend_data) ?>,
            borderColor:'#c92a42',
            backgroundColor:grad,
            borderWidth:2.5,
            pointBackgroundColor:'#fff',
            pointBorderColor:'#c92a42',
            pointBorderWidth:2,
            pointRadius:3.5,
            pointHoverRadius:6,
            fill:true,
            tension:.4
        }]
    },
    options:{
        responsive:true, maintainAspectRatio:false,
        plugins:{legend:{display:false}},
        scales:{
            x:{grid:{display:false}, border:{display:false}, ticks:{font:{weight:'600', size:11}}},
            y:{grid:{color:'#eef0f4'}, border:{display:false}, beginAtZero:true, ticks:{precision:0, padding:8, font:{weight:'600', size:11}}}
        }
    }
});

const doughnutOptions = {
    cutout:'68%',
    responsive:true,
    maintainAspectRatio:false,
    plugins:{
        legend:{
            position:'bottom',
            labels:{ boxWidth:10, boxHeight:10, padding:14, font:{size:11, weight:'700'} }
        }
    }
};
new Chart($('pieStatus'), { type:'doughnut', data:{ labels:['Completed','Pending','Failed','Running'], datasets:[{ data:<?= json_encode($status_chart_data) ?>, backgroundColor:['#059669','#d97706','#dc2626','#2563eb'], borderWidth:0, hoverOffset:8 }] }, options:doughnutOptions });

function scrollToHistory() {
    const el = document.getElementById('recentSection');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>
</body>
</html>