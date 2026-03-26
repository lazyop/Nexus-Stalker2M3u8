<?php
/**
 * Stalker Sync Handler
 * Handles channel synchronization for StalkerLite
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth Guard
if (empty($_SESSION['nexus_user'])) {
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/StalkerLite.php';

define('PORTAL_FILE',   __DIR__ . '/data/portal.json');
define('CHANNELS_FILE', __DIR__ . '/data/channels.json');

// ── Long Timeout for many channels ──────────────────────────────────────────
set_time_limit(180); // 3 minutes

function loadPortal() {
    if (!file_exists(PORTAL_FILE)) return null;
    return json_decode(file_get_contents(PORTAL_FILE), true);
}

$portal = loadPortal();

if (!$portal) {
    if (($_GET['format'] ?? '') === 'json') {
        die(json_encode(['success' => false, 'error' => 'No portal configured']));
    }
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'No portal configured. Please connect first.'];
    header('Location: index.php');
    exit;
}

try {
    $stk = new StalkerLite(
        $portal['url'],
        $portal['mac'],
        $portal['model'] ?? 'MAG250',
        $portal['device'] ?? [],
        $portal['token']  ?? ''
    );

    // Perform Sync
    $chs = $stk->getChannels();

    if (!empty($chs)) {
        // Save channels
        $data = [
            'fetched_at' => date('Y-m-d H:i:s'),
            'count'      => count($chs),
            'channels'   => $chs,
        ];
        file_put_contents(CHANNELS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // Update portal token if it changed
        $newToken = $stk->getToken();
        if ($newToken && $newToken !== ($portal['token'] ?? '')) {
            $portal['token'] = $newToken;
            $portal['saved_at'] = date('Y-m-d H:i:s');
            file_put_contents(PORTAL_FILE, json_encode($portal, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $msg = count($chs) . ' channels synced successfully.';
        if (($_GET['format'] ?? '') === 'json') {
            die(json_encode(['success' => true, 'message' => $msg, 'count' => count($chs)]));
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
    } else {
        $msg = 'No channels received. The portal might be empty or the token expired.';
        if (($_GET['format'] ?? '') === 'json') {
            die(json_encode(['success' => false, 'error' => $msg]));
        }
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $msg];
    }
} catch (Exception $e) {
    if (($_GET['format'] ?? '') === 'json') {
        die(json_encode(['success' => false, 'error' => $e->getMessage()]));
    }
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Sync Error: ' . $e->getMessage()];
}

header('Location: index.php');
exit;
