<?php

namespace CodeInsights\Debugger\Agent;

use Exception, stdClass;

class WebSocketsClient {

    private Agent $agent;
    private array $commandHandlers;

    public function __construct()
    {
        $this->agent = new Agent($this);
    }

    public function handleIncomingMessage(stdClass $message) : array
    {
        if (isset($message->event) === false)
        {
            d('Invalid message received.');
            return $this->doNotRespond();
        }

        switch ($message->event)
        {
            case 'pusher:connection_established';
                return $this->handleCommandConnectionEstablished($message);
            case 'pusher_internal:subscription_succeeded';
                // Ignoring
                return $this->doNotRespond();
            default:
                if (isset($this->commandHandlers[$message->event])) {
                    return $this->agent->{ $this->commandHandlers[$message->event] }($message);
                } else {
                    d('Unknown command received.');
                }
        }

        return $this->doNotRespond();
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

    public function prepareResponse($event, array $response) : array
    {
        return [
            'event' => $event,
            'channel' => 'private-' . $_ENV['CODEINSIGHTS_MESSAGING_SERVER_CHANNEL'],
            'data' => [
                $response,
            ],
        ];
    }

    public function doNotRespond() : array
    {
        return [];
    }

    public function performMaintenance() : void
    {
        $this->agent->performMaintenance();
    }

    public function registerCallback($command, $handler) : void
    {
        $this->commandHandlers[$command] = $handler;
    }
}

function d(mixed $variable = '')
{
    echo $variable . "\n";
}
