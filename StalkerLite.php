<?php
/**
 * StalkerLite — Minimal Stalker Portal Engine
 * Handshake · Profile · Channels · Stream Resolution
 * No database · No proxy · Pure PHP + cURL
 */

class StalkerLite {

    private string $mac;
    private string $model;
    private string $serverUrl;
    private string $portalBase;
    private array  $deviceInfo;
    private array  $headers;
    private string $token;
    private bool   $tokenVerified = false;

    // ─── Constructor ───────────────────────────────────────────────────────────
    // $existingToken: pass a cached token to skip handshake entirely
    public function __construct(
        string $url,
        string $mac,
        string $model          = 'MAG250',
        array  $extras         = [],
        string $existingToken  = ''
    ) {
        $this->mac   = strtoupper(trim($mac));
        $this->model = $model ?: 'MAG250';
        $this->token = $existingToken;

        $clean            = $this->sanitizeUrl($url);
        $this->serverUrl  = $this->buildServerUrl($clean);
        $this->portalBase = $this->buildPortalBase($clean);
        $this->deviceInfo = $this->makeDeviceInfo($extras);
        $this->headers    = $this->makeHeaders();
    }

    // ─── URL helpers ───────────────────────────────────────────────────────────
    private function sanitizeUrl(string $url): string {
        $url = rtrim(trim($url), '/');
        $url = preg_replace('#/c/?$#', '', $url);
        $url = preg_replace('#/stalker_portal/?$#', '', $url);
        return $url;
    }

    private function buildServerUrl(string $clean): string {
        return (strpos($clean, '/stalker_portal') !== false)
            ? $clean . '/server/load.php'
            : $clean . '/stalker_portal/server/load.php';
    }

    private function buildPortalBase(string $clean): string {
        return (strpos($clean, '/stalker_portal') !== false)
            ? $clean . '/c/'
            : $clean . '/stalker_portal/c/';
    }

    // ─── Device info ───────────────────────────────────────────────────────────
    private function makeDeviceInfo(array $x): array {
        $mac       = $this->mac;
        $sn        = strtoupper(md5($mac));
        $snCut     = !empty($x['sn_cut'])     ? $x['sn_cut']     : (!empty($x['sn']) ? substr($x['sn'], 0, 13) : substr($sn, 0, 13));
        $deviceId  = !empty($x['device_id'])  ? $x['device_id']  : strtoupper(hash('sha256', $mac));
        $deviceId2 = !empty($x['device_id2']) ? $x['device_id2'] : $deviceId;
        $signature = !empty($x['signature'])  ? $x['signature']  : strtoupper(hash('sha256', $snCut . $mac));

        return [
            'mac'       => $mac,
            'sn'        => $sn,
            'snCut'     => $snCut,
            'deviceId'  => $deviceId,
            'deviceId2' => $deviceId2,
            'signature' => $signature,
            'model'     => $this->model
        ];
    }

    // ─── Headers ───────────────────────────────────────────────────────────────
    private function makeHeaders(): array {
        return [
            'User-Agent: Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 (KHTML, like Gecko) MAG200 stbapp ver: 2 rev: 250 Safari/533.3',
            'X-User-Agent: Model: ' . $this->model . '; Link: WiFi',
            'Accept: */*',
            'Accept-Encoding: gzip, deflate',
            'Connection: Keep-Alive',
            'Cookie: mac=' . urlencode($this->mac) . '; stb_lang=en; timezone=GMT',
            'Referer: ' . $this->portalBase,
        ];
    }

    private function authHeaders(): array {
        $h = $this->headers;
        if ($this->token) $h[] = 'Authorization: Bearer ' . $this->token;
        return $h;
    }

    // ─── cURL ──────────────────────────────────────────────────────────────────
    public function curlGet(string $url, array $headers = [], int $timeout = 20): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers ?: $this->authHeaders(),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (QtEmbedded; U; Linux; C)',
        ]);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return ['data' => $body, 'code' => $code, 'error' => $error];
    }

    // ─── Handshake ─────────────────────────────────────────────────────────────
    public function handshake(): array {
        $url = $this->serverUrl
             . '?type=stb&action=handshake&prehash='
             . urlencode($this->mac)
             . '&token=&JsHttpRequest=1-xml';

        $res  = $this->curlGet($url);

        // Fallback for portals that don't match the initial stalker_portal path guess
        if ($res['code'] == 404 || (empty($res['data']) && $res['code'] != 200)) {
            if (strpos($this->serverUrl, '/stalker_portal/') !== false) {
                $this->serverUrl  = str_replace('/stalker_portal/', '/', $this->serverUrl);
                $this->portalBase = str_replace('/stalker_portal/', '/', $this->portalBase);
            } else {
                $this->serverUrl  = str_replace('/server/load.php', '/stalker_portal/server/load.php', $this->serverUrl);
                $this->portalBase = str_replace('/c/', '/stalker_portal/c/', $this->portalBase);
            }
            $this->headers = $this->makeHeaders();

            // Rebuild URL and retry
            $url = $this->serverUrl
                 . '?type=stb&action=handshake&prehash='
                 . urlencode($this->mac)
                 . '&token=&JsHttpRequest=1-xml';
            $res = $this->curlGet($url);
        }

        if ($res['code'] == 429) {
            return ['success' => false, 'token' => '', 'error' => 'Rate limited (HTTP 429). Please wait a few minutes and try again.'];
        }

        if (empty($res['data'])) {
            return ['success' => false, 'token' => '', 'error' => 'Empty response from server (HTTP ' . $res['code'] . ')'];
        }

        $data  = @json_decode($res['data'], true);
        $token = $data['js']['token'] ?? $data['token'] ?? '';

        if (empty($token)) {
            return [
                'success' => false,
                'token'   => '',
                'error'   => 'No token received (HTTP ' . $res['code'] . ')',
                'raw'     => substr((string)$res['data'], 0, 300),
            ];
        }

        $this->token = $token;
        return ['success' => true, 'token' => $token, 'random' => $data['js']['random'] ?? ''];
    }

    // ─── Get Profile ───────────────────────────────────────────────────────────
    public function getProfile(): array {
        if (empty($this->token)) return [];

        $di  = $this->deviceInfo;
        $metrics = json_encode([
            'mac'    => $di['mac'],
            'sn'     => $di['sn'],
            'model'  => $di['model'],
            'type'   => 'STB',
            'random' => bin2hex(random_bytes(8))
        ]);

        $url = $this->serverUrl . '?' . http_build_query([
            'type'          => 'stb',
            'action'        => 'get_profile',
            'hd'            => '1',
            'sn'            => $di['snCut'],
            'stb_type'      => $di['model'],
            'device_id'     => $di['deviceId'],
            'device_id2'    => $di['deviceId2'],
            'signature'     => $di['signature'],
            'timestamp'     => time(),
            'metrics'       => $metrics,
            'JsHttpRequest' => '1-xml',
        ]);

        $res  = $this->curlGet($url);
        $data = @json_decode($res['data'], true);
        $js   = $data['js'] ?? $data ?? [];

        if (empty($js)) return [];

        return [
            'login'    => $js['login']               ?? '',
            'id'       => (string)($js['id']          ?? ''),
            'name'     => $js['name'] ?? $js['fname'] ?? '',
            'expiry'   => $js['expire_billing_date']  ?? ($js['phone'] ?? null),
            'password' => $js['parent_password'] ?? $js['password'] ?? '',
            'tariff'   => is_array($js['tariff_plan'] ?? null) ? ($js['tariff_plan']['name'] ?? '') : '',
        ];
    }

    // ─── Ensure Valid Token (handshake + profile if needed) ─────────────────────
    public function ensureToken(): bool {
        if ($this->tokenVerified) return true;

        if (!empty($this->token)) {
            // Try a profile call to verify token is still valid
            $profile = $this->getProfile();
            if (!empty($profile['id'])) {
                $this->tokenVerified = true;
                return true;
            }
            $this->token = '';
        }

        $hs = $this->handshake();
        if (!$hs['success']) return false;

        // CRITICAL: Call getProfile to initialize Stalker session
        $profile = $this->getProfile();
        if (!empty($profile['id'])) {
            $this->tokenVerified = true;
            return true;
        }

        return false;
    }

    // ─── Full Connect (handshake + profile) ────────────────────────────────────
    public function connect(): array {
        $this->tokenVerified = false;
        $hs = $this->handshake();
        if (!$hs['success']) {
            return ['success' => false, 'error' => $hs['error'] ?? 'Handshake failed', 'raw' => $hs['raw'] ?? ''];
        }

        $profile = $this->getProfile();
        if (empty($profile)) {
            return ['success' => false, 'error' => 'Handshake OK but Profile fetch failed', 'token' => $hs['token']];
        }

        $this->tokenVerified = true;

        return [
            'success'     => true,
            'token'       => $hs['token'],
            'random'      => $hs['random'],
            'device'      => $this->deviceInfo,
            'server_url'  => $this->serverUrl,
            'portal_base' => $this->portalBase,
            'profile'     => $profile,
            'saved_at'    => date('Y-m-d H:i:s'),
        ];
    }


    // ─── Get Genres ────────────────────────────────────────────────────────────
    public function getGenres(bool $verify = true): array {
        if ($verify && !$this->ensureToken()) return [];

        $endpoints = [
            '?type=itv&action=get_genres&JsHttpRequest=1-xml',
            '?type=itv&action=get_all_genres&JsHttpRequest=1-xml',
        ];

        foreach ($endpoints as $ep) {
            $res  = $this->curlGet($this->serverUrl . $ep, [], 30);
            $data = @json_decode($res['data'], true);
            $list = $data['js']['data'] ?? $data['js'] ?? $data['data'] ?? [];
            if (is_array($list) && !empty($list)) {
                $out = [];
                foreach ($list as $g) {
                    if (!is_array($g)) continue;
                    $out[(string)($g['id'] ?? $g['genre_id'] ?? '0')] = $g['title'] ?? $g['name'] ?? 'General';
                }
                return $out;
            }
        }
        return [];
    }

    // ─── Get All Channels ──────────────────────────────────────────────────────
    // Returns array of channel rows (cmd is raw, NOT resolved)
    public function getChannels(): array {
        if (!$this->ensureToken()) return [];

        $genreMap = $this->getGenres(false);

        // Try get_all_channels first (most portals support this)
        $res  = $this->curlGet($this->serverUrl . '?type=itv&action=get_all_channels&JsHttpRequest=1-xml', [], 120);
        $data = @json_decode($res['data'], true);
        
        if (empty($data)) {
            error_log("StalkerLite: get_all_channels returned invalid JSON or empty body.");
        }

        $raw = [];
        if (isset($data['js']['data']) && is_array($data['js']['data'])) {
            $raw = $data['js']['data'];
        } elseif (isset($data['data']) && is_array($data['data'])) {
            $raw = $data['data'];
        } elseif (isset($data['js']) && is_array($data['js'])) {
            // Check if 'js' itself is the channel list or if it's an object with channel keys
            $first = reset($data['js']);
            if (is_array($first)) {
                if (isset($first['name']) || isset($first['cmd'])) {
                    $raw = $data['js'];
                } else {
                    // Try to find any child that is a list of channels
                    foreach ($data['js'] as $val) {
                        if (is_array($val) && (isset($val[0]['name']) || isset($val[0]['cmd']))) {
                            $raw = $val;
                            break;
                        }
                    }
                }
            }
        }

        if (empty($raw)) {
            error_log("StalkerLite: No channels in get_all_channels, trying fallback...");
            $raw = $this->fetchChannelsPaginated();
        }

        $channels = [];
        $i = 0;
        foreach ($raw as $ch) {
            if (!is_array($ch)) continue;
            $gid = (string)($ch['tv_genre_id'] ?? $ch['genre_id'] ?? '0');
            $channels[] = [
                'id'         => (string)($ch['id'] ?? $ch['channel_id'] ?? $i),
                'name'       => trim($ch['name'] ?? $ch['title'] ?? 'Channel ' . ($i + 1)),
                'cmd'        => $ch['cmd'] ?? '',
                'logo'       => $this->buildLogoUrl($ch['logo'] ?? ''),
                'genre_id'   => $gid,
                'genre_name' => $genreMap[$gid] ?? 'General',
                'number'     => (int)($ch['number'] ?? $i),
            ];
            $i++;
        }

        return $channels;
    }

    // ─── Paginated Channel Fetch (fallback) ───────────────────────────────────
    private function fetchChannelsPaginated(): array {
        $all  = [];
        $page = 0;
        $size = 500;

        while (true) {
            $params = http_build_query([
                'type'          => 'itv',
                'action'        => 'get_ordered_list',
                'genre'         => '*',
                'force_ch_link_check' => '',
                'fav'           => '0',
                'sortby'        => 'number',
                'p'             => $page,
                'JsHttpRequest' => '1-xml',
            ]);

            $res  = $this->curlGet($this->serverUrl . '?' . $params, [], 60);
            $data = @json_decode($res['data'], true);
            $list = $data['js']['data'] ?? [];

            if (!is_array($list) || empty($list)) break;

            $all = array_merge($all, $list);

            // Check if we've fetched all
            $total = (int)($data['js']['total_items'] ?? $data['js']['max_page_items'] ?? 0);
            if ($total > 0 && count($all) >= $total) break;

            // Safety: max 100 pages (50,000 channels)
            $page++;
            if ($page > 100) break;
        }

        return $all;
    }

    // ─── Create Link (resolve stream URL) ──────────────────────────────────────
    // Returns direct CDN URL or empty string. Never proxies data.
    public function createLink(string $cmd): string {
        $cmd = trim($cmd);

        // Strip ffmpeg prefix
        if (stripos($cmd, 'ffmpeg ') === 0) $cmd = trim(substr($cmd, 7));

        // If already direct HTTP (not ffrt), return as-is
        if (preg_match('#^https?://#i', $cmd) && stripos($cmd, 'ffrt') !== 0) {
            // Extract clean URL (strip trailing junk)
            if (preg_match('#(https?://[^\s"\']+)#i', $cmd, $m)) return $m[1];
            return $cmd;
        }

        // Call create_link API
        $url = $this->serverUrl . '?' . http_build_query([
            'type'           => 'itv',
            'action'         => 'create_link',
            'cmd'            => $cmd,
            'forced_storage' => 'undefined',
            'disable_ad'     => '1',
            'JsHttpRequest'  => '1-xml',
        ]);

        $res  = $this->curlGet($url, [], 15);
        $data = @json_decode($res['data'], true);
        $js   = $data['js'] ?? $data ?? [];

        $stream = $js['cmd'] ?? $js['url'] ?? '';
        if (empty($stream)) return '';

        if (stripos($stream, 'ffmpeg ') === 0) $stream = trim(substr($stream, 7));
        if (preg_match('#(https?://[^\s"\']+)#i', $stream, $m)) return $m[1];
        return trim($stream);
    }

    private function buildLogoUrl(string $logo): string {
        if (empty($logo)) return '';
        if (preg_match('#^https?://#i', $logo)) return $logo;
        // Convert 'filename.jpg' to full URL '(portal)/misc/logos/320/filename.jpg'
        $base = rtrim(str_replace('/server/load.php', '', $this->serverUrl), '/');
        return $base . '/misc/logos/320/' . ltrim($logo, '/');
    }

    // ─── Getters ───────────────────────────────────────────────────────────────
    public function getToken(): string      { return $this->token; }
    public function getServerUrl(): string  { return $this->serverUrl; }
    public function getPortalBase(): string { return $this->portalBase; }
    public function getDeviceInfo(): array  { return $this->deviceInfo; }
}
