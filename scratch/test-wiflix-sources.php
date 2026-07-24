<?php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;
use Psr\Log\NullLogger;

$httpClient = HttpClient::create();
$logger = new NullLogger();

$wiflixService = new \App\Service\WiflixMediaService($httpClient, $logger);

echo "Testing Movie search: 'Is God Is'...\n";
$sources = $wiflixService->getSources('movie', 'Is God Is');
print_r($sources);

echo "\nTesting Series search: 'Fire Country', Season 4, Episode 18...\n";
$seriesSources = $wiflixService->getSources('series', 'Fire Country', 'VF', 4, 18);
print_r($seriesSources);
