<?php
/**
 * Nexus — Custom Error Page
 * Matches login.php UI perfectly
 */

$code = http_response_code();
if ($code < 400) $code = 403;

$errors = [
    403 => ['title' => 'Access Denied',    'icon' => 'fa-shield-alt',      'msg' => 'You don\'t have permission to access this resource. This area is restricted for security purposes.'],
    404 => ['title' => 'Page Not Found',   'icon' => 'fa-map-signs',       'msg' => 'The page you\'re looking for doesn\'t exist or has been moved.'],
    500 => ['title' => 'Server Error',     'icon' => 'fa-exclamation-triangle', 'msg' => 'Something went wrong on our end. Please try again later.'],
];

$err = $errors[$code] ?? ['title' => 'Error ' . $code, 'icon' => 'fa-exclamation-circle', 'msg' => 'An unexpected error occurred.'];
http_response_code($code);

// Determine base path for assets (support being called from root or /data/)
$isSubDir = (basename(dirname($_SERVER['SCRIPT_NAME'])) === 'data' || strpos($_SERVER['REQUEST_URI'], '/data/') !== false);
$base = $isSubDir ? '../' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nexus | <?= $err['title'] ?></title>
<meta name="description" content="Nexus — Stalker Portal Manager. <?= $err['title'] ?>.">
<link rel="icon" type="image/png" sizes="128x128" href="<?= $base ?>data/logo.png">
<link rel="apple-touch-icon" href="<?= $base ?>data/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $base ?>data/fontawesome/css/all.min.css">
<style>
/* ── Reset & SaaS Light Theme Tokens (matches login.php) ──────────────────── */
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

/* ── Layout ──────────────────────────────────────────────────────────── */
.page {
    max-width: 500px;
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

/* ── Header ──────────────────────────────────────────────────────────── */
.hd {
    display: flex; align-items: center; gap: 16px;
    margin-bottom: 24px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--indigo) 100%);
    padding: 18px 20px;
    border-radius: var(--radius-lg);
    box-shadow: 0 10px 15px -3px rgba(59,130,246,0.3), 0 4px 6px -2px rgba(59,130,246,0.15);
}
.hd-logo { width: 44px; height: 44px; flex-shrink: 0; }
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

/* ── Card ────────────────────────────────────────────────────────────── */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    overflow: hidden;
    text-align: center;
}
.card-bd { padding: 40px 24px; }

.error-icon-box {
    width: 64px; height: 64px;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    margin-bottom: 20px;
    font-size: 1.5rem;
}
.icon-403 { background: var(--danger-bg); color: var(--danger); }
.icon-404 { background: var(--primary-bg); color: var(--primary); }
.icon-500 { background: var(--warning-bg); color: var(--warning); }
.icon-def { background: var(--indigo-bg); color: var(--indigo); }

.error-code {
    font-size: 3.5rem; font-weight: 800; letter-spacing: -0.04em;
    color: var(--text-main); line-height: 1; margin-bottom: 8px;
}
.error-title { font-size: 1.15rem; font-weight: 700; color: var(--text-main); margin-bottom: 12px; }
.error-msg { font-size: 0.875rem; color: var(--text-muted); line-height: 1.6; max-width: 320px; margin: 0 auto 32px; }

/* ── Buttons ─────────────────────────────────────────────────────────── */
.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 12px 24px; border-radius: 99px;
    font-size: 0.875rem; font-weight: 600;
    font-family: 'Inter', sans-serif; cursor: pointer; border: none;
    transition: all 0.2s ease; text-decoration: none;
    background: var(--primary); color: #fff;
    box-shadow: 0 4px 6px -1px rgba(59,130,246,0.3);
}
.btn:hover { background: var(--primary-hov); transform: translateY(-1px); box-shadow: 0 10px 15px -3px rgba(59,130,246,0.4); }
.btn:active { transform: translateY(0); }

/* ── Footer ──────────────────────────────────────────────────────────── */
.footer {
    position: fixed; bottom: 0; left: 0; width: 100%;
    text-align: center; color: var(--text-muted);
    font-size: 0.75rem; padding: 12px; font-weight: 500;
    background: var(--bg); border-top: 1px solid var(--border); z-index: 50;
}

/* ── Grid Background ─────────────────────────────────────────────────── */
.grid-bg {
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background-image:
        linear-gradient(to right, #e7e5e4 1px, transparent 1px),
        linear-gradient(to bottom, #e7e5e4 1px, transparent 1px);
    background-size: 20px 20px;
    mask-image:
        repeating-linear-gradient(to right, black 0px, black 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(to bottom, black 0px, black 3px, transparent 3px, transparent 8px);
    -webkit-mask-image:
        repeating-linear-gradient(to right, black 0px, black 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(to bottom, black 0px, black 3px, transparent 3px, transparent 8px);
    mask-composite: intersect; -webkit-mask-composite: source-in;
}

@media (max-width: 500px) {
    .page { padding: 0 16px; }
}
</style>
</head>
<body>
<div class="grid-bg"></div>

<div class="page">
    <div class="hd">
        <img src="<?= $base ?>data/logo.png" alt="Nexus" class="hd-logo" onerror="this.style.display='none'">
        <div class="hd-text">
            <h1>Nexus</h1>
            <p>Stalker Portal Manager</p>
        </div>
        <div class="hd-badge">
            <i class="fas fa-exclamation-circle"></i> Error Code
        </div>
    </div>

    <div class="card">
        <div class="card-bd">
            <div class="error-icon-box <?= isset($errors[$code]) ? 'icon-'.$code : 'icon-def' ?>">
                <i class="fas <?= $err['icon'] ?>"></i>
            </div>
            <div class="error-code"><?= $code ?></div>
            <div class="error-title"><?= $err['title'] ?></div>
            <p class="error-msg"><?= $err['msg'] ?></p>
            
            <a href="<?= $base ?>index.php" class="btn">
                <i class="fas fa-home"></i> Back to Dashboard
            </a>
        </div>
    </div>
</div>

<div class="footer">
    Crafted with <span style="color: var(--danger);">❤️</span> by <strong style="color: var(--text-main); font-weight: 700;">LazyyXD</strong> &middot; Nexus &middot; V1.0 &middot; <?= date('Y') ?>
</div>

</body>
</html>
