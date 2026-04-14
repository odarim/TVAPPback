<?php

/**
 * IPTV Endpoint Checker
 * To run: php iptv-checker.php [input.json] [output.json]
 */

$inputFile = $argv[1] ?? 'all-channels.json';
$outputFile = $argv[2] ?? 'results.json';

if (!file_exists($inputFile)) {
    die("Error: Input file '$inputFile' not found. Please create it or provide a filename: php iptv-checker.php my-channels.json\n");
}

$data = json_decode(file_get_contents($inputFile), true);
if (!$data) {
    die("Error: Invalid JSON in $inputFile\n");
}

$results = [];

echo "Starting IPTV check on " . count($data) . " channels...\n";

foreach ($data as $index => $channel) {
    $urls = $channel['iptv_urls'] ?? [];
    if (!is_array($urls)) {
        if (isset($channel['url'])) {
            $urls = [$channel['url']]; // Fallback if format differs
        } else {
            continue;
        }
    }

    echo "Checking channel [" . ($index + 1) . "/" . count($data) . "]: {$channel['name']}...\n";

    foreach ($urls as $url) {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, // HEAD request is faster, doesn't download the video stream
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        $status = 'unknown';

        if ($error) {
            $status = 'error';
        } elseif ($httpCode >= 200 && $httpCode < 400) {
            $status = 'working';
        } elseif ($httpCode === 403) {
            $status = 'blocked (maybe geo)';
        } elseif ($httpCode === 404) {
            $status = 'dead';
        }

        $resultRow = [
            'name' => $channel['name'],
            'url' => $url,
            'status' => $status,
            'http_code' => $httpCode,
            'error_msg' => $error ?: null
        ];

        $results[] = $resultRow;

        if ($status === 'working') {
             echo "  [OK] $url\n";
        } elseif ($status === 'blocked (maybe geo)') {
             echo "  [GEO] $url (403 Forbidden)\n";
        } else {
             echo "  [ERR] $url (Code: $httpCode)\n";
        }
    }
}

file_put_contents($outputFile, json_encode($results, JSON_PRETTY_PRINT));
echo "\nCheck complete! Results saved to $outputFile.\n";
