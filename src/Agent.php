<?php

namespace CodeInsights\Debugger\Agent;

use stdClass;

class Agent {

    private array $breakpoints = [];
    private array $activeClients = [];

    public function __construct (
        private WebSocketsClient $webSocketsClient,
    ) {
        $this->webSocketsClient->registerCallback('test-set-breakpoint', 'setBreakpoint');
        $this->webSocketsClient->registerCallback('test-keepalive', 'handleClientKeepalive');
    }

    public function setBreakpoint(stdClass $request) : array
    {
        d('Handling command setBreakpoint');

        $filePath = $_ENV['CODEINSIGHTS_PROJECT_WEBROOT'] . $request->filePath;

        if (file_exists($filePath) !== true)
        {
            return $this->webSocketsClient->prepareResponse('set-breakpoint-response', (array) $request + [
                'error' => true,
                'errorMessage' => 'File does not exist.',
            ]);
        }

        // TODO: Validate that breakpoints are not being set outside the webroot

        $fileHash = hash('xxh32', file_get_contents($filePath));

        if ($request->fileHash !== $fileHash)
        {
            return $this->webSocketsClient->prepareResponse('set-breakpoint-response', (array) $request + [
                'error' => true,
                'errorMessage' => 'PHP file contents differ. Please make sure you are using identical version to the one in production environment when setting breakpoints.',

                // TODO: Remove after debugging
                'expectedFileHash' => $fileHash,
            ]);
        }

        if (
            isset($this->breakpoints[$request->filePath][$request->lineNo]) === false
            || in_array($request->clientId, $this->breakpoints[$request->filePath][$request->lineNo]) === false
        ) {
            $this->breakpoints[$request->filePath][$request->lineNo][] = $request->clientId;
        }

        $this->saveBreakpointsInConfigurationFile();

        return $this->webSocketsClient->prepareResponse('set-breakpoint-response', (array) $request + [
            'error' => false,
        ]);
    }

    public function handleClientKeepalive(stdClass $request) : array
    {
        d('Handling client keepAlive');

        $this->markClientAsActive($request->clientId);

        print_r($this->activeClients);

        return $this->webSocketsClient->doNotRespond();
    }

    public function performMaintenance() : void
    {
        d('Performing maintenance');

        $clientTimeoutSeconds = 60;

        // TODO: If haven't heard back from peers in a while, disable debugging (remove expired breakpoints)
        foreach ($this->activeClients as $client => $lastSeen)
        {
            if ($lastSeen < time() - $clientTimeoutSeconds)
            {
                d($client . ' has been last seen more than ' . $clientTimeoutSeconds . ' seconds ago. Removing breakpoints set by this client.');
                $this->removeBreakpointSetByClient($clientId);
            }
        }
    }

    private function markClientAsActive($clientId) : void
    {
        $this->activeClients[$clientId] = time();
    }

    private function removeBreakpointSetByClient($clientId) : void
    {
        // TODO
        $this->saveBreakpointsInConfigurationFile();
    }

    private function saveBreakpointsInConfigurationFile() : void
    {
        // TODO
        print_r($this->breakpoints);
    }
}
