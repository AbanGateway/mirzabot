<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
@set_time_limit(300);

require_once __DIR__ . '/checks.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

function mirza_install_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function mirza_install_locked(): bool
{
    return is_file(mirza_install_lock_file());
}

function mirza_install_authorized(): bool
{
    if (!mirza_install_is_configured()) {
        return true;
    }

    return !empty($_SESSION['mirza_install_authorized']);
}

function mirza_install_group(string $group, array $items): array
{
    foreach ($items as $index => $item) {
        $items[$index]['group'] = $group;
    }

    return $items;
}

$action = (string) ($_POST['action'] ?? ($_GET['action'] ?? ''));

if ($action !== '') {
    if (mirza_install_locked() && $action !== 'state') {
        mirza_install_json(['error' => 'نصب قبلاً انجام شده است. برای اجرای مجدد فایل install/.installed را حذف کنید.'], 423);
    }

    if ($action === 'state') {
        mirza_install_json([
            'locked' => mirza_install_locked(),
            'configured' => mirza_install_is_configured(),
            'authorized' => mirza_install_authorized(),
            'shell_exec' => mirza_install_shell_exec_available(),
            'host' => mirza_install_host(),
            'base_url' => mirza_install_base_url(),
            'root' => mirza_install_root(),
        ]);
    }

    if ($action === 'auth') {
        $secret = trim((string) ($_POST['secret'] ?? ''));
        $values = mirza_install_config_values();
        $matches = ($secret !== '' && hash_equals($values['APIKEY'], $secret))
            || ($secret !== '' && hash_equals($values['adminnumber'], $secret));

        if (!$matches) {
            usleep(700000);
            mirza_install_json(['ok' => false, 'error' => 'توکن ربات یا آیدی عددی مدیر نادرست است.'], 403);
        }

        $_SESSION['mirza_install_authorized'] = true;
        mirza_install_json(['ok' => true]);
    }

    if (!mirza_install_authorized()) {
        mirza_install_json(['error' => 'برای ادامه ابتدا هویت خود را تأیید کنید.'], 403);
    }

    if ($action === 'requirements') {
        $items = array_merge(
            mirza_install_group('نسخه PHP', mirza_install_php_check()),
            mirza_install_group('وب‌سرور', mirza_install_webserver_check()),
            mirza_install_group('اکستنشن‌ها', mirza_install_extensions_check()),
            mirza_install_group('تنظیمات PHP', mirza_install_ini_check())
        );
        mirza_install_json(mirza_install_result($items));
    }

    if ($action === 'ssl') {
        mirza_install_json(mirza_install_result(mirza_install_group('دامنه و گواهی', mirza_install_ssl_check())));
    }

    if ($action === 'paths') {
        mirza_install_json(mirza_install_result(mirza_install_group('ساختار فایل‌ها', mirza_install_paths_check())));
    }

    if ($action === 'config_load') {
        $values = mirza_install_config_values();
        foreach ($values as $key => $value) {
            if (mirza_install_is_placeholder($value)) {
                $values[$key] = '';
            }
        }
        $values['passworddb'] = '';
        if ($values['dbhost'] === '') {
            $values['dbhost'] = 'localhost';
        }
        $values['domainhosts'] = mirza_install_host();
        mirza_install_json(['ok' => true, 'values' => $values]);
    }

    if ($action === 'config_save') {
        $values = [
            'dbhost' => trim((string) ($_POST['dbhost'] ?? '')),
            'dbname' => trim((string) ($_POST['dbname'] ?? '')),
            'usernamedb' => trim((string) ($_POST['usernamedb'] ?? '')),
            'passworddb' => (string) ($_POST['passworddb'] ?? ''),
            'APIKEY' => trim((string) ($_POST['APIKEY'] ?? '')),
            'adminnumber' => trim((string) ($_POST['adminnumber'] ?? '')),
            'domainhosts' => mirza_install_host(),
            'usernamebot' => ltrim(trim((string) ($_POST['usernamebot'] ?? '')), '@'),
        ];

        $steps = [];

        if ($values['dbname'] === '' || $values['usernamedb'] === '' || $values['APIKEY'] === '' || $values['adminnumber'] === '') {
            mirza_install_json(['ok' => false, 'error' => 'نام دیتابیس، کاربر دیتابیس، توکن ربات و آیدی عددی مدیر الزامی هستند.', 'steps' => []], 400);
        }
        if (!preg_match('/^\d{5,15}$/', $values['adminnumber'])) {
            mirza_install_json(['ok' => false, 'error' => 'آیدی عددی مدیر باید فقط عدد باشد.', 'steps' => []], 400);
        }
        if (!preg_match('/^\d{6,}:[A-Za-z0-9_-]{30,}$/', $values['APIKEY'])) {
            mirza_install_json(['ok' => false, 'error' => 'قالب توکن ربات نادرست است.', 'steps' => []], 400);
        }

        $database = mirza_install_test_database($values);
        if (!$database['ok']) {
            mirza_install_json(['ok' => false, 'error' => 'اتصال به دیتابیس ناموفق بود: ' . $database['error'], 'steps' => []], 400);
        }
        $steps[] = ['status' => 'ok', 'label' => 'اتصال به دیتابیس', 'detail' => 'MySQL ' . $database['version']];

        $bot = mirza_install_telegram($values['APIKEY'], 'getMe');
        if (!$bot['ok']) {
            mirza_install_json(['ok' => false, 'error' => 'توکن ربات معتبر نیست: ' . $bot['error'], 'steps' => $steps], 400);
        }
        if ($values['usernamebot'] === '') {
            $values['usernamebot'] = (string) ($bot['result']['username'] ?? '');
        }
        $steps[] = ['status' => 'ok', 'label' => 'اعتبارسنجی توکن ربات', 'detail' => '@' . ($bot['result']['username'] ?? '')];

        $written = mirza_install_write_config($values);
        if (!$written['ok']) {
            mirza_install_json(['ok' => false, 'error' => $written['error'], 'steps' => $steps], 500);
        }
        $steps[] = ['status' => 'ok', 'label' => 'ساخت فایل config.php', 'detail' => 'دامنه: ' . $values['domainhosts']];

        $_SESSION['mirza_install_authorized'] = true;

        mirza_install_json(['ok' => true, 'error' => '', 'steps' => $steps, 'usernamebot' => $values['usernamebot']]);
    }

    if ($action === 'bootstrap') {
        $bootstrap = mirza_install_bootstrap_database();
        if (!$bootstrap['ok']) {
            mirza_install_json(['ok' => false, 'error' => 'ساخت جداول ناموفق بود: ' . $bootstrap['error']], 500);
        }

        mirza_install_json([
            'ok' => true,
            'error' => '',
            'steps' => [
                ['status' => 'ok', 'label' => 'ساخت و به‌روزرسانی جداول دیتابیس', 'detail' => 'جداول، ایندکس‌ها و مهاجرت‌ها اعمال شدند'],
                ['status' => 'ok', 'label' => 'وبهوک تلگرام', 'detail' => 'در مرحله پایانی ست می‌شود'],
            ],
        ]);
    }

    if ($action === 'cron_plan') {
        mirza_install_json([
            'ok' => true,
            'jobs' => mirza_install_cron_plan(),
            'required' => mirza_install_required_jobs(),
            'probe' => mirza_install_probe_status(),
        ]);
    }

    if ($action === 'probe_begin') {
        mirza_install_probe_reset();
        mirza_install_json(mirza_install_probe_status());
    }

    if ($action === 'probe_status') {
        mirza_install_json(mirza_install_probe_status());
    }

    if ($action === 'finish') {
        if (!mirza_install_shell_exec_available()) {
            $probe = mirza_install_probe_status();
            if (!$probe['verified']) {
                mirza_install_json(['ok' => false, 'error' => 'اجرای کرون هاست هنوز تأیید نشده است.'], 400);
            }

            $confirmed = json_decode((string) ($_POST['confirmed'] ?? '[]'), true);
            $confirmed = is_array($confirmed) ? array_map('strval', $confirmed) : [];
            $missing = array_diff(mirza_install_required_jobs(), $confirmed);
            if ($missing !== []) {
                mirza_install_json(['ok' => false, 'error' => 'این کرون‌ها هنوز تأیید نشده‌اند: ' . implode('، ', $missing)], 400);
            }
        }

        if (!mirza_install_is_configured()) {
            mirza_install_json(['ok' => false, 'error' => 'ابتدا باید مرحله تنظیمات ربات کامل شود.'], 400);
        }

        $values = mirza_install_config_values();
        $webhookUrl = 'https://' . $values['domainhosts'] . '/index.php';
        $reactivateUrl = 'https://' . $values['domainhosts'] . '/table.php';

        $deleted = mirza_install_delete_tree(__DIR__);

        if (!$deleted) {
            mirza_install_telegram($values['APIKEY'], 'deleteWebhook', []);

            mirza_install_json([
                'ok' => false,
                'deleted' => false,
                'disabled' => true,
                'steps' => [
                    ['status' => 'fail', 'label' => 'حذف پوشه install', 'detail' => 'حذف خودکار انجام نشد'],
                    ['status' => 'fail', 'label' => 'وضعیت ربات', 'detail' => 'وبهوک حذف شد و ربات غیرفعال است'],
                ],
                'error' => 'پوشه install حذف نشد، بنابراین ربات غیرفعال شد. پوشه install را با فایل‌منیجر یا FTP دستی حذف کنید، سپس یک بار آدرس ' . $reactivateUrl . ' را در مرورگر باز کنید تا ربات دوباره فعال شود.',
                'reactivate_url' => $reactivateUrl,
            ], 500);
        }

        $steps = [['status' => 'ok', 'label' => 'حذف پوشه install', 'detail' => 'نصب‌کننده از روی هاست پاک شد و مسدودسازی ربات برداشته شد']];

        $webhook = mirza_install_telegram($values['APIKEY'], 'setWebhook', [
            'url' => $webhookUrl,
            'max_connections' => 40,
        ]);
        if (!$webhook['ok']) {
            mirza_install_json([
                'ok' => false,
                'deleted' => true,
                'steps' => $steps,
                'error' => 'پوشه install حذف شد ولی تنظیم وبهوک ناموفق بود: ' . $webhook['error'] . ' — یک بار آدرس ' . $reactivateUrl . ' را در مرورگر باز کنید تا وبهوک ست شود.',
                'reactivate_url' => $reactivateUrl,
            ], 500);
        }

        $steps[] = ['status' => 'ok', 'label' => 'تنظیم وبهوک تلگرام', 'detail' => $webhookUrl];

        $info = mirza_install_telegram($values['APIKEY'], 'getWebhookInfo');
        if ($info['ok']) {
            $lastError = (string) ($info['result']['last_error_message'] ?? '');
            $steps[] = [
                'status' => $lastError === '' ? 'ok' : 'warn',
                'label' => 'وضعیت وبهوک',
                'detail' => $lastError === ''
                    ? 'بدون خطا، ' . (int) ($info['result']['pending_update_count'] ?? 0) . ' آپدیت در صف'
                    : 'آخرین خطای تلگرام: ' . $lastError,
            ];
        }

        mirza_install_telegram($values['APIKEY'], 'sendMessage', [
            'chat_id' => $values['adminnumber'],
            'text' => 'ربات میرزا روی هاست نصب شد. برای شروع دستور /start را بفرستید.',
        ]);

        mirza_install_json([
            'ok' => true,
            'deleted' => true,
            'steps' => $steps,
            'bot_url' => $values['usernamebot'] !== '' ? 'https://t.me/' . $values['usernamebot'] : '',
        ]);
    }

    mirza_install_json(['error' => 'درخواست نامعتبر است.'], 400);
}

$locked = mirza_install_locked();
$configured = mirza_install_is_configured();
$authorized = mirza_install_authorized();
$host = mirza_install_host();

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>نصب ربات میرزا</title>
    <style>
        :root {
            --bg: #0f1420;
            --panel: #161d2c;
            --panel-2: #1c2537;
            --line: #263248;
            --text: #e6ecf7;
            --muted: #94a3b8;
            --brand: #3b82f6;
            --ok: #22c55e;
            --warn: #f59e0b;
            --fail: #ef4444;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Vazirmatn, Tahoma, "Segoe UI", system-ui, sans-serif;
            font-size: 15px;
            line-height: 1.9;
        }

        .wrap {
            max-width: 980px;
            margin: 0 auto;
            padding: 28px 18px 90px;
        }

        header.top {
            text-align: center;
            margin-bottom: 26px;
        }

        header.top h1 {
            margin: 0 0 6px;
            font-size: 24px;
        }

        header.top p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .steps {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
            margin-bottom: 22px;
        }

        .steps span {
            font-size: 12.5px;
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            color: var(--muted);
            background: var(--panel);
        }

        .steps span.active {
            border-color: var(--brand);
            color: #fff;
            background: #1e3a8a33;
        }

        .steps span.done {
            border-color: var(--ok);
            color: var(--ok);
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 22px;
        }

        .card h2 {
            margin: 0 0 4px;
            font-size: 19px;
        }

        .card .lead {
            margin: 0 0 18px;
            color: var(--muted);
            font-size: 13.5px;
        }

        .group-title {
            margin: 18px 0 8px;
            font-size: 13px;
            color: var(--muted);
            border-top: 1px dashed var(--line);
            padding-top: 12px;
        }

        .group-title:first-child {
            border-top: 0;
            margin-top: 0;
            padding-top: 0;
        }

        .row {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 9px 12px;
            border-radius: 10px;
            background: var(--panel-2);
            margin-bottom: 7px;
        }

        .dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            margin-top: 11px;
            flex: 0 0 9px;
        }

        .dot.ok {
            background: var(--ok);
        }

        .dot.warn {
            background: var(--warn);
        }

        .dot.fail {
            background: var(--fail);
        }

        .row .body {
            flex: 1;
            min-width: 0;
        }

        .row .label {
            font-size: 14px;
        }

        .row .value {
            font-size: 13px;
            color: var(--muted);
        }

        .row .hint {
            font-size: 12.5px;
            color: #cbd5e1;
            margin-top: 3px;
        }

        .row.fail .hint {
            color: #fca5a5;
        }

        .row.warn .hint {
            color: #fcd34d;
        }

        .summary {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .pill {
            font-size: 12.5px;
            padding: 4px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
        }

        .pill.ok {
            color: var(--ok);
            border-color: #14532d;
        }

        .pill.warn {
            color: var(--warn);
            border-color: #78350f;
        }

        .pill.fail {
            color: var(--fail);
            border-color: #7f1d1d;
        }

        button {
            font-family: inherit;
            font-size: 14px;
            padding: 9px 20px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: var(--panel-2);
            color: var(--text);
            cursor: pointer;
        }

        button.primary {
            background: var(--brand);
            border-color: var(--brand);
            color: #fff;
        }

        button:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        .actions {
            display: flex;
            gap: 10px;
            justify-content: space-between;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .actions .left,
        .actions .right {
            display: flex;
            gap: 10px;
        }

        label.field {
            display: block;
            margin-bottom: 13px;
        }

        label.field span {
            display: block;
            font-size: 13px;
            margin-bottom: 5px;
            color: var(--muted);
        }

        input[type=text],
        input[type=password] {
            width: 100%;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: var(--panel-2);
            color: var(--text);
            font-family: inherit;
            font-size: 14px;
            direction: ltr;
            text-align: left;
        }

        .grid2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 14px;
        }

        @media (max-width: 680px) {
            .grid2 {
                grid-template-columns: 1fr;
            }
        }

        .notice {
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 13px;
            margin-bottom: 14px;
            border: 1px solid var(--line);
            background: var(--panel-2);
        }

        .notice.bad {
            border-color: #7f1d1d;
            color: #fca5a5;
        }

        .notice.good {
            border-color: #14532d;
            color: #86efac;
        }

        .notice.info {
            border-color: #1e3a8a;
            color: #bfdbfe;
        }

        pre.cmd {
            background: #0b1017;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 14px;
            overflow-x: auto;
            direction: ltr;
            text-align: left;
            font-size: 12.5px;
            line-height: 1.8;
            margin: 0 0 10px;
        }

        table.jobs {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        table.jobs th,
        table.jobs td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--line);
            text-align: right;
        }

        table.jobs th {
            color: var(--muted);
            font-weight: normal;
            font-size: 12.5px;
        }

        table.jobs td.mono {
            direction: ltr;
            text-align: left;
            font-family: monospace;
            color: var(--muted);
        }

        .badge {
            font-size: 12px;
            padding: 2px 9px;
            border-radius: 999px;
            white-space: nowrap;
        }

        .badge.ok {
            background: #14532d;
            color: #bbf7d0;
        }

        .badge.warn {
            background: #78350f;
            color: #fde68a;
        }

        .badge.fail {
            background: #7f1d1d;
            color: #fecaca;
        }

        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
        }

        .tabs button.active {
            border-color: var(--brand);
            color: #fff;
        }

        .checkbox {
            display: flex;
            gap: 9px;
            align-items: flex-start;
            font-size: 13.5px;
            margin-top: 14px;
        }

        .spinner {
            display: inline-block;
            width: 13px;
            height: 13px;
            border: 2px solid #ffffff55;
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            vertical-align: -2px;
            margin-left: 6px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        ol.guide {
            margin: 0 0 12px;
            padding-right: 20px;
            font-size: 13px;
            color: #cbd5e1;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <header class="top">
            <h1>نصب ربات میرزا روی هاست</h1>
            <p>دامنه شناسایی‌شده: <b><?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?></b></p>
        </header>

        <?php if ($locked): ?>
            <div class="card">
                <h2>نصب قبلاً انجام شده است</h2>
                <p class="lead">برای امنیت، نصب‌کننده قفل شده است.</p>
                <div class="notice good">پوشه <code>install</code> را از هاست حذف کنید. اگر می‌خواهید دوباره نصب کنید، فایل <code>install/.installed</code> را پاک کنید.</div>
            </div>
        <?php elseif (!$authorized): ?>
            <div class="card">
                <h2>تأیید هویت</h2>
                <p class="lead">ربات قبلاً روی این هاست پیکربندی شده است. برای اجرای دوباره نصب‌کننده، توکن ربات یا آیدی عددی مدیر را وارد کنید.</p>
                <div id="authError" class="notice bad" style="display:none"></div>
                <label class="field">
                    <span>توکن ربات یا آیدی عددی مدیر</span>
                    <input type="password" id="authSecret" autocomplete="off">
                </label>
                <div class="actions">
                    <span></span>
                    <button class="primary" id="authButton">ورود</button>
                </div>
            </div>
            <script>
                const authButton = document.getElementById('authButton');
                authButton.addEventListener('click', async () => {
                    const box = document.getElementById('authError');
                    box.style.display = 'none';
                    authButton.disabled = true;
                    const body = new FormData();
                    body.append('action', 'auth');
                    body.append('secret', document.getElementById('authSecret').value);
                    const response = await fetch('index.php', { method: 'POST', body });
                    const data = await response.json();
                    if (data.ok) {
                        location.reload();
                        return;
                    }
                    box.textContent = data.error || 'ورود ناموفق بود.';
                    box.style.display = 'block';
                    authButton.disabled = false;
                });
            </script>
        <?php else: ?>
            <div class="steps" id="steps"></div>
            <div class="card" id="card"></div>
        <?php endif; ?>
    </div>
    <?php if (!$locked && $authorized): ?>
        <script>
            const SHELL_EXEC_AVAILABLE = <?php echo mirza_install_shell_exec_available() ? 'true' : 'false'; ?>;
            const STEPS = [
                { key: 'requirements', title: 'پیش‌نیازهای سرور' },
                { key: 'cron', title: 'کرون‌ها' },
                { key: 'ssl', title: 'دامنه و SSL' },
                { key: 'paths', title: 'فایل‌ها و مسیرها' },
                { key: 'config', title: 'تنظیمات ربات' },
                { key: 'done', title: 'پایان' }
            ].filter(step => step.key !== 'cron' || !SHELL_EXEC_AVAILABLE);

            let current = 0;
            let cronTimer = null;

            const card = document.getElementById('card');
            const stepsBar = document.getElementById('steps');

            async function api(action, payload = {}) {
                const body = new FormData();
                body.append('action', action);
                Object.keys(payload).forEach(key => body.append(key, payload[key]));
                const response = await fetch('index.php', { method: 'POST', body });
                try {
                    return await response.json();
                } catch (error) {
                    return { error: 'پاسخ سرور نامعتبر بود (کد ' + response.status + ').' };
                }
            }

            function renderSteps() {
                stepsBar.innerHTML = STEPS.map((step, index) => {
                    const cls = index === current ? 'active' : (index < current ? 'done' : '');
                    return '<span class="' + cls + '">' + (index + 1) + '. ' + step.title + '</span>';
                }).join('');
            }

            function escapeHtml(value) {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function renderItems(result) {
                let html = '';
                let lastGroup = null;
                result.items.forEach(item => {
                    if (item.group && item.group !== lastGroup) {
                        lastGroup = item.group;
                        html += '<div class="group-title">' + escapeHtml(item.group) + '</div>';
                    }
                    html += '<div class="row ' + item.status + '">'
                        + '<div class="dot ' + item.status + '"></div>'
                        + '<div class="body">'
                        + '<div class="label">' + escapeHtml(item.label)
                        + (item.value ? ' <span class="value">— ' + escapeHtml(item.value) + '</span>' : '')
                        + '</div>'
                        + (item.hint ? '<div class="hint">' + escapeHtml(item.hint) + '</div>' : '')
                        + '</div></div>';
                });
                const passed = result.items.length - result.failed - result.warned;
                const summary = '<div class="summary">'
                    + '<span class="pill ok">' + passed + ' مورد سالم</span>'
                    + '<span class="pill warn">' + result.warned + ' هشدار</span>'
                    + '<span class="pill fail">' + result.failed + ' خطا</span>'
                    + '</div>';
                return summary + html;
            }

            function actionsHtml(nextLabel, nextEnabled, extra = '') {
                return '<div class="actions">'
                    + '<div class="left">'
                    + (current > 0 ? '<button id="backBtn">مرحله قبل</button>' : '')
                    + extra
                    + '</div>'
                    + '<div class="right">'
                    + '<button id="recheckBtn">بررسی مجدد</button>'
                    + '<button class="primary" id="nextBtn"' + (nextEnabled ? '' : ' disabled') + '>' + nextLabel + '</button>'
                    + '</div></div>';
            }

            function bindNav(onNext, onRecheck) {
                const backBtn = document.getElementById('backBtn');
                if (backBtn) {
                    backBtn.addEventListener('click', () => { current--; render(); });
                }
                const nextBtn = document.getElementById('nextBtn');
                if (nextBtn) {
                    nextBtn.addEventListener('click', onNext || (() => { current++; render(); }));
                }
                const recheckBtn = document.getElementById('recheckBtn');
                if (recheckBtn && onRecheck) {
                    recheckBtn.addEventListener('click', onRecheck);
                }
            }

            function loading(text) {
                card.innerHTML = '<h2>' + text + '<span class="spinner"></span></h2>';
            }

            async function renderCheckStep(action, title, lead, blockingNote) {
                loading('در حال بررسی');
                const result = await api(action);
                if (result.error) {
                    card.innerHTML = '<h2>' + title + '</h2><div class="notice bad">' + escapeHtml(result.error) + '</div>';
                    return;
                }
                card.innerHTML = '<h2>' + title + '</h2><p class="lead">' + lead + '</p>'
                    + (result.ok ? '' : '<div class="notice bad">' + blockingNote + '</div>')
                    + renderItems(result)
                    + actionsHtml('مرحله بعد', result.ok);
                bindNav(null, () => renderCheckStep(action, title, lead, blockingNote));
            }

            async function renderConfigStep() {
                loading('در حال خواندن تنظیمات');
                const data = await api('config_load');
                const values = data.values || {};
                card.innerHTML = '<h2>تنظیمات ربات</h2>'
                    + '<p class="lead">اطلاعات دیتابیس و ربات را وارد کنید. فایل config.php ساخته می‌شود و جداول دیتابیس ایجاد می‌شوند. وبهوک تلگرام در مرحله پایانی ست می‌شود.</p>'
                    + '<div id="configMsg"></div>'
                    + '<div class="grid2">'
                    + field('dbhost', 'هاست دیتابیس', values.dbhost || 'localhost')
                    + field('dbname', 'نام دیتابیس', values.dbname || '')
                    + field('usernamedb', 'کاربر دیتابیس', values.usernamedb || '')
                    + field('passworddb', 'رمز دیتابیس', '', 'password')
                    + '</div>'
                    + field('APIKEY', 'توکن ربات تلگرام', values.APIKEY || '', 'password')
                    + '<div class="grid2">'
                    + field('adminnumber', 'آیدی عددی مدیر', values.adminnumber || '')
                    + field('usernamebot', 'یوزرنیم ربات (اختیاری)', values.usernamebot || '')
                    + '</div>'
                    + '<div id="configSteps"></div>'
                    + actionsHtml('ذخیره و ادامه', true);
                document.getElementById('recheckBtn').style.display = 'none';
                bindNav(saveConfig, null);
            }

            function field(name, label, value, type = 'text') {
                return '<label class="field"><span>' + label + '</span>'
                    + '<input type="' + type + '" id="f_' + name + '" value="' + escapeHtml(value) + '" autocomplete="off"></label>';
            }

            async function saveConfig() {
                const message = document.getElementById('configMsg');
                const nextBtn = document.getElementById('nextBtn');
                message.innerHTML = '';
                nextBtn.disabled = true;
                nextBtn.innerHTML = 'در حال نصب<span class="spinner"></span>';

                const payload = {};
                ['dbhost', 'dbname', 'usernamedb', 'passworddb', 'APIKEY', 'adminnumber', 'usernamebot'].forEach(name => {
                    payload[name] = document.getElementById('f_' + name).value;
                });

                const saved = await api('config_save', payload);
                renderConfigSteps(saved.steps || []);
                if (!saved.ok) {
                    message.innerHTML = '<div class="notice bad">' + escapeHtml(saved.error || saved.errorText || 'ذخیره ناموفق بود.') + '</div>';
                    nextBtn.disabled = false;
                    nextBtn.textContent = 'ذخیره و ادامه';
                    return;
                }

                const bootstrapped = await api('bootstrap');
                renderConfigSteps((saved.steps || []).concat(bootstrapped.steps || []));
                if (!bootstrapped.ok) {
                    message.innerHTML = '<div class="notice bad">' + escapeHtml(bootstrapped.error || 'ساخت جداول ناموفق بود.') + '</div>';
                    nextBtn.disabled = false;
                    nextBtn.textContent = 'تلاش مجدد';
                    return;
                }

                message.innerHTML = '<div class="notice good">نصب پایگاه داده و وبهوک با موفقیت انجام شد.</div>';
                nextBtn.disabled = false;
                nextBtn.textContent = 'مرحله بعد';
                nextBtn.replaceWith(nextBtn.cloneNode(true));
                document.getElementById('nextBtn').addEventListener('click', () => { current++; render(); });
            }

            function renderConfigSteps(steps) {
                renderConfigStepsInto('configSteps', steps);
            }

            let cronPlan = null;
            const confirmedJobs = new Set();
            let cronTab = 'curl';

            async function renderCronStep() {
                if (!cronPlan) {
                    loading('در حال آماده‌سازی مرحله کرون');
                    cronPlan = await api('cron_plan');
                    if (cronPlan.probe && cronPlan.probe.count === 0) {
                        cronPlan.probe = await api('probe_begin');
                    }
                }
                drawCron();
                if (cronTimer) {
                    clearInterval(cronTimer);
                }
                cronTimer = setInterval(async () => {
                    if (STEPS[current].key !== 'cron') {
                        clearInterval(cronTimer);
                        return;
                    }
                    cronPlan.probe = await api('probe_status');
                    drawCron();
                }, 15000);
            }

            function commandOf(job) {
                return cronTab === 'curl' ? job.command_curl : job.command_php;
            }

            function drawCron() {
                const probe = cronPlan.probe || {};
                const jobs = cronPlan.jobs || [];
                const required = cronPlan.required || [];
                const allCommands = jobs.map(commandOf).join('\n');
                const missing = required.filter(job => !confirmedJobs.has(job));
                const canContinue = probe.verified && missing.length === 0;

                card.innerHTML = '<h2>ثبت دستی کرون‌ها</h2>'
                    + '<p class="lead">روی هاست اشتراکی دسترسی shell_exec وجود ندارد، پس کرون‌ها باید از بخش Cron Jobs کنترل پنل هاست (cPanel / DirectAdmin / Plesk / DirectSlave) دستی ثبت شوند. بدون این کرون‌ها فعال‌سازی سرویس، ارسال پیام و پیگیری پرداخت‌ها کار نمی‌کند.</p>'
                    + '<div class="tabs"><button class="' + (cronTab === 'curl' ? 'active' : '') + '" id="tabCurl">فراخوانی با curl</button>'
                    + '<button class="' + (cronTab === 'php' ? 'active' : '') + '" id="tabPhp">اجرای مستقیم PHP</button></div>'
                    + '<div class="group-title">۱. تست اجرای کرون روی هاست</div>'
                    + '<p class="lead">این یک خط <b>موقت</b> است و فقط برای اثبات فعال بودن کرون هاست استفاده می‌شود. بعد از پایان نصب آن را از کنترل پنل حذف کنید.</p>'
                    + '<pre class="cmd" id="probeBox">' + escapeHtml(cronTab === 'curl' ? probe.command_curl : probe.command_php) + '</pre>'
                    + '<div class="row ' + (probe.verified ? 'ok' : 'fail') + '"><div class="dot ' + (probe.verified ? 'ok' : 'fail') + '"></div>'
                    + '<div class="body"><div class="label">وضعیت کرون هاست <span class="value">— '
                    + escapeHtml(probe.count + ' اجرا ثبت شده، آخرین اجرا: ' + probe.last_run_human) + '</span></div>'
                    + '<div class="hint">' + escapeHtml(probe.message || '') + '</div></div></div>'
                    + '<div class="actions" style="margin:10px 0 0"><div class="left">'
                    + '<button id="copyProbe">کپی دستور تست</button><button id="resetProbe">شروع دوباره تست</button>'
                    + '</div><div class="right"></div></div>'
                    + '<div class="group-title">۲. کرون‌های اصلی ربات</div>'
                    + '<p class="lead">هر خط را در کنترل پنل ثبت کنید و بعد تیک کنارش را بزنید. تا وقتی همه کرون‌های اجباری تیک نخورند، ادامه ممکن نیست. این کرون‌ها تا پایان نصب و حذف شدن پوشه install پاسخی نمی‌گیرند و از همان لحظه به بعد شروع به کار می‌کنند.</p>'
                    + '<div class="actions" style="margin:0 0 12px"><div class="left">'
                    + '<button id="copyAll">کپی همه دستورها</button><button id="checkAll">تیک همه</button>'
                    + '</div><div class="right"></div></div>'
                    + jobs.map(jobRow).join('')
                    + '<pre class="cmd" id="allBox" style="display:none">' + escapeHtml(allCommands) + '</pre>'
                    + '<div class="summary" style="margin-top:14px"><span class="pill ' + (missing.length === 0 ? 'ok' : 'fail') + '">'
                    + (required.length - missing.length) + ' از ' + required.length + ' کرون اجباری تأیید شد</span>'
                    + '<span class="pill ' + (probe.verified ? 'ok' : 'fail') + '">تست کرون هاست: ' + (probe.verified ? 'تأیید شد' : 'در انتظار') + '</span></div>'
                    + actionsHtml('مرحله بعد', canContinue);

                document.getElementById('recheckBtn').textContent = 'بررسی وضعیت';
                document.getElementById('tabCurl').addEventListener('click', () => { cronTab = 'curl'; drawCron(); });
                document.getElementById('tabPhp').addEventListener('click', () => { cronTab = 'php'; drawCron(); });
                document.getElementById('copyProbe').addEventListener('click', () => copyText(document.getElementById('probeBox').textContent, 'copyProbe'));
                document.getElementById('copyAll').addEventListener('click', () => copyText(document.getElementById('allBox').textContent, 'copyAll'));
                document.getElementById('resetProbe').addEventListener('click', async () => {
                    cronPlan.probe = await api('probe_begin');
                    drawCron();
                });
                document.getElementById('checkAll').addEventListener('click', () => {
                    jobs.forEach(job => confirmedJobs.add(job.job));
                    drawCron();
                });
                jobs.forEach(job => {
                    document.getElementById('chk_' + job.job).addEventListener('change', event => {
                        if (event.target.checked) {
                            confirmedJobs.add(job.job);
                        } else {
                            confirmedJobs.delete(job.job);
                        }
                        drawCron();
                    });
                    document.getElementById('cpy_' + job.job).addEventListener('click', () => copyText(commandOf(job), 'cpy_' + job.job));
                });
                bindNav(null, async () => {
                    cronPlan.probe = await api('probe_status');
                    drawCron();
                });
            }

            function jobRow(job) {
                const checked = confirmedJobs.has(job.job);
                return '<div class="row ' + (checked ? 'ok' : (job.optional ? 'warn' : 'fail')) + '">'
                    + '<input type="checkbox" id="chk_' + job.job + '"' + (checked ? ' checked' : '') + ' style="margin-top:10px">'
                    + '<div class="body"><div class="label">' + escapeHtml(job.title)
                    + (job.optional ? ' <span class="value">(اختیاری)</span>' : '') + '</div>'
                    + '<div class="hint" style="direction:ltr;text-align:left;font-family:monospace;font-size:12px">'
                    + escapeHtml(commandOf(job)) + '</div></div>'
                    + '<button id="cpy_' + job.job + '" style="padding:4px 10px;font-size:12px">کپی</button></div>';
            }

            function copyText(text, buttonId) {
                navigator.clipboard.writeText(text).then(() => {
                    const button = document.getElementById(buttonId);
                    const original = button.textContent;
                    button.textContent = 'کپی شد';
                    setTimeout(() => { button.textContent = original; }, 1500);
                });
            }

            async function renderDoneStep() {
                card.innerHTML = '<h2>پایان نصب</h2>'
                    + '<p class="lead">با تأیید این مرحله ابتدا پوشه install به‌صورت خودکار حذف می‌شود، سپس وبهوک تلگرام ست شده و پیام تست برای مدیر ارسال می‌گردد.</p>'
                    + '<div class="notice info">تا زمانی که پوشه install روی هاست باشد، ربات مسدود است. اگر حذف خودکار ناموفق باشد، وبهوک هم حذف می‌شود و ربات غیرفعال می‌ماند تا پوشه را دستی پاک کنید.</div>'
                    + (SHELL_EXEC_AVAILABLE ? '' : '<div class="notice info">فراموش نکنید کرون <b>موقت</b> تست (install/cron-check.php) را هم از کنترل پنل هاست حذف کنید.</div>')
                    + '<div id="doneMsg"></div><div id="doneSteps"></div>'
                    + actionsHtml('پایان نصب و حذف نصب‌کننده', true);
                document.getElementById('recheckBtn').style.display = 'none';
                bindNav(async () => {
                    const nextBtn = document.getElementById('nextBtn');
                    nextBtn.disabled = true;
                    nextBtn.innerHTML = 'در حال اتمام<span class="spinner"></span>';
                    const result = await api('finish', { confirmed: JSON.stringify(Array.from(confirmedJobs)) });
                    renderConfigStepsInto('doneSteps', result.steps || []);
                    const box = document.getElementById('doneMsg');
                    if (result.ok) {
                        box.className = 'notice good';
                        box.innerHTML = 'نصب کامل شد، پوشه install حذف گردید و ربات از حالت مسدود خارج شد. حالا در تلگرام دستور /start را برای ربات بفرستید.'
                            + (result.bot_url ? ' <a href="' + escapeHtml(result.bot_url) + '" style="color:#93c5fd">' + escapeHtml(result.bot_url) + '</a>' : '');
                        nextBtn.style.display = 'none';
                        return;
                    }
                    box.className = 'notice bad';
                    box.textContent = result.error || 'اتمام نصب ناموفق بود.';
                    nextBtn.disabled = false;
                    nextBtn.textContent = 'تلاش مجدد';
                }, null);
            }

            function renderConfigStepsInto(containerId, steps) {
                const container = document.getElementById(containerId);
                if (!container) {
                    return;
                }
                container.innerHTML = steps.map(step =>
                    '<div class="row ' + step.status + '"><div class="dot ' + step.status + '"></div>'
                    + '<div class="body"><div class="label">' + escapeHtml(step.label) + '</div>'
                    + '<div class="hint">' + escapeHtml(step.detail) + '</div></div></div>'
                ).join('');
            }

            function render() {
                renderSteps();
                const key = STEPS[current].key;
                if (key === 'requirements') {
                    renderCheckStep('requirements', 'پیش‌نیازهای سرور',
                        'نسخه PHP، نوع وب‌سرور، اکستنشن‌های موردنیاز و تنظیمات php.ini بررسی می‌شوند.',
                        'تا وقتی موارد قرمز برطرف نشوند نمی‌توان ادامه داد.');
                } else if (key === 'ssl') {
                    renderCheckStep('ssl', 'دامنه و گواهی SSL',
                        'دامنه از روی همین صفحه خوانده می‌شود و اعتبار گواهی SSL آن مستقیماً تست می‌شود.',
                        'تلگرام بدون SSL معتبر وبهوک را قبول نمی‌کند.');
                } else if (key === 'paths') {
                    renderCheckStep('paths', 'فایل‌ها و مسیرها',
                        'کامل بودن پوشه‌ها، فایل‌های سورس، فایل‌های .htaccess و دسترسی نوشتن بررسی می‌شود.',
                        'فایل‌های ناقص یا دسترسی نوشتن نداشتن باعث خطای ربات می‌شود.');
                } else if (key === 'config') {
                    renderConfigStep();
                } else if (key === 'cron') {
                    renderCronStep();
                } else {
                    renderDoneStep();
                }
            }

            render();
        </script>
    <?php endif; ?>
</body>

</html>
