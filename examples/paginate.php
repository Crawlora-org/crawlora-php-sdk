<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Crawlora\Client;

$client = new Client(['apiKey' => getenv('CRAWLORA_API_KEY') ?: '']);

try {
    // Walk every review across pages, stopping on the first empty page.
    foreach ($client->paginateItems('airbnb-room-reviews', ['id' => 'r1'], ['max_pages' => 5]) as $review) {
        echo ($review['comments'] ?? ''), "\n";
    }
} finally {
    $client->close();
}
