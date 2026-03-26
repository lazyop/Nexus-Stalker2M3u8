<?php
/**
 * Nexus — IPTV M3U Playlist Generator + Stream Proxy
 *
 * GET  /playlist.php                → returns full M3U8 playlist
 * GET  /playlist.php?id=78132       → resolves channel → 302 or proxy (based on setting)
 * GET  /playlist.php?segment=ENC    → proxy a .ts segment (when proxy active)
 * GET  /playlist.php?chunks=ENC     → proxy a sub-manifest / key (when proxy active)
 *
 * Compatible with: TiviMate · OTT Navigator · VLC · GSE Smart IPTV · Kodi · any IPTV app
 */

require_once __DIR__ . '/StalkerLite.php';

date_default_timezone_set('Asia/Kolkata');

define('PORTAL_FILE',   __DIR__ . '/data/portal.json');
define('CHANNELS_FILE', __DIR__ . '/data/channels.json');
define('USERS_FILE',    __DIR__ . '/data/users_account.json');

// ─── XOR key — loaded from user account (unique per install) ──────────────────
function getXorKey(): string {
    if (!file_exists(USERS_FILE)) { http_response_code(503); die('No user account configured.'); }
    $d = json_decode(file_get_contents(USERS_FILE), true);
    if (empty($d['xor_key'])) { http_response_code(503); die('XOR key not found in user account.'); }
    return $d['xor_key'];
}

// ─── Load helpers ─────────────────────────────────────────────────────────────
function loadPortal(): ?array {
    if (!file_exists(PORTAL_FILE)) return null;
    $d = json_decode(file_get_contents(PORTAL_FILE), true);
    return (is_array($d) && !empty($d)) ? $d : null;
}

function loadChannels(): ?array {
    if (!file_exists(CHANNELS_FILE)) return null;
    $d = json_decode(file_get_contents(CHANNELS_FILE), true);
    return (is_array($d) && !empty($d['channels'])) ? $d : null;
}

function isProxyActive(): bool {
    if (!file_exists(USERS_FILE)) return false;
    $d = json_decode(file_get_contents(USERS_FILE), true);
    return (is_array($d) && ($d['stream_proxy'] ?? 'inactive') === 'active');
}

// ─── XOR encrypt/decrypt for URL obfuscation ──────────────────────────────────
function xorEncode(string $data): string {
    $key = getXorKey();
    $out = '';
    for ($i = 0; $i < strlen($data); $i++) {
        $out .= chr(ord($data[$i]) ^ ord($key[$i % strlen($key)]));
    }
    return rtrim(base64_encode($out), '=');
}

function xorDecode(string $encoded): string {
    $key = getXorKey();
    // Re-pad base64
    $mod = strlen($encoded) % 4;
    if ($mod) $encoded .= str_repeat('=', 4 - $mod);
    $data = base64_decode($encoded);
    if ($data === false) return '';
    $out = '';
    for ($i = 0; $i < strlen($data); $i++) {
        $out .= chr(ord($data[$i]) ^ ord($key[$i % strlen($key)]));
    }
    return $out;
}

// ─── Stream proxy headers (MAG box user agent) ───────────────────────────────
function getStreamHeaders(): array {
    return [
        'User-Agent: Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 (KHTML, like Gecko) MAG200 stbapp ver: 2 rev: 250 Safari/533.3',
        'Accept: */*',
        'Connection: keep-alive',
    ];
}

// ─── Helper: get base URL from a full stream URL ─────────────────────────────
function getBaseUrlFromStream(string $url): string {
    $parsed = parse_url($url);
    $base = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');
    if (!empty($parsed['port'])) $base .= ':' . $parsed['port'];
    $path = $parsed['path'] ?? '';
    $dir = dirname($path);
    if ($dir !== '/' && $dir !== '\\') $base .= $dir;
    return rtrim($base, '/');
}

// ─── Helper: resolve relative URLs ──────────────────────────────────────────
function resolveRelativeUrl(string $url, string $baseUrl, bool $isKeyTag = false): string {
    if ($isKeyTag && preg_match('/URI="([^"]+)"/', $url, $m)) $url = $m[1];
    $url = trim($url);
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) return $url;
    if (str_starts_with($url, '/')) {
        $parsed = parse_url($baseUrl);
        $host = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');
        if (!empty($parsed['port'])) $host .= ':' . $parsed['port'];
        return $host . $url;
    }
    return $baseUrl . '/' . $url;
}

// ─── Helper: check if URL is a segment ───────────────────────────────────────
function isSegment(string $url): bool {
    $url = strtolower(trim($url));
    return str_ends_with($url, '.ts') || str_ends_with($url, '.aac') || str_ends_with($url, '.mp4')
        || str_contains($url, '.ts?') || (str_contains($url, '/seg') && !str_contains($url, '.m3u'));
}

// ─── Self URL ─────────────────────────────────────────────────────────────────
$proto = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
$self  = $proto . '://' . $host . ($_SERVER['SCRIPT_NAME'] ?? '/playlist.php');


// ═════════════════════════════════════════════════════════════════════════════
// PROXY MODE: Segment streaming (?segment=ENCRYPTED)
// Streams a .ts segment through the server — no buffering
// ═════════════════════════════════════════════════════════════════════════════
if (!empty($_GET['segment'])) {
    $url = xorDecode($_GET['segment']);
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        die('Invalid segment URL');
    }

    set_time_limit(0);
    header('Content-Type: video/mp2t');
    header('Cache-Control: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('X-Accel-Buffering: no');

    while (ob_get_level()) ob_end_flush();
    ini_set('zlib.output_compression', 'Off');

    $headersSent = false;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPHEADER     => getStreamHeaders(),
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_BUFFERSIZE     => 65536,
        CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$headersSent) {
            if (!$headersSent && stripos($header, 'Content-Length:') === 0) {
                header(trim($header));
            }
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION  => function($ch, $data) use (&$headersSent) {
            if (!$headersSent) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode !== 200 && $httpCode !== 0) return 0;
                $headersSent = true;
            }
            echo $data;
            flush();
            return strlen($data);
        },
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    if (!$result && !$headersSent) {
        http_response_code(502);
        echo 'Segment fetch failed';
    }
    exit;
}


// ═════════════════════════════════════════════════════════════════════════════
// PROXY MODE: Sub-manifest / key streaming (?chunks=ENCRYPTED)
// ═════════════════════════════════════════════════════════════════════════════
if (!empty($_GET['chunks'])) {
    $url = xorDecode($_GET['chunks']);
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        die('Invalid chunk URL');
    }

    // Detect if this is a key request
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $isKey = (str_contains($requestUri, '.key') || str_contains($requestUri, 'key'));

    if ($isKey) {
        // Proxy DRM key
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => getStreamHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || empty($data)) {
            http_response_code(502);
            die('Key fetch failed');
        }
        header('Content-Type: application/octet-stream');
        header('Cache-Control: no-cache');
        header('Access-Control-Allow-Origin: *');
        echo $data;
        exit;
    }

    // Proxy sub-manifest (fetch, rewrite URLs, serve)
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPHEADER     => getStreamHeaders(),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || empty($data)) {
        // Fallback: redirect
        header('Location: ' . $url);
        exit;
    }

    processAndServeManifest($data, $url, $self);
    exit;
}


// ═════════════════════════════════════════════════════════════════════════════
// Helper: Process HLS manifest, rewrite URLs through proxy
// ═════════════════════════════════════════════════════════════════════════════
function processAndServeManifest(string $data, string $sourceUrl, string $selfUrl): void {
    if (strpos($data, '#EXTM3U') === false && strpos($data, '#EXT-X') === false) {
        header('Location: ' . $sourceUrl);
        exit;
    }

    $baseUrl = getBaseUrlFromStream($sourceUrl);
    $lines   = explode("\n", $data);
    $newLines = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) { $newLines[] = ''; continue; }

        if (str_starts_with($line, '#')) {
            // Rewrite key URIs
            if (str_contains($line, 'URI=')) {
                $fullKeyUrl = resolveRelativeUrl($line, $baseUrl, true);
                $encrypted  = xorEncode($fullKeyUrl);
                $newLines[] = '#EXT-X-KEY:METHOD=AES-128,URI="' . $selfUrl . '?chunks=' . urlencode($encrypted) . '"';
            } else {
                $newLines[] = $line;
            }
            continue;
        }

        // Segment or sub-manifest
        $fullUrl   = resolveRelativeUrl($line, $baseUrl);
        $encrypted = xorEncode($fullUrl);

        if (isSegment($line)) {
            $newLines[] = $selfUrl . '?segment=' . urlencode($encrypted);
        } else {
            $newLines[] = $selfUrl . '?chunks=' . urlencode($encrypted);
        }
    }

    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache, no-store');
    header('Access-Control-Allow-Origin: *');
    echo implode("\n", $newLines);
    exit;
}


// ═════════════════════════════════════════════════════════════════════════════
// Helper: Smart proxy — single GET, auto-detect manifest vs binary
// ═════════════════════════════════════════════════════════════════════════════
function smartProxyStream(string $url, string $selfUrl): void {
    set_time_limit(0);

    $isPlaylist   = null;
    $headersSent  = false;
    $playlistData = '';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPHEADER     => getStreamHeaders(),
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_BUFFERSIZE     => 65536,
        CURLOPT_WRITEFUNCTION  => function($ch, $data) use (&$isPlaylist, &$playlistData, &$headersSent) {
            if ($isPlaylist === null) {
                $str = trim(substr($data, 0, 15));
                $isPlaylist = (str_starts_with($str, '#EXTM3U') || str_starts_with($str, '#EXT'));
            }
            if ($isPlaylist) {
                $playlistData .= $data;
            } else {
                if (!$headersSent) {
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if ($httpCode !== 200 && $httpCode !== 0) return 0;
                    header('Content-Type: video/mp2t');
                    header('Cache-Control: no-cache');
                    header('Access-Control-Allow-Origin: *');
                    header('X-Accel-Buffering: no');
                    while (ob_get_level()) ob_end_flush();
                    $headersSent = true;
                }
                echo $data;
                flush();
            }
            return strlen($data);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);

    if ($isPlaylist && !empty($playlistData)) {
        processAndServeManifest($playlistData, $url, $selfUrl);
    }
    exit;
}


// ═════════════════════════════════════════════════════════════════════════════
// MODE A: Stream resolver (?id=CHANNEL_ID)
// Resolves channel → either 302 redirect (proxy OFF) or proxy stream (proxy ON)
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_GET['id'])) {
    $channelId = $_GET['id'] ?? '';

    if (empty($channelId)) {
        http_response_code(400);
        die('Missing channel ID');
    }

    $portal = loadPortal();
    if (!$portal) {
        http_response_code(503);
        die('No portal configured');
    }

    $channels = loadChannels();
    if (!$channels) {
        http_response_code(503);
        die('No channels found');
    }

    // Find channel by ID
    $cmd = '';
    foreach ($channels['channels'] as $ch) {
        if (($ch['id'] ?? '') === $channelId) {
            $cmd = $ch['cmd'] ?? '';
            break;
        }
    }

    if (empty($cmd)) {
        http_response_code(404);
        die('Channel not found');
    }

    // Initialise StalkerLite with the cached token — no new handshake needed
    $stk = new StalkerLite(
        $portal['url'],
        $portal['mac'],
        $portal['model']  ?? 'MAG250',
        $portal['device'] ?? [],
        $portal['token']  ?? ''
    );

    $streamUrl = $stk->createLink($cmd);

    if (empty($streamUrl)) {
        // Token may have expired — try a fresh handshake once
        $fresh = $stk->handshake();
        if ($fresh['success']) {
            // Persist fresh token
            $portal['token']    = $fresh['token'];
            $portal['random']   = $fresh['random'];
            $portal['saved_at'] = date('Y-m-d H:i:s');
            @file_put_contents(PORTAL_FILE, json_encode($portal, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $stk->getProfile(); // initialise session
            $streamUrl = $stk->createLink($cmd);
        }
    }

    if (empty($streamUrl)) {
        http_response_code(502);
        die('Could not resolve stream URL');
    }

    // ─── Check proxy setting ─────────────────────────────────────────────
    if (isProxyActive()) {
        // Proxy mode: route stream through this server
        $lowerUrl = strtolower($streamUrl);
        if (strpos($lowerUrl, '.m3u8') !== false || strpos($lowerUrl, '.m3u') !== false) {
            // HLS manifest — fetch, rewrite, serve
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $streamUrl,
                CURLOPT_HTTPHEADER     => getStreamHeaders(),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_ENCODING       => '',
            ]);
            $data = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && !empty($data)) {
                processAndServeManifest($data, $streamUrl, $self);
            } else {
                header('Location: ' . $streamUrl, true, 302);
            }
            exit;
        } elseif (strpos($lowerUrl, '.ts') !== false) {
            // Direct TS segment — proxy stream it
            smartProxyStream($streamUrl, $self);
            exit;
        } else {
            // Unknown format — smart detect
            smartProxyStream($streamUrl, $self);
            exit;
        }
    }

    // Direct mode: 302 redirect — stream goes from CDN to app, zero proxy
    header('Location: ' . $streamUrl, true, 302);
    exit;
}


// ═════════════════════════════════════════════════════════════════════════════
// MODE B: Generate M3U Playlist
// ═════════════════════════════════════════════════════════════════════════════
$portal   = loadPortal();
$channels = loadChannels();

if (!$portal) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "# ERROR: No portal configured.\n";
    $managerUrl = $proto . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/playlist.php'), '/') . '/index.php';
    echo "# Visit: " . $managerUrl . "\n";
    exit;
}

if (!$channels) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "# ERROR: No channels found.\n";
    echo "# Visit the manager page and click 'Fetch Channels Now' first.\n";
    exit;
}

// ─── Output M3U ───────────────────────────────────────────────────────────────
$portalName = $portal['name'] ?? 'Stalker Portal';
$fetched    = $channels['fetched_at'] ?? '';
$count      = count($channels['channels']);
$proxyOn    = isProxyActive();

if (isset($_GET['dl']) && $_GET['dl'] == '1') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_-]/i', '_', $portalName) . '.m3u"');
} else {
    header('Content-Type: application/x-mpegurl; charset=utf-8');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^a-z0-9_-]/i', '_', $portalName) . '.m3u"');
}
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
header('Access-Control-Allow-Origin: *');

echo '#EXTM3U' . "\n";
echo '#' . "\n";
echo '#  ███╗░░██╗███████╗██╗░░██╗██╗░░░██╗░██████╗' . "\n";
echo '#  ████╗░██║██╔════╝╚██╗██╔╝██║░░░██║██╔════╝' . "\n";
echo '#  ██╔██╗██║█████╗░░░╚███╔╝░██║░░░██║╚█████╗░' . "\n";
echo '#  ██║╚████║██╔══╝░░░██╔██╗░██║░░░██║░╚═══██╗' . "\n";
echo '#  ██║░╚███║███████╗██╔╝╚██╗╚██████╔╝██████╔╝' . "\n";
echo '#  ╚═╝░░╚══╝╚══════╝╚═╝░░╚═╝░╚═════╝░╚═════╝░' . "\n";
echo '#' . "\n";
echo '#  Nexus — Stalker Portal Manager' . "\n";
echo '#  Portal   : ' . $portalName . "\n";
echo '#  Channels : ' . $count . "\n";
echo '#  Proxy    : ' . ($proxyOn ? 'ON' : 'OFF') . "\n";
echo '#  Fetched  : ' . $fetched . "\n";
echo '#  Generated: ' . date('Y-m-d H:i:s') . "\n";
echo '#' . "\n";
echo "\n";

foreach ($channels['channels'] as $ch) {
    $name      = $ch['name']       ?? 'Unknown';
    $logo      = $ch['logo']       ?? '';
    $groupTitle= $ch['genre_name'] ?? 'General';
    $tvgId     = $ch['id']         ?? '';
    $number    = $ch['number']     ?? 0;
    $cmd       = $ch['cmd']        ?? '';

    if (empty($cmd) || empty($tvgId)) continue;

    // Build the stream URL — goes through playlist.php?id= which handles proxy/redirect
    $streamUrl = $self . '?id=' . urlencode($tvgId);

    // #EXTINF line
    echo '#EXTINF:-1'
       . ' tvg-id="'      . htmlspecialchars($tvgId,     ENT_QUOTES) . '"'
       . ' tvg-name="'    . htmlspecialchars($name,      ENT_QUOTES) . '"'
       . ' tvg-logo="'    . htmlspecialchars($logo,      ENT_QUOTES) . '"'
       . ' group-title="' . htmlspecialchars($groupTitle,ENT_QUOTES) . '"'
       . ' tvg-chno="'    . (int)$number . '"'
       . ',' . $name . "\n";

    echo $streamUrl . "\n";
}
