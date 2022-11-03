<?php

namespace CodeInsights\Debugger\Agent;

use stdClass;

class Agent {

    public function __construct (
        private WebSocketsClient $webSocketsClient,
    ) {
        $this->webSocketsClient->registerCallback('random-event-test', 'setBreakpoint');
    }

    public function setBreakpoint(stdClass $message) : array
    {
        d('Handling command setBreakpoint');
        return $this->webSocketsClient->doNotRespond();
        return $this->webSocketsClient->prepareResponse('random-response-event', ['a' => 123, 'boolean' => true]);
        // return [];
    }

    public function performMaintenance() : void
    {
        // TODO: Ping pong
        // TODO: If haven't heard back from peers in a while, disable debugging (remove expired breakpoints)
        // TODO: Store active breakpoints in configuration file
    }
}
