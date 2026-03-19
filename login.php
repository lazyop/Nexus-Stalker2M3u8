<?php
/**
 * Nexus — Login / Account Setup
 * Single-user auth · JSON-based · Session managed
 */


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


date_default_timezone_set('Asia/Kolkata');


header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");


define('USERS_FILE', __DIR__ . '/data/users_account.json');


// ─── If already logged in, go to dashboard ──────────────────────────────────
if (!empty($_SESSION['nexus_user'])) {
    header('Location: index.php');
    exit;
}


// ─── Helpers ─────────────────────────────────────────────────────────────────
function loadUser(): ?array {
    if (!file_exists(USERS_FILE)) return null;
    $content = file_get_contents(USERS_FILE);
    if (empty(trim($content))) return null;
    $d = json_decode($content, true);
    return (is_array($d) && !empty($d['username'])) ? $d : null;
}


function saveUser(string $username, string $password): void {
    // Generate a unique 64-character XOR key for stream proxy URL encryption
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $xorKey = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < 64; $i++) {
        $xorKey .= $chars[random_int(0, $max)];
    }

    $data = [
        'username'     => $username,
        'password'     => password_hash($password, PASSWORD_DEFAULT),
        'stream_proxy' => 'inactive',
        'xor_key'      => $xorKey,
        'created_at'   => date('Y-m-d H:i:s'),
    ];
    @mkdir(dirname(USERS_FILE), 0755, true);
    file_put_contents(USERS_FILE, json_encode($data, JSON_PRETTY_PRINT));
}


// ─── Determine mode ─────────────────────────────────────────────────────────
$user = loadUser();
$isSetup = ($user === null);


$flash = $_SESSION['login_flash'] ?? null;
unset($_SESSION['login_flash']);


// ─── Handle POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']   ?? '';
    $uname   = trim($_POST['username'] ?? '');
    $pass    = $_POST['password'] ?? '';


    if ($action === 'create') {
        if (empty($uname) || empty($pass)) {
            $_SESSION['login_flash'] = ['type' => 'error', 'msg' => 'Username and password are required.'];
        } elseif (strlen($pass) < 4) {
            $_SESSION['login_flash'] = ['type' => 'error', 'msg' => 'Password must be at least 4 characters.'];
        } else {
            saveUser($uname, $pass);
            $_SESSION['nexus_user'] = $uname;
            header('Location: index.php');
            exit;
        }
    }


    if ($action === 'login') {
        if (empty($uname) || empty($pass)) {
            $_SESSION['login_flash'] = ['type' => 'error', 'msg' => 'Please enter your credentials.'];
        } else {
            $user = loadUser();
            if ($user && $user['username'] === $uname && password_verify($pass, $user['password'])) {
                $_SESSION['nexus_user'] = $uname;
                header('Location: index.php');
                exit;
            } else {
                $_SESSION['login_flash'] = ['type' => 'error', 'msg' => 'Invalid username or password.'];
            }
        }
    }


    header('Location: login.php');
    exit;
}


$e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nexus | <?= $isSetup ? 'Create Account' : 'Login' ?></title>
<meta name="description" content="Nexus — Stalker Portal Manager. Secure access.">
<link rel="icon" type="image/png" sizes="128x128" href="data/logo.png">
<link rel="apple-touch-icon" href="data/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="data/fontawesome/css/all.min.css">
<style>
/* ── Reset & SaaS Light Theme Tokens (matches index.php) ──────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg:          #f8fafc;
    --surface:     #ffffff;
    --border:      #e2e8f0;
    --border-hover:#cbd5e1;

    --text-main:   #0f172a;
    --text-muted:  #64748b;
    --text-light:  #94a3b8;

    --primary:     #3b82f6;
    --primary-hov: #2563eb;
    --primary-bg:  #eff6ff;

    --indigo:      #6366f1;
    --indigo-bg:   #eef2ff;

    --success:     #10b981;
    --success-bg:  #d1fae5;

    --danger:      #ef4444;
    --danger-bg:   #fee2e2;

    --warning:     #f59e0b;
    --warning-bg:  #fef3c7;

    --cyan:        #06b6d4;
    --cyan-bg:     #cffafe;

    --mono:        'JetBrains Mono', monospace;
    --radius-lg:   12px;
    --radius-md:   8px;
    --radius-sm:   6px;

    --shadow-sm:   0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md:   0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05);
}

html { color-scheme: light; height: 100vh; height: 100dvh; overflow: hidden; }

body {
    font-family: 'Inter', system-ui, sans-serif;
    background: var(--bg);
    color: var(--text-main);
    height: 100vh;
    height: 100dvh;
    font-size: 14px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px 20px calc(20px + 42px);
    overflow: hidden;
}

/* ── Layout (matches index.php .page) ────────────────────────────────── */
.page {
    max-width: 600px;
    width: 100%;
    padding: 0 20px;
    position: relative;
    z-index: 1;
    animation: fadeUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Header (matches index.php .hd) ──────────────────────────────────── */
.hd {
    display: flex; align-items: center; gap: 16px;
    margin-bottom: 24px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--indigo) 100%);
    padding: 18px 20px;
    border-radius: var(--radius-lg);
    border: none;
    box-shadow: 0 10px 15px -3px rgba(59,130,246,0.3), 0 4px 6px -2px rgba(59,130,246,0.15);
}
.hd-logo {
    width: 44px; height: 44px;
    flex-shrink: 0; display: block;
}
.hd-text h1 { font-size: 1.25rem; font-weight: 700; letter-spacing: -0.02em; color: #ffffff; }
.hd-text p  { font-size: 0.85rem; color: rgba(255,255,255,0.8); margin-top: 2px; }
.hd-badge {
    margin-left: auto;
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 0.75rem; font-weight: 600;
    padding: 6px 12px; border-radius: 99px;
    background: rgba(255,255,255,0.15); color: #ffffff;
    border: 1px solid rgba(255,255,255,0.2);
}
.hd-badge i { font-size: 8px; }

/* ── Flash Toast (matches index.php) ─────────────────────────────────── */
.flash {
    position: fixed;
    top: 32px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 9999;
    width: max-content;
    max-width: 90vw;
    display: flex; align-items: flex-start; gap: 12px;
    padding: 14px 20px; border-radius: var(--radius-lg);
    font-size: 0.9rem; font-weight: 600;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
    animation: toastIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.flash-ok  { background: var(--surface); border: 1px solid rgba(16,185,129,0.3); color: var(--text-main); border-left: 4px solid var(--success); }
.flash-ok i { color: var(--success); }
.flash-err { background: var(--surface); border: 1px solid rgba(239,68,68,0.3);  color: var(--text-main); border-left: 4px solid var(--danger); }
.flash-err i { color: var(--danger); }
.flash i   { margin-top: 2px; font-size: 1.1rem; }

@keyframes toastIn {
    0%   { opacity: 0; transform: translate(-50%, -30px) scale(0.95); }
    100% { opacity: 1; transform: translate(-50%, 0) scale(1); }
}
@keyframes toastOut {
    0%   { opacity: 1; transform: translate(-50%, 0) scale(1); }
    100% { opacity: 0; transform: translate(-50%, -30px) scale(0.95); }
}

/* ── Card (matches index.php) ────────────────────────────────────────── */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    margin-bottom: 0;
    overflow: hidden;
}
.card-hd {
    display: flex; align-items: center; gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    background: #fafafa;
}
.card-hd-icon {
    width: 32px; height: 32px; border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.875rem; flex-shrink: 0;
}
.ci-blue   { background: var(--primary-bg); color: var(--primary); }
.ci-green  { background: var(--success-bg); color: var(--success); }
.card-hd-text h2  { font-size: 0.95rem; font-weight: 600; color: var(--text-main); }
.card-hd-text p   { font-size: 0.8rem; color: var(--text-muted); margin-top: 2px; }
.card-bd { padding: 20px; }

/* ── Form Elements (matches index.php) ───────────────────────────────── */
.field { margin-bottom: 16px; }
.field:last-child { margin-bottom: 0; }
.field label {
    display: block; font-size: 0.75rem; font-weight: 600;
    color: var(--text-main); margin-bottom: 6px;
}
.fw { position: relative; }
.fw i.field-icon {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    color: var(--text-light); font-size: 0.875rem; pointer-events: none;
    transition: color 0.2s;
}
.fw input {
    width: 100%; background: var(--surface); border: 1px solid var(--border);
    color: var(--text-main); font-family: 'Inter', sans-serif; font-size: 0.875rem;
    padding: 10px 14px 10px 38px; border-radius: var(--radius-md); outline: none;
    transition: all 0.2s ease;
    box-shadow: var(--shadow-sm);
}
.fw input[type="password"] { padding-right: 44px; }
.fw input::placeholder { color: var(--text-light); }
.fw input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-bg); }
.fw:focus-within i.field-icon { color: var(--primary); }

/* Eye Toggle */
.eye-toggle {
    position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: var(--text-light);
    cursor: pointer; font-size: 0.95rem; padding: 4px;
    transition: color 0.2s;
}
.eye-toggle:hover { color: var(--text-main); }

/* ── Buttons (matches index.php) ─────────────────────────────────────── */
.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 10px 20px; border-radius: var(--radius-md);
    font-size: 0.875rem; font-weight: 600;
    font-family: 'Inter', sans-serif; cursor: pointer; border: none;
    transition: all 0.2s ease; text-decoration: none; white-space: nowrap;
    width: 100%;
}
.btn-primary {
    background: var(--primary); color: #fff;
    box-shadow: 0 2px 4px rgba(59,130,246,0.3);
    margin-top: 8px;
}
.btn-primary:hover { background: var(--primary-hov); transform: translateY(-1px); box-shadow: 0 4px 6px rgba(59,130,246,0.4); }
.btn-primary:active { transform: translateY(0); }

/* Spinner */
.spin-wrap { display: none; }
.spin-wrap i { animation: spin 0.6s linear infinite; }
.loading .spin-wrap { display: inline-flex; }
.loading .btn-label { display: none; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Footer (matches index.php) ──────────────────────────────────────── */
.footer {
    position: fixed; bottom: 0; left: 0; width: 100%;
    text-align: center; color: var(--text-muted);
    font-size: 0.75rem; padding: 12px; font-weight: 500;
    background: var(--bg); border-top: 1px solid var(--border); z-index: 50;
}

/* ── Animations ──────────────────────────────────────────────────────── */
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to   { opacity: 1; transform: translateY(0); }
}
.fade { animation: slideDown 0.3s ease-out; }

/* ── Dashed Grid Background ──────────────────────────────────────────── */
.grid-bg {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    background-image:
        linear-gradient(to right, #e7e5e4 1px, transparent 1px),
        linear-gradient(to bottom, #e7e5e4 1px, transparent 1px);
    background-size: 20px 20px;
    background-position: 0 0, 0 0;
    mask-image:
        repeating-linear-gradient(to right, black 0px, black 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(to bottom, black 0px, black 3px, transparent 3px, transparent 8px);
    -webkit-mask-image:
        repeating-linear-gradient(to right, black 0px, black 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(to bottom, black 0px, black 3px, transparent 3px, transparent 8px);
    mask-composite: intersect;
    -webkit-mask-composite: source-in;
}

/* ── Mobile ──────────────────────────────────────────────────────────── */
@media (max-width: 500px) {
    .page { padding: 20px 16px 40px; }
    .card-hd, .card-bd { padding: 16px; }
}
</style>
</head>
<body>
<div class="grid-bg"></div>


<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-err' : 'flash-ok' ?>">
    <i class="fas <?= $flash['type'] === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?>"></i>
    <span><?= $e($flash['msg']) ?></span>
</div>
<?php endif; ?>


<div class="page">


<div class="hd">
    <img src="data/logo.png" alt="Nexus" class="hd-logo" onerror="this.style.display='none'">
    <div class="hd-text">
        <h1>Nexus</h1>
        <p>Stalker Portal Manager</p>
    </div>
    <div class="hd-badge">
        <i class="fas fa-circle"></i>
        <?= $isSetup ? 'Setup' : 'Login' ?>
    </div>
</div>


<div class="card fade">
    <div class="card-hd">
        <div class="card-hd-icon <?= $isSetup ? 'ci-green' : 'ci-blue' ?>">
            <i class="fas <?= $isSetup ? 'fa-user-plus' : 'fa-shield-alt' ?>"></i>
        </div>
        <div class="card-hd-text">
            <?php if ($isSetup): ?>
                <h2>Create Your Account</h2>
                <p>Set up a username and password to secure your dashboard</p>
            <?php else: ?>
                <h2>Welcome Back</h2>
                <p>Sign in to access your portal dashboard</p>
            <?php endif; ?>
        </div>
    </div>


    <div class="card-bd">
        <form method="post" id="auth-form">
            <input type="hidden" name="action" value="<?= $isSetup ? 'create' : 'login' ?>">


            <div class="field">
                <label>Username</label>
                <div class="fw">
                    <i class="fas fa-user field-icon"></i>
                    <input type="text" name="username" placeholder="Enter username" required autocomplete="username" autofocus>
                </div>
            </div>


            <div class="field">
                <label>Password</label>
                <div class="fw">
                    <i class="fas fa-lock field-icon"></i>
                    <input type="password" name="password" id="pass-input" placeholder="Enter password" required autocomplete="<?= $isSetup ? 'new-password' : 'current-password' ?>" minlength="4">
                    <button type="button" class="eye-toggle" onclick="togglePass()" title="Show/Hide Password">
                        <i class="fas fa-eye" id="eye-icon"></i>
                    </button>
                </div>
            </div>


            <button type="submit" class="btn btn-primary" id="auth-btn">
                <span class="spin-wrap"><i class="fas fa-circle-notch"></i></span>
                <span class="btn-label">
                    <i class="fas <?= $isSetup ? 'fa-user-plus' : 'fa-sign-in-alt' ?>"></i>
                    <?= $isSetup ? 'Create Account' : 'Sign In' ?>
                </span>
            </button>
        </form>
    </div>
</div>


</div>


<div class="footer">
    Crafted with <span style="color: var(--danger);">❤️</span> by <strong style="color: var(--text-main); font-weight: 700;">LazyyXD</strong> &middot; Nexus &middot; V1.0 &middot; <?= date('Y') ?>
</div>


<script>
// Auto-hide flash toast
setTimeout(() => {
    const flash = document.querySelector('.flash');
    if (flash) {
        flash.style.animation = 'toastOut 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards';
        setTimeout(() => flash.remove(), 400);
    }
}, 3500);

// Password toggle
function togglePass() {
    const inp  = document.getElementById('pass-input');
    const icon = document.getElementById('eye-icon');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        inp.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// Loading state on submit
document.getElementById('auth-form').addEventListener('submit', function() {
    document.getElementById('auth-btn').classList.add('loading');
});
</script>
</body>
</html>
