<?php

require __DIR__ . '/vendor/autoload.php';

// Load configuration and secrets from .env file
$dotenv = Dotenv\Dotenv::createMutable(__DIR__);
$dotenv->load();

$webSocketsClient = new \CodeInsights\Debugger\Agent\WebSocketsClient();

$loop = \React\EventLoop\Loop::get();

// Check every 10 seconds if peers are alive and perform cleanup of inactive breakpoints
$loop->addPeriodicTimer(10, function () use ($webSocketsClient) {
    $webSocketsClient->performMaintenance();
});

$connectionOptions = [
    'timeout' => 3,
];

if (empty($_ENV['CODEINSIGHTS_MESSAGING_SERVER_USE_SPECIFIC_DNS_SERVER']) === false)
{
    $connectionOptions['dns'] = $_ENV['CODEINSIGHTS_MESSAGING_SERVER_USE_SPECIFIC_DNS_SERVER'];
}

$reactConnector = new \React\Socket\Connector($loop, $connectionOptions);

$connector = new \Ratchet\Client\Connector($loop, $reactConnector);

$messagingServerEndpoint = 'wss://' . $_ENV['CODEINSIGHTS_MESSAGING_SERVER_HOST'] .'/app/' . $_ENV['CODEINSIGHTS_MESSAGING_SERVER_KEY'] . '?protocol=7&flash=false';

// https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/
$connector($messagingServerEndpoint)->then(function ($connection) use ($webSocketsClient) {
    $connection->on('message', function ($msg) use ($connection, $webSocketsClient) {
        echo "Received: {$msg}\n";

        $message = json_decode($msg, false);

        if (is_string($message->data)) {
            // event "pusher:connection_established" has data as string which contains json
            $message->data = json_decode($message->data, false);
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
