<?php

require __DIR__ . '/vendor/autoload.php';

// Helper functions / "loggers"
function d(mixed $variable = '')
{
    echo $variable . "\n";
}

function dd(mixed $variable = '')
{
    d($variable);
    sleep(30);
    die();
}

// Load configuration and secrets from .env file
$dotenv = Dotenv\Dotenv::createMutable(__DIR__);
$dotenv->load();

$webSocketsClient = new \CodeInsights\Debugger\Agent\WebSocketsClient();

if (empty($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENDPOINT'])) {
    dd('Incomplete configuration, .env does not contain websockets messaging server endpoint.');
}

$loop = \React\EventLoop\Loop::get();

// Check every 100 ms if periodic tasks have to be performed
// (like checking if peers are alive and performing cleanup of inactive breakpoints or sending accumulated logs)
// TODO: Make intensity configurable?
$loop->addPeriodicTimer(0.1, function () use ($webSocketsClient) {
    $webSocketsClient->performMaintenance();
});

$connectionOptions = [
    'timeout' => 3,
];

if (isset($_ENV['CODEINSIGHTS_MESSAGING_SERVER_SSL_VERIFY_PEER']) && $_ENV['CODEINSIGHTS_MESSAGING_SERVER_SSL_VERIFY_PEER'] === 'false')
{
    $connectionOptions['tls'] = [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ];
}

if (empty($_ENV['CODEINSIGHTS_MESSAGING_SERVER_USE_SPECIFIC_DNS_SERVER']) === false)
{
    $connectionOptions['dns'] = $_ENV['CODEINSIGHTS_MESSAGING_SERVER_USE_SPECIFIC_DNS_SERVER'];
}

$reactConnector = new \React\Socket\Connector($loop, $connectionOptions);

$connector = new \Ratchet\Client\Connector($loop, $reactConnector);

// https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/
$connector($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENDPOINT'])->then(function ($connection) use ($webSocketsClient) {
    $webSocketsClient->connection = $connection;

    $connection->on('message', function ($msg) use ($connection, $webSocketsClient) {
        echo "Received: {$msg}\n";

        $message = json_decode($msg, true);

        if (is_string($message['data'])) {
            // event "pusher:connection_established" has data as string which contains json
            $message['data'] = json_decode($message['data'], true);
        }

        print_r($message);

        $response = $webSocketsClient->handleIncomingMessage($message);

        if (empty($response) === false) {
            $connection->send(json_encode($response));
        }
    });

    // Custom addition to Ratchet code has to be added
    // as $this->emit('response-sent', [$msg, $this]);
    // in src/WebSocket.php
    $connection->on('response-sent', function ($msg) {
        if (empty(trim($msg)) === false) {
            echo "Sent: {$msg}\n";
        }
    });

// TODO: On "close" event handler?
}, function (\Exception $e) use ($loop) {
    echo "Could not connect: {$e->getMessage()}\n";
    $loop->stop();
});

$loop->run();
