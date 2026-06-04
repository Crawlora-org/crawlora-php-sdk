<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Crawlora\Client;

$client = new Client(['apiKey' => getenv('CRAWLORA_API_KEY') ?: '']);

try {
    $result = $client->bing->search(['q' => 'web scraping']);
    foreach ($result['data'] ?? [] as $item) {
        echo ($item['title'] ?? ''), "\n";
    }
} finally {
    $client->close();
}
