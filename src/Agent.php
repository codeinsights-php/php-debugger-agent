<?php

namespace CodeInsights\Debugger\Agent;

use stdClass;

class Agent
{
    private array $breakpoints = [];
    private array $activeClients = [];
    private array $processTimestamps = [];

    private string $extensionConfigDir;
    public string $projectWebroot;

    public function __construct(
        private WebSocketsClient $webSocketsClient,
    ) {
        $this->_determineExtensionConfigDir();
        $this->_verifyExtensionConfigDir();

        $this->webSocketsClient->registerCallback('client-set-breakpoint', 'handleSetBreakpoint');
        $this->webSocketsClient->registerCallback('client-remove-breakpoint', 'handleRemoveBreakpoint');
        $this->webSocketsClient->registerCallback('client-keepalive', 'handleClientKeepalive');

        // Clear existing breakpoints from the configuration file upon startup
        $this->saveBreakpointsInConfigurationFile();
    }

    public function handleSetBreakpoint(stdClass $request): array
    {
        d('Handling command setBreakpoint');

        $this->markClientAsActive($request->clientId);

        $filePath = $this->projectWebroot . $request->filePath;

        // Validate that breakpoints are not being set outside the webroot
        if (str_starts_with(realpath($filePath), $this->projectWebroot) === false) {
            return $this->webSocketsClient->prepareResponse('client-set-breakpoint-response', (array) $request + [
                'error' => true,
                'errorMessage' => 'Invalid file path provided.',
            ]);
        }

        if (file_exists($filePath) !== true) {
            return $this->webSocketsClient->prepareResponse('client-set-breakpoint-response', (array) $request + [
                'error' => true,
                'errorMessage' => 'File does not exist.',
            ]);
        }

        $fileHash = hash('xxh32', file_get_contents($filePath));

        if ($request->fileHash !== $fileHash) {
            return $this->webSocketsClient->prepareResponse('client-set-breakpoint-response', (array) $request + [
                'error' => true,
                'errorMessage' => 'PHP file contents in production environment differ. Please make sure you are using identical version ' .
                    'to the one in production environment when setting breakpoints.',

                // TODO: Remove after debugging
                'expectedFileHash' => $fileHash,
            ]);
        }

        // TODO: Inform the user if the breakpoint wasn't added
        $this->addBreakpoint((array) $request + ['absoluteFilePath' => $filePath]);

        $this->saveBreakpointsInConfigurationFile();

        return $this->webSocketsClient->prepareResponse('client-set-breakpoint-response', (array) $request + [
            'error' => false,
        ]);
    }

    public function handleRemoveBreakpoint(stdClass $request): array
    {
        $this->markClientAsActive($request->clientId);

        $this->removeBreakpoint($request);

        $this->saveBreakpointsInConfigurationFile();

        return $this->webSocketsClient->prepareResponse('client-remove-breakpoint-response', (array) $request + [
            'error' => false,
        ]);
    }

    public function handleClientKeepalive(stdClass $request): array
    {
        d('Handling client keepAlive');

        $this->markClientAsActive($request->clientId);

        return $this->webSocketsClient->doNotRespond();
    }

    public function performMaintenance(): void
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
        foreach ($this->activeClients as $clientId => $lastSeen) {
            if ($lastSeen < time() - $clientTimeoutSeconds) {
                d($clientId . ' has been last seen more than ' . $clientTimeoutSeconds . ' seconds ago. Removing breakpoints set by this client.');
                $this->removeBreakpointSetByClient($clientId);
                unset($this->activeClients[$clientId]);
            }
        }
    }

    private function sendLogsToServer(): void
    {
        // TODO: Make time interval between process runs configurable
        if ($this->alreadyRun('sending-logs', 1)) {
            return;
        }

        // d('Sending logs');

        // TODO: Create "logs" folder automatically if it does not exist
        $logPath = $this->extensionConfigDir . 'logs/';
        $logs = scandir($logPath, SCANDIR_SORT_ASCENDING);

        foreach ($logs as $logFilename) {
            if ($logFilename == '.' || $logFilename == '..') {
                continue;
            }

            $logFile = $logPath . $logFilename;

            $this->webSocketsClient->sendMessage('client-debugging-event', file_get_contents($logFile), compress: true);

            unlink($logFile);
        }
    }

    private function alreadyRun($process, $timeIntervalInSeconds): bool
    {
        if (isset($this->processTimestamps[$process]) === false || time() > $this->processTimestamps[$process] + $timeIntervalInSeconds) {
            $this->processTimestamps[$process] = time();
            return false;
        }

        return true;
    }

    private function markClientAsActive($clientId): void
    {
        $this->activeClients[$clientId] = time();
    }

    private function removeBreakpointSetByClient($clientId): void
    {
        foreach ($this->breakpoints as $breakpointId => $breakpoint) {
            if ($breakpoint['clientId'] == $clientId) {
                unset($this->breakpoints[$breakpointId]);
            }
        }

        $this->saveBreakpointsInConfigurationFile();
    }

    private function removeBreakpoint(stdClass $breakpointToRemove): bool
    {
        foreach ($this->breakpoints as $breakpointId => $breakpoint) {
            if (
                $breakpoint['filePath'] == $breakpointToRemove->filePath &&
                $breakpoint['lineNo'] == $breakpointToRemove->lineNo &&
                $breakpoint['id'] == $breakpointToRemove->id
            ) {
                unset($this->breakpoints[$breakpointId]);
                return true;
            }
        }

        return false;
    }

    private function addBreakpoint(array $breakpointToAdd): bool {
        // Check if the breakpoint hasn't been already added
        // TODO: Check breakpoint uniqueness also based on the type, condition and variable to dump
        foreach ($this->breakpoints as $breakpoint) {
            if (
                $breakpoint['filePath'] == $breakpointToAdd['filePath'] &&
                $breakpoint['lineNo'] == $breakpointToAdd['lineNo'] &&
                // TODO: Allow setting multiple equal breakpoints only for the public demo?
                $breakpoint['clientId'] == $breakpointToAdd['clientId']
            ) {
                return false;
            }
        }

        // TODO: Add support for conditional breakpoints
        $breakpointToAdd['condition'] = '1';

        // Placeholder for future functionality
        // E.g. capability to add timers and counters instead of logpoints
        $breakpointToAdd['type'] = 'bp_type';

        $this->breakpoints[] = $breakpointToAdd;

        return true;
    }

    private function saveBreakpointsInConfigurationFile(): void
    {
        $debuggerCallback = '\\CodeInsights\\Debugger\\Helper::debug(\'\', \'\', get_defined_vars(), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), __FILE__, __LINE__);';
        $breakpointsConfiguration = 'version=1' . "\n";

        foreach ($this->breakpoints as $breakpoint) {
            $breakpointsConfiguration .= "\n";
            $breakpointsConfiguration .= 'id=' . $breakpoint['id'] . "\n";
            $breakpointsConfiguration .= $breakpoint['type'] . "\n";
            $breakpointsConfiguration .= $breakpoint['absoluteFilePath'] . "\n";
            $breakpointsConfiguration .= $breakpoint['lineNo'] . "\n";
            $breakpointsConfiguration .= $breakpoint['condition'] . "\n";
            $breakpointsConfiguration .= $debuggerCallback . "\n";
        }

        file_put_contents($this->extensionConfigDir . ini_get('codeinsights.breakpoint_file'), $breakpointsConfiguration, LOCK_EX);
    }

    private function _determineExtensionConfigDir(): void {
        $extensionConfigurationPath = ini_get('codeinsights.directory');

        if (empty($extensionConfigurationPath)) {
            dd('Extension hasn\'t been installed or is incorrectly configured. Missing "codeinsights.directory" php.ini configuration directive showing where to look for breakpoints and debug dumps.');
        }

        if (substr($extensionConfigurationPath, -1) !== DIRECTORY_SEPARATOR)
        {
            $extensionConfigurationPath .= DIRECTORY_SEPARATOR;
        }

        $this->extensionConfigDir = $extensionConfigurationPath;
    }

    private function _verifyExtensionConfigDir(): void {
        $logsFolder = $this->extensionConfigDir . 'logs/';

        if (file_exists($logsFolder) === false) {
            dd('Extension hasn\'t been installed or is incorrectly configured. Logs folder "' . $logsFolder . '" for debug dumps does not exist.');
        }

        if (is_writable($logsFolder) === false) {
            dd('Extension hasn\'t been installed or is incorrectly configured. Logs folder "' . $logsFolder . '" for debug dumps is not writable.');
        }

        $breakpointsConfigurationFile = $this->extensionConfigDir . ini_get('codeinsights.breakpoint_file');

        if (is_writable($breakpointsConfigurationFile) === false) {
            if (touch($breakpointsConfigurationFile) !== true) {
                dd('Extension hasn\'t been installed or is incorrectly configured. Breakpoints configuration file "' . $breakpointsConfigurationFile . '" is not writable.');
            }
        }
    }
}
