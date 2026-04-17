#!/usr/bin/env php
<?php
/**
 * fstv.rest Channel Scraper — CLI tool
 *
 * Usage:
 *   php scraper.php                    → starts from newsid=1
 *   php scraper.php --start=50         → starts from a specific ID
 *   php scraper.php --out=tv.json      → custom output file (default: channels.json)
 *
 * Scans in batches of 1000 IDs.
 * Asks to continue after each batch.
 * Stops automatically after 30 consecutive non-channel IDs.
 * Saves all found channels into a single JSON file.
 */

// ── Config ────────────────────────────────────────────────────────────────────
define('BASE_URL',   'https://fstv.rest/index.php?newsid=');
define('BATCH_SIZE', 1000);
define('SLEEP_MS',   300);  // ms between requests (be polite)
define('MAX_SKIP',   30);   // stop batch early if N consecutive IDs have no channel
define('TIMEOUT',    10);

// ── Parse CLI args ────────────────────────────────────────────────────────────
$opts    = getopt('', ['start:', 'out:']);
$startId = isset($opts['start']) ? (int)$opts['start'] : 1;
$outFile = $opts['out'] ?? 'channels.json';

// ── Load existing results ─────────────────────────────────────────────────────
$channels = [];
if (file_exists($outFile)) {
    $existing = json_decode(file_get_contents($outFile), true);
    if (is_array($existing)) {
        $channels = $existing;
        echo "📂 Loaded " . count($channels) . " existing channel(s) from $outFile\n";
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function fetchPage(string $url): array
{
    static $cookieJar = [];

    $cookieHeader = '';
    if (!empty($cookieJar)) {
        $cookieHeader = 'Cookie: ' . implode('; ', array_map(
            fn($k, $v) => "$k=$v", array_keys($cookieJar), $cookieJar
        ));
    }

    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept-Encoding: identity',
        'Referer: https://fstv.rest/',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: same-origin',
    ];
    if ($cookieHeader) $headers[] = $cookieHeader;

    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => TIMEOUT,
            'ignore_errors'   => true,
            'follow_location' => false,
            'header'          => implode("\r\n", $headers),
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $html    = @file_get_contents($url, false, $ctx);
    $headers = $http_response_header ?? [];

    $status = 200;
    foreach ($headers as $h) {
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $h, $m)) {
            $status = (int)$m[1];
        }
        // Collect Set-Cookie headers
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]+)/i', $h, $m)) {
            $cookieJar[trim($m[1])] = trim($m[2]);
        }
    }

    return ['html' => $html ?: '', 'status' => $status];
}

function parseChannel(string $html, string $pageUrl): ?array
{
    // The player is inside a srcdoc iframe → HTML entities encoded
    // After one decode, quotes appear as \" (backslash + quote), so we normalize them
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $decoded = str_replace('\\"', '"', $decoded);

    // Must contain an m3u8 URL
    if (!preg_match('/var\s+su\s*=\s*"(https?:\/\/[^"]+\.m3u8[^"]*)"/i', $decoded, $m)) {
        return null;
    }
    $m3u8 = $m[1];

    // Channel name from <title>NAME » ...</title>
    $name = 'Unknown';
    if (preg_match('/<title>([^»<]+)/u', $html, $m)) {
        $name = trim($m[1]);
    } elseif (preg_match('/property="og:title"\s+content="([^"»]+)/i', $html, $m)) {
        $name = trim(explode('»', $m[1])[0]);
    }

    // Logo from /chaineimg/
    $logo = null;
    if (preg_match('/<img\s[^>]*src="(\/chaineimg\/[^"]+)"/i', $html, $m)) {
        $base = parse_url($pageUrl, PHP_URL_SCHEME) . '://' . parse_url($pageUrl, PHP_URL_HOST);
        $logo = $base . $m[1];
    }

    return ['name' => $name, 'm3u8' => $m3u8, 'logo' => $logo, 'page' => $pageUrl];
}

function saveJson(array $channels, string $file): void
{
    file_put_contents(
        $file,
        json_encode(array_values($channels), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function ask(string $question): bool
{
    echo "\n$question [y/n] > ";
    $line = strtolower(trim(fgets(STDIN)));
    return $line === 'y' || $line === 'yes';
}

function progressLine(int $id, int $total, int $skip, string $label): void
{
    echo sprintf(
        "\r  ID %-6d  total found: %-4d  consec. skips: %-3d  %s          ",
        $id, $total, $skip, $label
    );
}

// ── Main loop ─────────────────────────────────────────────────────────────────
echo "\n\033[1m fstv.rest Channel Scraper\033[0m\n";
echo " Output      : $outFile\n";
echo " Start ID    : $startId\n";
echo " Batch size  : " . BATCH_SIZE . "\n\n";

$currentId  = $startId;
$totalFound = count($channels);
$keepGoing  = true;

while ($keepGoing) {

    $batchEnd        = $currentId + BATCH_SIZE - 1;
    $batchFound      = 0;
    $consecutiveSkip = 0;

    echo "━━━ Scanning IDs $currentId → $batchEnd ━━━\n";

    for ($id = $currentId; $id <= $batchEnd; $id++) {

        $url  = BASE_URL . $id;
        $page = fetchPage($url);

        // Hard stop: 403/404/redirect
        if (in_array($page['status'], [301, 302, 403, 404])) {
            $consecutiveSkip++;
            progressLine($id, $totalFound, $consecutiveSkip, "\033[90mHTTP {$page['status']}\033[0m");

            if ($consecutiveSkip >= MAX_SKIP) {
                echo "\n\n  ⚠️  $consecutiveSkip consecutive non-200 responses — likely reached the end.\n";
                $keepGoing = false;
                break;
            }
            usleep(SLEEP_MS * 1000);
            continue;
        }

        // Try to parse as channel
        $channel = parseChannel($page['html'], $url);

        if ($channel) {
            $consecutiveSkip = 0;
            $batchFound++;
            $totalFound++;
            $channels[$id] = array_merge(['id' => $id], $channel);
            progressLine($id, $totalFound, $consecutiveSkip, "\033[32m✓ {$channel['name']}\033[0m");
            saveJson($channels, $outFile); // save after every hit
        } else {
            $consecutiveSkip++;
            progressLine($id, $totalFound, $consecutiveSkip, "\033[90m— not a channel\033[0m");

            if ($consecutiveSkip >= MAX_SKIP) {
                echo "\n\n  ⚠️  $consecutiveSkip consecutive pages with no stream — likely reached the end.\n";
                $keepGoing = false;
                break;
            }
        }

        usleep(SLEEP_MS * 1000);
    }

    echo "\n\n  ✅ Batch done. Found \033[1m$batchFound\033[0m channel(s) this batch. Total: \033[1m$totalFound\033[0m\n";

    if (!$keepGoing) break;

    $currentId = $batchEnd + 1;

    if (!ask("  Continue scanning IDs $currentId → " . ($currentId + BATCH_SIZE - 1) . "?")) {
        $keepGoing = false;
    }

    echo "\n";
}

// ── Final save & summary ──────────────────────────────────────────────────────
saveJson($channels, $outFile);

echo "\n\033[1m Done!\033[0m Saved \033[1m$totalFound\033[0m channel(s) → \033[33m$outFile\033[0m\n\n";

if ($totalFound > 0) {
    echo " ID    Name                           m3u8\n";
    echo str_repeat('─', 90) . "\n";
    foreach ($channels as $ch) {
        printf(" %-5d %-30s %s\n", $ch['id'], mb_substr($ch['name'], 0, 30), $ch['m3u8']);
    }
    echo "\n";
}