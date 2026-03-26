<?php
/**
 * Nexus — Stalker Portal Manager
 * Single portal · Token saved to JSON · No database · No proxy
 */


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


date_default_timezone_set('Asia/Kolkata');


header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past


// ─── Auth Guard ──────────────────────────────────────────────────────────────
if (empty($_SESSION['nexus_user'])) {
    header('Location: login.php');
    exit;
}


// ─── Session Timeout (5 minutes idle) ────────────────────────────────────────
define('SESSION_TIMEOUT', 300); // 5 minutes in seconds
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['login_flash'] = ['type' => 'error', 'msg' => 'Session expired due to inactivity. Please sign in again.'];
    header('Location: login.php');
    exit;
}
$_SESSION['last_activity'] = time();


// ─── Logout ──────────────────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: login.php');
    exit;
}


require_once __DIR__ . '/StalkerLite.php';


define('PORTAL_FILE',   __DIR__ . '/data/portal.json');
define('CHANNELS_FILE', __DIR__ . '/data/channels.json');
define('USERS_FILE',    __DIR__ . '/data/users_account.json');


// ─── Data helpers ─────────────────────────────────────────────────────────────
function loadPortal(): ?array {
    if (!file_exists(PORTAL_FILE)) return null;
    $d = json_decode(file_get_contents(PORTAL_FILE), true);
    return (is_array($d) && !empty($d)) ? $d : null;
}


function savePortal(array $d): void {
    @file_put_contents(PORTAL_FILE, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}


function loadChannels(): ?array {
    if (!file_exists(CHANNELS_FILE)) return null;
    $d = json_decode(file_get_contents(CHANNELS_FILE), true);
    return (is_array($d) && !empty($d['channels'])) ? $d : null;
}


function clearData(): void {
    @unlink(PORTAL_FILE);
    @unlink(CHANNELS_FILE);
}


function loadUserAccount(): ?array {
    if (!file_exists(USERS_FILE)) return null;
    $d = json_decode(file_get_contents(USERS_FILE), true);
    return (is_array($d) && !empty($d['username'])) ? $d : null;
}


function saveUserAccount(array $data): void {
    file_put_contents(USERS_FILE, json_encode($data, JSON_PRETTY_PRINT));
}


// ─── Base URL for playlist link (works at any path: /, /nexus/, /stalker/, etc.) ─
$proto = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/');
// Edge-case: dirname returns '/' at root, avoid double-slash
$scriptDir   = ($scriptDir === '/' ? '' : $scriptDir);
$baseUrl     = $proto . '://' . $host;
$playlistUrl = $baseUrl . $scriptDir . '/playlist.php';


// ─── Handle actions & PRG Pattern ─────────────────────────────────────────────
$portal   = loadPortal();
$channels = loadChannels();
$action   = $_POST['action'] ?? '';


// Read and clear flash/modal states from session
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);


$showSyncModal = $_SESSION['show_sync_modal'] ?? false;
unset($_SESSION['show_sync_modal']);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    // ── Connect (add / replace) and Auto-Sync
    if ($action === 'connect') {
        $name  = trim($_POST['name']  ?? '');
        $url   = trim($_POST['url']   ?? '');
        $mac   = trim($_POST['mac']   ?? '');
        $model = trim($_POST['model'] ?? 'MAG250');
        $extras = array_map('trim', [
            'sn_cut'    => $_POST['sn_cut']    ?? '',
            'device_id' => $_POST['device_id'] ?? '',
            'device_id2'=> $_POST['device_id2']?? '',
            'signature' => $_POST['signature'] ?? '',
        ]);


        if (!$name || !$url || !$mac) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Name, URL and MAC are required.'];
        } else {
            $stk    = new StalkerLite($url, $mac, $model, $extras);
            $result = $stk->connect();


            if ($result['success']) {
                @unlink(CHANNELS_FILE);  // clear old channels on portal change
                $newPortal = array_merge(['name' => $name, 'url' => $url, 'mac' => strtoupper($mac), 'model' => $model], $result);
                savePortal($newPortal);
                
                // Auto-sync channels instantly
                set_time_limit(180);
                $chs = $stk->getChannels();


                if (!empty($chs)) {
                    $data = [
                        'fetched_at' => date('Y-m-d H:i:s'),
                        'count'      => count($chs),
                        'channels'   => $chs,
                    ];
                    file_put_contents(CHANNELS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Connected successfully! Playlist is ready with ' . count($chs) . ' channels.'];
                } else {
                    // Sync failed, prompt manual modal
                    $_SESSION['show_sync_modal'] = true;
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Handshake OK, but automatic channel sync failed.'];
                }


            } else {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => $result['error'] ?? 'Handshake failed. Check credentials.'];
            }
        }
        
        // PRG Pattern
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }


    // ── Sync channels (Manual) - Redirect to standalone sync script
    if ($action === 'sync_channels' && $portal) {
        header("Location: sync.php");
        exit;
    }


    // ── Clear everything
    if ($action === 'clear') {
        clearData();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Portal data cleared.'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }


    // ── Toggle stream proxy
    if ($action === 'toggle_proxy') {
        $user = loadUserAccount();
        if ($user) {
            $current = $user['stream_proxy'] ?? 'inactive';
            $user['stream_proxy'] = ($current === 'active') ? 'inactive' : 'active';
            saveUserAccount($user);
            $newState = $user['stream_proxy'];
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Stream proxy ' . ($newState === 'active' ? 'enabled' : 'disabled') . '.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'User account not found.'];
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}


// ─── View helpers ─────────────────────────────────────────────────────────────
$e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$connected = ($portal !== null);
$userAccount = loadUserAccount();
$proxyActive = ($userAccount['stream_proxy'] ?? 'inactive') === 'active';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nexus | Portal Manager</title>
<meta name="description" content="Nexus — Stalker Portal Manager. Connect, sync channels and generate IPTV playlists.">
<link rel="icon" type="image/png" sizes="128x128" href="data/logo.png">
<link rel="apple-touch-icon" href="data/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="data/fontawesome/css/all.min.css">
<style>
/* ── Reset & SaaS Light Theme Tokens ───────────────────────────────────────── */
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

html { color-scheme: light; }

body {
    font-family: 'Inter', system-ui, sans-serif;
    background: var(--bg);
    color: var(--text-main);
    min-height: 100vh;
    font-size: 14px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
}

.hidden { display: none !important; }

/* ── Layout ───────────────────────────────────────────────────────────── */
.page {
    max-width: 600px;
    margin: 0 auto;
    padding: 32px 20px 64px;
    position: relative;
    z-index: 1;
}

/* ── Header ───────────────────────────────────────────────────────────── */
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
.status-badge {
    margin-left: auto;
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 0.75rem; font-weight: 600;
    padding: 6px 12px; border-radius: 99px;
    transition: all 0.2s;
}
.badge-on  { background: var(--success-bg); color: #065f46; border: 1px solid rgba(16,185,129,0.3); }
.badge-off { background: rgba(239,68,68,0.15); color: #fca5a5; border: 1px solid rgba(239,68,68,0.35); box-shadow: none; }
.status-badge i { font-size: 8px; }

/* ── Flash Toast ──────────────────────────────────────────────────────── */
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

/* ── Cards ────────────────────────────────────────────────────────────── */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    margin-bottom: 12px;
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
.ci-cyan   { background: var(--cyan-bg); color: var(--cyan); }
.ci-amber  { background: var(--warning-bg); color: var(--warning); }
.ci-red    { background: var(--danger-bg); color: var(--danger); }
.card-hd-text h2  { font-size: 0.95rem; font-weight: 600; color: var(--text-main); }
.card-hd-text p   { font-size: 0.8rem; color: var(--text-muted); margin-top: 2px; }
.card-bd { padding: 14px 20px; }

/* ── Connected Banner ─────────────────────────────────────────────────── */
.connected-banner {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 14px 20px;
    background: var(--success-bg);
    border-bottom: 1px solid rgba(16,185,129,0.2);
    font-size: 0.85rem; color: #047857; font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.connected-banner:hover { background: #bbf7d0; }
.connected-banner span { color: var(--success); font-weight: 500; margin-left: 2px; }
.connected-banner .hint { color: #065f46; font-weight: 500; font-size: 0.75rem; margin-left: auto; display: flex; align-items: center; gap: 6px; }
.connected-banner .hint i { font-size: 0.9rem; }

/* ── Form Elements ────────────────────────────────────────────────────── */
.field { margin-bottom: 10px; }
.field:last-child { margin-bottom: 0; }
.field label {
    display: block; font-size: 0.75rem; font-weight: 600;
    color: var(--text-main); margin-bottom: 4px;
}
.fw { position: relative; }
.fw i {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    color: var(--text-light); font-size: 0.875rem; pointer-events: none;
    transition: color 0.2s;
}
input[type=text], input[type=url], select {
    width: 100%; background: var(--surface); border: 1px solid var(--border);
    color: var(--text-main); font-family: 'Inter', sans-serif; font-size: 0.875rem;
    padding: 8px 14px 8px 38px; border-radius: var(--radius-md); outline: none;
    transition: all 0.2s ease;
    box-shadow: var(--shadow-sm);
    appearance: none; -webkit-appearance: none;
}
select { padding-left: 14px; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; }
input::placeholder { color: var(--text-light); }
input:focus, select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-bg);
}
.fw:focus-within i { color: var(--primary); }
.mono { font-family: var(--mono) !important; font-size: 0.85rem !important; letter-spacing: -0.02em; }

.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.row2 .full { grid-column: 1 / -1; }

/* Advanced Toggle */
.adv-btn {
    background: none; border: none; color: var(--text-muted);
    font-family: 'Inter', sans-serif; font-size: 0.8rem; font-weight: 500;
    cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 0; margin-top: 4px; transition: color 0.2s;
}
.adv-btn:hover { color: var(--text-main); }
.adv-section { display: none; margin-top: 4px; padding-top: 16px; border-top: 1px dashed var(--border); }
.adv-section.open { display: block; animation: slideDown 0.2s ease-out; }

/* ── Buttons ──────────────────────────────────────────────────────────── */
.btn-row { display: flex; gap: 12px; margin-top: 24px; }
.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 10px 20px; border-radius: var(--radius-md);
    font-size: 0.875rem; font-weight: 600;
    font-family: 'Inter', sans-serif; cursor: pointer; border: none;
    transition: all 0.2s ease; text-decoration: none; white-space: nowrap;
}
.btn-primary {
    background: var(--primary); color: #fff; flex: 1;
    box-shadow: 0 2px 4px rgba(59,130,246,0.3);
}
.btn-primary:hover { background: var(--primary-hov); transform: translateY(-1px); box-shadow: 0 4px 6px rgba(59,130,246,0.4); }

.btn-danger-solid {
    background: var(--danger); color: #fff;
    box-shadow: 0 2px 4px rgba(239,68,68,0.3);
}
.btn-danger-solid:hover { background: #dc2626; transform: translateY(-1px); box-shadow: 0 4px 6px rgba(239,68,68,0.4); }

.btn-danger {
    background: var(--surface); border: 1px solid var(--border); color: var(--danger);
    box-shadow: var(--shadow-sm);
}
.btn-danger:hover { background: var(--danger-bg); border-color: rgba(239,68,68,0.3); }

.btn-ghost {
    background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);
    box-shadow: var(--shadow-sm);
}
.btn-ghost:hover { background: var(--bg); border-color: var(--border-hover); color: var(--text-main); }

.btn-cyan {
    background: var(--surface); border: 1px solid var(--border); color: var(--cyan);
    box-shadow: var(--shadow-sm); flex: 1;
}
.btn-cyan:hover { background: var(--cyan-bg); border-color: rgba(6,182,212,0.3); }

/* Spinner */
.spin-wrap { display: none; }
.spin-wrap i { animation: spin 0.6s linear infinite; }
.loading .spin-wrap { display: inline-flex; }
.loading .btn-label { display: none; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Info Grid ────────────────────────────────────────────────────────── */
.info-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
}
.info-item {
    background: var(--bg);
    padding: 12px 16px;
    border-radius: var(--radius-md);
    border: 1px solid var(--border);
}
.info-item .lbl {
    font-size: 0.7rem; color: var(--text-muted); font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;
}
.info-item .val {
    font-size: 0.875rem; font-weight: 500; color: var(--text-main); word-break: break-all;
}
.val-mono  { font-family: var(--mono); font-size: 0.85rem !important; color: var(--primary) !important; }
.val-green { color: var(--success) !important; }
.val-muted { color: var(--text-muted) !important; font-weight: 400 !important; }
.val-full  { grid-column: 1 / -1; }

/* Token Copy Row */
.copy-row {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.copy-row .token-str {
    font-family: var(--mono); font-size: 0.85rem; color: var(--primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.copy-btn {
    background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);
    cursor: pointer; font-size: 0.75rem; padding: 6px 10px; border-radius: var(--radius-sm);
    transition: all 0.2s; flex-shrink: 0; box-shadow: var(--shadow-sm);
}
.copy-btn:hover { color: var(--primary); border-color: var(--primary); background: var(--primary-bg); }

/* ── Playlist Box ─────────────────────────────────────────────────────── */
.m3u-box {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 16px;
    margin-bottom: 16px;
}
.m3u-box .m3u-label {
    font-size: 0.75rem; color: var(--text-main); font-weight: 600; 
    margin-bottom: 8px; display: flex; align-items: center; gap: 6px;
}
.m3u-box .m3u-label i { color: var(--cyan); }
.m3u-url-row {
    display: flex; align-items: center; gap: 8px;
    background: var(--surface); border: 1px solid var(--border);
    padding: 8px 12px; border-radius: var(--radius-sm);
}
.m3u-url {
    font-family: var(--mono); font-size: 0.8rem; color: var(--text-muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1;
}

/* Channel Count Pill */
.ch-count {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--indigo-bg); border: 1px solid rgba(99,102,241,0.2);
    padding: 6px 14px; border-radius: 99px;
    font-size: 0.8rem; color: var(--indigo); font-weight: 600;
    margin-bottom: 16px;
}
.sync-note { font-size: 0.75rem; color: var(--text-muted); margin-top: 12px; line-height: 1.4; display: flex; gap: 6px; }
.sync-note i { color: var(--text-light); margin-top: 2px; }

/* ── Modal Overlay ────────────────────────────────────────────────────── */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(15,23,42,0.6); backdrop-filter: blur(4px);
    z-index: 999; display: flex; align-items: center; justify-content: center;
    padding: 16px; animation: fadeInOverlay 0.25s ease-out;
}
.modal-overlay.hidden { display: none !important; }
.modal-overlay .card { width: 100%; max-width: 420px; margin: 0; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); animation: modalPop 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.close-btn { background: none; border: none; font-size: 1rem; color: var(--text-light); cursor: pointer; transition: color 0.2s; padding: 4px; margin-left: auto; }
.close-btn:hover { color: var(--text-main); }

/* ── Delete Modal Specific ────────────────────────────────────────────── */
.delete-modal-icon {
    width: 56px; height: 56px;
    background: var(--danger-bg);
    border: 2px solid rgba(239,68,68,0.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px;
    font-size: 1.4rem; color: var(--danger);
}
.delete-modal-title {
    text-align: center;
    font-size: 1.05rem; font-weight: 700;
    color: var(--text-main);
    margin-bottom: 8px;
}
.delete-modal-sub {
    text-align: center;
    font-size: 0.85rem; color: var(--text-muted);
    line-height: 1.6;
    margin-bottom: 20px;
}
.delete-modal-list {
    background: var(--danger-bg);
    border: 1px solid rgba(239,68,68,0.15);
    border-radius: var(--radius-md);
    padding: 12px 16px;
    margin-bottom: 20px;
    list-style: none;
}
.delete-modal-list li {
    font-size: 0.8rem; color: #b91c1c;
    display: flex; align-items: center; gap: 8px;
    padding: 3px 0;
    font-weight: 500;
}
.delete-modal-list li i { font-size: 0.75rem; opacity: 0.8; }
.delete-modal-actions {
    display: flex; gap: 10px;
}
.delete-modal-actions .btn { flex: 1; }

/* ── Animations ───────────────────────────────────────────────────────── */
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes fadeInOverlay {
    from { opacity: 0; }
    to   { opacity: 1; }
}
@keyframes modalPop {
    from { opacity: 0; transform: scale(0.93) translateY(10px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
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

/* ── Toggle Switch (Stream Proxy) ────────────────────────────────────── */
.toggle-card-row {
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
}
.toggle-card-info h3 {
    font-size: 0.9rem; font-weight: 600; color: var(--text-main); margin-bottom: 2px;
}
.toggle-card-info p {
    font-size: 0.78rem; color: var(--text-muted); line-height: 1.4;
}
.toggle-switch {
    position: relative; display: inline-block; width: 52px; height: 28px; flex-shrink: 0;
}
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; cursor: pointer; inset: 0;
    background: #cbd5e1; border-radius: 99px;
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
}
.toggle-slider::before {
    content: ''; position: absolute; left: 3px; bottom: 3px;
    width: 22px; height: 22px; border-radius: 50%;
    background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.15);
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
}
.toggle-switch input:checked + .toggle-slider {
    background: linear-gradient(135deg, var(--primary), var(--indigo));
    box-shadow: 0 0 12px rgba(99,102,241,0.4);
}
.toggle-switch input:checked + .toggle-slider::before {
    transform: translateX(24px);
}
.proxy-badge {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 0.7rem; font-weight: 700; letter-spacing: 0.04em;
    padding: 4px 10px; border-radius: 99px; text-transform: uppercase;
}
.proxy-badge-on  { background: var(--success-bg); color: #065f46; border: 1px solid rgba(16,185,129,0.3); }
.proxy-badge-off { background: var(--bg); color: var(--text-muted); border: 1px solid var(--border);
}

/* ── Mobile ───────────────────────────────────────────────────────────── */
@media (max-width: 500px) {
    .page { padding: 20px 16px 40px; }
    .row2 { grid-template-columns: 1fr; gap: 12px; }
    .row2 .full { grid-column: 1; }
    .info-grid { grid-template-columns: 1fr 1fr; }
    .val-full { grid-column: 1 / -1; }
    .card-hd, .card-bd { padding: 16px; }
    .btn-row { flex-direction: column; }
    .btn-danger { order: 2; }
    .delete-modal-actions { flex-direction: column; }
}
</style>
</head>
<body>
<div class="grid-bg"></div>


<?php if ($showSyncModal): ?>
<div id="sync-modal" class="modal-overlay" onclick="if(event.target===this) this.style.display='none'">
    <div class="card">
        <div class="card-hd">
            <div class="card-hd-icon ci-amber"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="card-hd-text">
                <h2>Channel Sync Delayed</h2>
                <p>Portal is joined, but mapping channels timed out</p>
            </div>
            <button class="close-btn" onclick="document.getElementById('sync-modal').style.display='none'"><i class="fas fa-times"></i></button>
        </div>
        <div class="card-bd">
            <p style="margin-bottom:16px; color:var(--text-muted); font-size:0.875rem;">
                Don't worry, your portal connection is saved securely! The provider might be slow to respond right now. Please try fetching the channels manually.
            </p>
            <form method="post">
                <input type="hidden" name="action" value="sync_channels">
                <button type="submit" class="btn btn-primary" style="width:100%" id="modal-sync-btn" onclick="setLoading('modal-sync-btn')">
                    <span class="spin-wrap"><i class="fas fa-circle-notch"></i></span>
                    <span class="btn-label"><i class="fas fa-sync-alt"></i> Try Syncing Now</span>
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>


<!-- ── Delete Confirmation Modal ──────────────────────────────────────────── -->
<div id="delete-modal" class="modal-overlay hidden" onclick="if(event.target===this) closeDeleteModal()">
    <div class="card">
        <div class="card-hd">
            <div class="card-hd-icon ci-red"><i class="fas fa-trash-alt"></i></div>
            <div class="card-hd-text">
                <h2>Delete Everything</h2>
                <p>This action cannot be undone</p>
            </div>
            <button class="close-btn" onclick="closeDeleteModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="card-bd">
            <div class="delete-modal-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="delete-modal-title">Are you absolutely sure?</div>
            <div class="delete-modal-sub">
                You are about to permanently wipe all portal data from this instance. The following will be erased:
            </div>
            <ul class="delete-modal-list">
                <li><i class="fas fa-circle"></i> Portal credentials &amp; auth token</li>
                <li><i class="fas fa-circle"></i> All synced channel data</li>
                <li><i class="fas fa-circle"></i> Playlist cache &amp; configuration</li>
            </ul>
            <div class="delete-modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeDeleteModal()">
                    <i class="fas fa-arrow-left"></i> Cancel
                </button>
                <form method="post" style="flex:1; display:flex;">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="btn btn-danger-solid" style="width:100%;" id="confirm-delete-btn" onclick="setLoading('confirm-delete-btn')">
                        <span class="spin-wrap"><i class="fas fa-circle-notch"></i></span>
                        <span class="btn-label"><i class="fas fa-trash-alt"></i> Yes, Delete All</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>


<div class="page">


<div class="hd">
    <img src="data/logo.png" alt="Nexus" class="hd-logo" onerror="this.style.display='none'">
    <div class="hd-text">
        <h1>Nexus</h1>
        <p>Stalker Portal Manager</p>
    </div>
    <div style="margin-left: auto; display: flex; align-items: center; gap: 10px;">
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="logout">
            <button type="submit" title="Logout" style="background:none; border:none; color:rgba(255,255,255,0.7); cursor:pointer; font-size:1rem; padding:4px; transition: color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.7)'">
                <i class="fas fa-sign-out-alt"></i>
            </button>
        </form>
    </div>
</div>


<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'success' ? 'flash-ok' : 'flash-err' ?>">
    <i class="fas <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
    <span><?= $e($flash['msg']) ?></span>
</div>
<?php endif; ?>


<div class="card fade">
    <?php if ($connected): ?>
    <div class="connected-banner" onclick="document.getElementById('portal-form-wrap').classList.toggle('hidden')">
        <div style="display:flex; align-items:center; gap:8px;">
            <i class="fas fa-check-circle"></i> Connected: <span><?= $e($portal['name']) ?></span>
        </div>
        <div class="hint">Config <i class="fas fa-chevron-down"></i></div>
    </div>
    <?php endif; ?>


    <div id="portal-form-wrap" class="<?= $connected ? 'hidden' : '' ?>">
        <div class="card-hd">
            <div class="card-hd-icon ci-blue"><i class="fas fa-plug"></i></div>
            <div class="card-hd-text">
                <h2>Portal Setup</h2>
                <p><?= $connected ? 'Update your portal configuration' : 'Enter your Stalker portal credentials' ?></p>
            </div>
        </div>


        <div class="card-bd">
            <form id="conn-form" method="post" action="">


                <div class="row2">
                    <div class="field">
                        <label>Portal Name</label>
                        <div class="fw"><i class="fas fa-tag"></i>
                            <input type="text" name="name" placeholder="My Portal" required
                                   value="<?= $e($portal['name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="field">
                        <label>Device Model</label>
                        <select name="model">
                            <?php foreach (['MAG250','MAG254','MAG256','MAG322','MAG324','MAG349','MAG351','MAG420','MAG424'] as $m): ?>
                                <option value="<?= $m ?>" <?= (($portal['model'] ?? 'MAG250') === $m) ? 'selected' : '' ?>>
                                    <?= $m ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>


                    <div class="field full">
                        <label>Portal URL</label>
                        <div class="fw"><i class="fas fa-link"></i>
                            <input type="url" name="url" placeholder="http://example.com/stalker_portal/c/" required
                                   value="<?= $e($portal['url'] ?? '') ?>">
                        </div>
                    </div>


                    <div class="field full">
                        <label>MAC Address</label>
                        <div class="fw"><i class="fas fa-network-wired"></i>
                            <input type="text" name="mac" id="mac-in" class="mono"
                                   placeholder="00:1A:79:XX:XX:XX"
                                   pattern="[0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5}" required
                                   value="<?= $e($portal['mac'] ?? '') ?>">
                        </div>
                    </div>
                </div>


                <button type="button" class="adv-btn" onclick="toggleAdv(this)">
                    <i class="fas fa-sliders-h" id="adv-arr"></i> Advanced Configuration
                </button>
                <div class="adv-section" id="adv-sec">
                    <div class="row2">
                        <div class="field">
                            <label>SN Cut</label>
                            <div class="fw"><i class="fas fa-barcode"></i>
                                <input type="text" name="sn_cut" class="mono" placeholder="Auto"
                                       value="<?= $e($portal['device']['snCut'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="field">
                            <label>Signature</label>
                            <div class="fw"><i class="fas fa-key"></i>
                                <input type="text" name="signature" class="mono" placeholder="Auto"
                                       value="<?= $e($portal['device']['signature'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="field">
                            <label>Device ID</label>
                            <div class="fw"><i class="fas fa-microchip"></i>
                                <input type="text" name="device_id" class="mono" placeholder="Auto"
                                       value="<?= $e($portal['device']['deviceId'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="field">
                            <label>Device ID 2</label>
                            <div class="fw"><i class="fas fa-microchip"></i>
                                <input type="text" name="device_id2" class="mono" placeholder="Auto"
                                       value="<?= $e($portal['device']['deviceId2'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>


                <div class="btn-row">
                    <button type="submit" name="action" value="connect" class="btn btn-primary" id="conn-btn" onclick="setLoading('conn-btn')">
                        <span class="spin-wrap"><i class="fas fa-circle-notch"></i></span>
                        <span class="btn-label"><i class="fas fa-link"></i>
                            <?= $connected ? 'Save Changes & Update All' : 'Connect & Fetch Playlist' ?>
                        </span>
                    </button>
                    <?php if ($connected): ?>
                        <!-- Triggers custom modal instead of browser confirm() -->
                        <button type="button" class="btn btn-danger" title="Disconnect & Clear Data" onclick="openDeleteModal()">
                            <i class="fas fa-trash-alt"></i> Delete Everything
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>


<?php if ($connected): ?>
<div class="card fade" style="animation-delay: 0.1s; animation-fill-mode: both;">
    <div class="card-hd" style="cursor: pointer; display: flex; align-items: center;" onclick="document.getElementById('conn-status-bd').classList.toggle('hidden'); const i=this.querySelector('.fa-chevron-down, .fa-chevron-up'); if(i){i.classList.toggle('fa-chevron-down'); i.classList.toggle('fa-chevron-up');}">
        <div class="card-hd-icon ci-green"><i class="fas fa-user-circle"></i></div>
        <div class="card-hd-text" style="flex: 1;">
            <h2>Profile Details</h2>
            <p style="font-size: 0.8rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                <?php if (!empty($portal['profile']['expiry'])): ?>
                    Expires: <span style="font-weight:600; color:var(--text-main);"><?= $e(date('d/m/Y', strtotime($portal['profile']['expiry']))) ?></span>
                <?php else: ?>
                    Auth: <span style="font-weight:600; color:var(--text-main);"><?= $e(date('d/m/Y', strtotime($portal['saved_at'] ?? 'now'))) ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div style="flex-shrink: 0; display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 0.7rem; text-transform:uppercase; letter-spacing:0.05em; color: var(--primary); font-weight: 700;">Details</span>
            <i class="fas fa-chevron-down" style="color:var(--text-light); font-size: 0.85rem;"></i>
        </div>
    </div>
    <div id="conn-status-bd" class="card-bd hidden">
        <div class="info-grid">
            <div class="info-item val-full">
                <div class="lbl">Authorization Token</div>
                <div class="val val-mono" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= $e($portal['token'] ?? '') ?>"><?= $e($portal['token'] ?? '') ?></div>
            </div>


            <?php if (!empty($portal['profile']['name'])): ?>
            <div class="info-item val-full" style="background:var(--primary-bg); border-color:#bfdbfe;">
                <div class="lbl" style="color:var(--primary);">Account Owner</div>
                <div class="val" style="color:var(--text-main); font-weight:700; font-size:1.05rem;"><?= $e($portal['profile']['name']) ?></div>
            </div>
            <?php endif; ?>


            <div class="info-item">
                <div class="lbl">MAC Address</div>
                <div class="val val-mono"><?= $e($portal['mac'] ?? '') ?></div>
            </div>
            <div class="info-item">
                <div class="lbl">Device Model</div>
                <div class="val val-muted"><?= $e($portal['model'] ?? 'MAG250') ?></div>
            </div>


            <?php if (!empty($portal['profile']['login'])): ?>
            <div class="info-item">
                <div class="lbl">Account Login</div>
                <div class="val val-green" style="font-weight: 600; font-size: 0.95rem;"><?= $e($portal['profile']['login']) ?></div>
            </div>
            <?php endif; ?>


            <?php if (!empty($portal['profile']['id'])): ?>
            <div class="info-item" style="background:var(--primary-bg); border-color:#bfdbfe;">
                <div class="lbl" style="color:var(--primary);">Profile ID</div>
                <div class="val val-mono" style="color:var(--text-main); font-weight:700; font-size:0.95rem;"><?= $e($portal['profile']['id']) ?></div>
            </div>
            <?php endif; ?>


            <?php if (!empty($portal['profile']['tariff'])): ?>
            <div class="info-item val-full">
                <div class="lbl">Tariff Plan</div>
                <div class="val val-muted"><?= $e($portal['profile']['tariff']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>


<div class="card fade" style="animation-delay: 0.2s; animation-fill-mode: both; background: #0f172a; border: 1px solid #1e293b; color: #fff;">
    <div class="card-hd" style="background: rgba(255,255,255,0.05); border-bottom: 1px solid #1e293b;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div class="card-hd-icon" style="background: #1e293b; color: #38bdf8;"><i class="fas fa-list-ul"></i></div>
            <div class="card-hd-text">
                <h2 style="color: #fff;">Playlist M3U <?= $channels ? '(Channels: ' . number_format($channels['count']) . ')' : '' ?></h2>
                <p style="color: rgba(255,255,255,0.6); font-weight: 500;"><?= $channels ? 'Last Sync: ' . $e(date('d/m/Y g:ia', strtotime($channels['fetched_at']))) : 'Playlist not generated yet' ?></p>
            </div>
        </div>
    </div>
    <div class="card-bd" style="padding: 14px 20px;">
        <div class="m3u-url-row" style="margin-bottom: 12px; display: block; text-align: center; border: 1px dashed rgba(255,255,255,0.2); background: rgba(255,255,255,0.03); padding: 10px 12px; border-radius: var(--radius-sm);">
            <span class="m3u-url" id="m3u-url" title="<?= $e($playlistUrl) ?>" style="display: block; width: 100%; color: #38bdf8; font-weight: 600; font-family: var(--mono);"><?= $e($playlistUrl) ?></span>
        </div>
        <div style="display: flex; justify-content: center; gap: 10px; margin-bottom: 15px;">
            <button class="copy-btn" onclick="cp('<?= $e($playlistUrl) ?>', this)" title="Copy URL" style="padding: 7px 18px; border-radius: 99px; font-weight: 600; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.1); color: #fff;">
                <i class="fas fa-copy" style="margin-right: 6px; color: #38bdf8;"></i> Copy URL
            </button>
            <a href="<?= $e($playlistUrl) ?>?dl=1" class="copy-btn" title="Download Playlist File" style="text-decoration: none; padding: 7px 18px; border-radius: 99px; font-weight: 600; color: #fff; border: 1px solid #38bdf8; background: rgba(56, 189, 248, 0.1);">
                <i class="fas fa-download" style="margin-right: 6px; color: #38bdf8;"></i> Download M3U
            </a>
        </div>
        <form method="post" id="sync-form">
            <input type="hidden" name="action" value="sync_channels">
            <button type="submit" class="btn" id="sync-btn" style="width: 100%; background: #38bdf8; color: #0f172a; border-radius: var(--radius-md);">
                <span class="spin-wrap"><i class="fas fa-circle-notch"></i></span>
                <span class="btn-label">
                    <i class="fas fa-sync-alt" style="margin-right: 4px;"></i>
                    <?= $channels ? 'Force Resync Playlist' : 'Fetch Channels Now' ?>
                </span>
            </button>
        </form>
        <div class="sync-note" style="margin-top: 10px; color: rgba(255,255,255,0.4);">
            <i class="fas fa-info-circle" style="color: #38bdf8;"></i>
            <div style="font-size: 0.75rem;">If streams stop working, force resync data.</div>
        </div>
    </div>
</div>


<!-- ── Stream Proxy Toggle Card ──────────────────────────────────────────── -->
<div class="card fade" style="animation-delay: 0.3s; animation-fill-mode: both;">
    <div class="card-hd" style="padding: 12px 20px;">
        <div class="card-hd-icon ci-blue" style="background: <?= $proxyActive ? 'var(--indigo-bg)' : 'var(--primary-bg)' ?>; color: <?= $proxyActive ? 'var(--indigo)' : 'var(--primary)' ?>;"><i class="fas fa-shield-alt"></i></div>
        <div class="card-hd-text" style="flex: 1;">
            <h2>Stream Proxy</h2>
            <p><?= $proxyActive ? 'Streams via your server' : 'Direct CDN redirect' ?></p>
        </div>
        <form method="post" id="proxy-form" style="display:flex; align-items:center; gap: 10px; margin:0;">
            <input type="hidden" name="action" value="toggle_proxy">
            <label class="toggle-switch">
                <input type="checkbox" <?= $proxyActive ? 'checked' : '' ?> onchange="document.getElementById('proxy-form').submit();">
                <span class="toggle-slider"></span>
            </label>
        </form>
    </div>
    <div class="card-bd" style="padding: 10px 20px 14px; border-top: 1px solid var(--border);">
        <?php if ($proxyActive): ?>
        <div style="display:flex; gap:10px; align-items:flex-start;">
            <i class="fas fa-info-circle" style="color:var(--indigo); margin-top:2px; flex-shrink:0;"></i>
            <p style="font-size:0.78rem; color:var(--text-muted); line-height:1.45; margin:0;">
                <strong style="color:var(--text-main);">Proxy ON</strong> — All stream data flows through your server. The original CDN URL is hidden from the player app. Uses your server's bandwidth.
            </p>
        </div>
        <?php else: ?>
        <div style="display:flex; gap:10px; align-items:flex-start;">
            <i class="fas fa-info-circle" style="color:var(--text-light); margin-top:2px; flex-shrink:0;"></i>
            <p style="font-size:0.78rem; color:var(--text-muted); line-height:1.45; margin:0;">
                <strong style="color:var(--text-main);">Proxy OFF</strong> — Player is redirected directly to the CDN. Video never touches your server. Zero bandwidth usage.
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>


<div style="position: fixed; bottom: 0; left: 0; width: 100%; text-align: center; color: var(--text-muted); font-size: 0.75rem; padding: 12px; font-weight: 500; background: var(--bg); border-top: 1px solid var(--border); z-index: 50;">
    Crafted with <span style="color: var(--danger);">❤️</span> by <strong style="color: var(--text-main); font-weight: 700;">LazyyXD</strong> &middot; Nexus &middot; V1.0 &middot; <?= date('Y') ?>
</div>


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

// Advanced toggle
function toggleAdv(btn) {
    const sec = document.getElementById('adv-sec');
    const arr = document.getElementById('adv-arr');
    sec.classList.toggle('open');
    if (sec.classList.contains('open')) {
        arr.classList.replace('fa-sliders-h', 'fa-times');
    } else {
        arr.classList.replace('fa-times', 'fa-sliders-h');
    }
}

// Copy helper (with fallback for HTTP)
function cp(text, btn) {
    const orig = btn.innerHTML;
    const showOk = () => {
        btn.innerHTML = '<i class="fas fa-check" style="color:var(--success)"></i> Copied!';
        setTimeout(() => btn.innerHTML = orig, 1600);
    };
    const showFail = () => {
        btn.innerHTML = '<i class="fas fa-times" style="color:var(--danger)"></i> Failed';
        setTimeout(() => btn.innerHTML = orig, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text.trim()).then(showOk).catch(showFail);
    } else {
        // Fallback for HTTP
        const ta = document.createElement('textarea');
        ta.value = text.trim();
        ta.style.cssText = 'position:fixed;left:-9999px;';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); showOk(); }
        catch(e) { showFail(); }
        ta.remove();
    }
}

// Loading state
function setLoading(btnId) {
    const btn = document.getElementById(btnId);
    if (btn) btn.classList.add('loading');
}

document.getElementById('conn-form').addEventListener('submit', () => setLoading('conn-btn'));
<?php if ($connected): ?>
document.getElementById('sync-form').addEventListener('submit', () => setLoading('sync-btn'));
<?php endif; ?>

// MAC auto-format
const macIn = document.getElementById('mac-in');
if (macIn) {
    macIn.addEventListener('input', function() {
        let v = this.value.replace(/[^0-9A-Fa-f]/g, '').slice(0, 12);
        this.value = (v.match(/.{1,2}/g) || []).join(':').toUpperCase();
    });
}

// ── Delete Modal ──────────────────────────────────────────────────────────────
function openDeleteModal() {
    const modal = document.getElementById('delete-modal');
    modal.classList.remove('hidden');
    // Re-trigger animation
    const card = modal.querySelector('.card');
    card.style.animation = 'none';
    card.offsetHeight; // reflow
    card.style.animation = '';
    // Trap scroll
    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    const modal = document.getElementById('delete-modal');
    modal.classList.add('hidden');
    document.body.style.overflow = '';
}

// Close on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeDeleteModal();
});

// ── Idle Session Timeout (5 min) ─────────────────────────────────────────────
(function() {
    const IDLE_MS = 5 * 60 * 1000;
    let timer;
    function resetIdle() {
        clearTimeout(timer);
        timer = setTimeout(() => { window.location.href = 'login.php'; }, IDLE_MS);
    }
    ['click','keypress','scroll','mousemove','touchstart'].forEach(evt =>
        document.addEventListener(evt, resetIdle, { passive: true })
    );
    resetIdle();
})();
</script>
</body>
</html>
