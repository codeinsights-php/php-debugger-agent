<?php

namespace CodeInsights\Debugger\Agent;

use stdClass;

class Agent {

    private array $breakpoints = [];
    private array $activeClients = [];
    private array $processTimestamps = [];

    public function __construct (
        private WebSocketsClient $webSocketsClient,
    ) {
        $this->webSocketsClient->registerCallback('client-set-breakpoint', 'handleSetBreakpoint');
        $this->webSocketsClient->registerCallback('client-remove-breakpoint', 'handleRemoveBreakpoint');
        $this->webSocketsClient->registerCallback('client-keepalive', 'handleClientKeepalive');

        // Clear existing breakpoints from the configuration file upon startup
        $this->saveBreakpointsInConfigurationFile();
    }

    public function handleSetBreakpoint(stdClass $request) : array
    {
        d('Handling command setBreakpoint');

        $this->markClientAsActive($request->clientId);

        $filePath = $_ENV['CODEINSIGHTS_PROJECT_WEBROOT'] . $request->filePath;

        // Validate that breakpoints are not being set outside the webroot
        if (str_starts_with(realpath($filePath), $_ENV['CODEINSIGHTS_PROJECT_WEBROOT']) === false)
        {
            return $this->webSocketsClient->prepareResponse('client-set-breakpoint-response', (array) $request + [
                'error' => true,
                'errorMessage' => 'Invalid file path provided.',
            ]);
        }

        if (file_exists($filePath) !== true)
        {
            return $this->webSocketsClient->prepareResponse('client-set-breakpoint-response', (array) $request + [
                'error' => true,
                'errorMessage' => 'File does not exist.',
            ]);
        }

        $fileHash = hash('xxh32', file_get_contents($filePath));

        if ($request->fileHash !== $fileHash)
        {
            return $this->webSocketsClient->prepareResponse('client-set-breakpoint-response', (array) $request + [
                'error' => true,
                'errorMessage' => 'PHP file contents in production environment differ. Please make sure you are using identical version ' .
                    'to the one in production environment when setting breakpoints.',

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

        return $this->webSocketsClient->prepareResponse('client-set-breakpoint-response', (array) $request + [
            'error' => false,
        ]);
    }

    public function handleRemoveBreakpoint(stdClass $request) : array
    {
        $this->markClientAsActive($request->clientId);

        $this->removeBreakpoint($request->filePath, $request->lineNo, $request->clientId);

        $this->saveBreakpointsInConfigurationFile();

        return $this->webSocketsClient->prepareResponse('client-remove-breakpoint-response', (array) $request + [
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
        // d('Performing maintenance');

        $this->removeExpiredBreakpoints();
        $this->sendLogsToServer();
    }

    private function removeExpiredBreakpoints() : void
    {
        // TODO: Make time interval between process runs configurable
        if ($this->alreadyRun('removing-expired-breakpoints', 10))
        {
            return;
        }

        // d('Removing expired breakpoints');

        // TODO: Make configurable?
        $clientTimeoutSeconds = 60;

        // If haven't heard back from peers in a while, disable debugging (remove expired breakpoints)
        foreach ($this->activeClients as $clientId => $lastSeen)
        {
            if ($lastSeen < time() - $clientTimeoutSeconds)
            {
                d($clientId . ' has been last seen more than ' . $clientTimeoutSeconds . ' seconds ago. Removing breakpoints set by this client.');
                $this->removeBreakpointSetByClient($clientId);
                unset($this->activeClients[$clientId]);
            }
        }
    }

    private function sendLogsToServer() : void
    {
        // TODO: Make time interval between process runs configurable
        if ($this->alreadyRun('sending-logs', 1))
        {
            return;
        }

        // d('Sending logs');

        $logPath = dirname(ini_get('codeinsights.breakpoint_file')) . '/logs/';
        $logs = scandir($logPath, SCANDIR_SORT_ASCENDING);

        foreach ($logs as $logFilename)
        {
            if ($logFilename == '.' || $logFilename == '..')
            {
                continue;
            }

            $logFile = $logPath . $logFilename;

            $this->webSocketsClient->sendMessage('client-debugging-event', file_get_contents($logFile), compress: true);

            unlink($logFile);
        }
    }

    private function alreadyRun($process, $timeIntervalInSeconds) : bool
    {
        if (isset($this->processTimestamps[$process]) === false || time() > $this->processTimestamps[$process] + $timeIntervalInSeconds)
        {
            $this->processTimestamps[$process] = time();
            return false;
        }

        return true;
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
                $this->removeBreakpoint($filePath, $lineNo, $clientId);
            }
        }

        $this->saveBreakpointsInConfigurationFile();
    }

    private function removeBreakpoint($filePath, $lineNo, $clientId) : void
    {
        if (isset($this->breakpoints[$filePath][$lineNo]) === false)
        {
            return;
        }

        foreach ($this->breakpoints[$filePath][$lineNo] as $array_key => $client)
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

    private function saveBreakpointsInConfigurationFile() : void
    {

        $debuggerCallback = '\\CodeInsights\\Debugger\\Helper::debug(\'\', \'\', get_defined_vars(), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), __FILE__, __LINE__);';
        $breakpointsConfiguration = '';

        foreach ($this->breakpoints as $filePath => $breakpoint)
        {
            $breakpointsConfiguration .= $_ENV['CODEINSIGHTS_PROJECT_WEBROOT'] . $filePath . "\n" . array_key_first($breakpoint) . "\n" . $debuggerCallback . "\n";
        }

        $breakpointsConfiguration = rtrim($breakpointsConfiguration, "\n");

        file_put_contents(ini_get('codeinsights.breakpoint_file'), $breakpointsConfiguration, LOCK_EX);
    }
}
