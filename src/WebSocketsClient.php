<?php

namespace CodeInsights\Debugger\Agent;

use Exception, stdClass;

class WebSocketsClient {

    public function handleIncomingMessage(stdClass $message) : array
    {
        if (isset($message->event) === false)
        {
            d('Invalid message received.');
            return [];
        }

        switch ($message->event)
        {
            case 'pusher:connection_established';
                return $this->handleCommandConnectionEstablished($message);
            case 'pusher_internal:subscription_succeeded';
                // Ignoring
                return [];
            // TODO:
            case 'client-my-manual-event':
                return $this->handleCommandCustom($message);
        }

        d('Unknown command received.');

        return [];
    }

    private function handleCommandConnectionEstablished(stdClass $message) : array
    {
        $socket_id = $message->data->socket_id;

        // TODO: Obtain authentication signature from external service (which validates if access to the channel can be granted?) (or just knows / gives the channel name)
        $authentication_signature = hash_hmac('sha256', $socket_id . ':' . 'private-' . $_ENV['CODEINSIGHTS_MESSAGING_SERVER_CHANNEL'], $_ENV['CODEINSIGHTS_MESSAGING_SERVER_SECRET']);

        return [
            'event' => 'pusher:subscribe',
            'data' => [
                'auth' => $_ENV['CODEINSIGHTS_MESSAGING_SERVER_KEY'] . ':' . $authentication_signature,
                'channel' => 'private-' . $_ENV['CODEINSIGHTS_MESSAGING_SERVER_CHANNEL'],
            ]
        ];
    }

    private function handleCommandCustom(stdClass $message) : array
    {
        return [
            'event' => 'client-my-manual-event-response',
            'channel' => 'private-' . $_ENV['CODEINSIGHTS_MESSAGING_SERVER_CHANNEL'],
            'data' => [
                'Some random gibberish in response: ' . time(),
            ],
        ];
    }

    public function uploadSnapshots() : void
    {
        // TODO
        // d('Checking for new dumps to upload.');
        // TODO: Ping pong
        // TODO: And if haven't heard back - disable debugging
    }

}

function d(mixed $variable = '')
{
    echo $variable . "\n";
}
