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

        // Clear existing breakpoints from the configuration file upon startup
        $this->saveBreakpointsInConfigurationFile();
    }

    public function setBreakpoint(stdClass $request) : array
    {
        d('Handling command setBreakpoint');

        $filePath = $_ENV['CODEINSIGHTS_PROJECT_WEBROOT'] . $request->filePath;

        // Validate that breakpoints are not being set outside the webroot
        if (str_starts_with(realpath($filePath), $_ENV['CODEINSIGHTS_PROJECT_WEBROOT']) === false)
        {
            return $this->webSocketsClient->prepareResponse('set-breakpoint-response', (array) $request + [
                'error' => true,
                'errorMessage' => 'Invalid file path provided.',
            ]);
        }

        if (file_exists($filePath) !== true)
        {
            return $this->webSocketsClient->prepareResponse('set-breakpoint-response', (array) $request + [
                'error' => true,
                'errorMessage' => 'File does not exist.',
            ]);
        }

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
            $this->markClientAsActive($request->clientId);
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

        return $this->webSocketsClient->doNotRespond();
    }

    public function performMaintenance() : void
    {
        d('Performing maintenance');

        // TODO: Make configurable?
        $clientTimeoutSeconds = 60;

        // If haven't heard back from peers in a while, disable debugging (remove expired breakpoints)
        foreach ($this->activeClients as $clientId => $lastSeen)
        {
            if ($lastSeen < time() - $clientTimeoutSeconds)
            {
                d($clientId . ' has been last seen more than ' . $clientTimeoutSeconds . ' seconds ago. Removing breakpoints set by this client.');
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
        foreach ($this->breakpoints as $filePath => $lines)
        {
            foreach ($lines as $lineNo => $clients)
            {
                foreach ($clients as $array_key => $client)
                {
                    if ($client === $clientId)
                    {
                        unset($this->breakpoints[$filePath][$lineNo][$array_key]);

                        if (empty($this->breakpoints[$filePath][$lineNo]))
                        {
                            unset($this->breakpoints[$filePath][$lineNo]);
                        }

                        if (empty($this->breakpoints[$filePath]))
                        {
                            unset($this->breakpoints[$filePath]);
                        }
                    }
                }
            }
        }

        $this->saveBreakpointsInConfigurationFile();
    }

    private function saveBreakpointsInConfigurationFile() : void
    {
        $debuggerCallback = '\\CodeInsights\\Debugger\\Helper::debug(\'\', \'\', get_defined_vars(), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), __FILE__, __LINE__);';
        $breakpointsConfiguration = '';

        foreach ($this->breakpoints as $filePath => $breakpoint)
        {
            $breakpointsConfiguration .= $filePath . "\n" . array_key_first($breakpoint) . "\n" . $debuggerCallback . "\n";
        }

        $breakpointsConfiguration = rtrim($breakpointsConfiguration, "\n");

        file_put_contents(ini_get('codeinsights.breakpoint_file'), $breakpointsConfiguration, LOCK_EX);
    }
}
