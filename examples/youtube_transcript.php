<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Crawlora\Client;

$client = new Client(['apiKey' => getenv('CRAWLORA_API_KEY') ?: '']);

try {
    // youtube-transcript supports plain-text responses.
    $transcript = $client->request('youtube-transcript', ['id' => 'dQw4w9WgXcQ'], [
        'response_type' => 'text',
    ]);
    echo $transcript, "\n";
} finally {
    $client->close();
}
