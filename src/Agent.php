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
        $this->projectWebroot = '/app/';

        d('Project webroot: ' . $this->projectWebroot);

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

        d('Request file path:' . $request->filePath);


        $filePath = $this->projectWebroot . $request->filePath;

        d('Relfilepath:' . realpath($filePath));
        d('projectWebroot:' . $this->projectWebroot);

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

        if (
            isset($this->breakpoints[$filePath][$request->lineNo]) === false
            // Check if this specific client hasn't already added this breakpoint
            || in_array($request->clientId, $this->breakpoints[$filePath][$request->lineNo]) === false
        ) {
            // Note the breakpoint and the client interested in this specific breakpoint
            $this->breakpoints[$filePath][$request->lineNo][] = $request->clientId;
        }

        $this->saveBreakpointsInConfigurationFile();

        return $this->webSocketsClient->prepareResponse('client-set-breakpoint-response', (array) $request + [
            'error' => false,
        ]);
    }

    public function handleRemoveBreakpoint(stdClass $request): array
    {
        $this->markClientAsActive($request->clientId);

        $this->removeBreakpoint($request->filePath, $request->lineNo, $request->clientId);

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
        foreach ($this->breakpoints as $filePath => $lines) {
            foreach ($lines as $lineNo => $clients) {
                $this->removeBreakpoint($filePath, $lineNo, $clientId);
            }
        }

        $this->saveBreakpointsInConfigurationFile();
    }

    private function removeBreakpoint($filePath, $lineNo, $clientId): void
    {
        if (isset($this->breakpoints[$filePath][$lineNo]) === false) {
            return;
        }

        foreach ($this->breakpoints[$filePath][$lineNo] as $array_key => $client) {
            if ($client === $clientId) {
                unset($this->breakpoints[$filePath][$lineNo][$array_key]);

                if (empty($this->breakpoints[$filePath][$lineNo])) {
                    unset($this->breakpoints[$filePath][$lineNo]);
                }

                if (empty($this->breakpoints[$filePath])) {
                    unset($this->breakpoints[$filePath]);
                }
            }
        }
    }

    private function saveBreakpointsInConfigurationFile(): void
    {
        $debuggerCallback = '\\CodeInsights\\Debugger\\Helper::debug(\'\', \'\', get_defined_vars(), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), __FILE__, __LINE__);';
        $breakpointsConfiguration = 'version=1' . "\n";

        // TODO: Add support for conditional breakpoints
        $breakpointCondition = '1';

        // Placeholder for future functionality
        // E.g. capability to add timers and counters instead of logpoints
        $breakpointType = 'bp_type';

        $breakpointId = 0;

        foreach ($this->breakpoints as $filePath => $listOfBreakpoints) {
            foreach (array_keys($listOfBreakpoints) as $breakpoint) {
                // Currently extension expects id to be a numeric value
                // $breakpointId = uniqid('', true);

                $breakpointsConfiguration .= "\n" . 'id=' . $breakpointId . "\n" . $breakpointType . "\n" . $filePath . "\n" . $breakpoint . "\n" . $breakpointCondition . "\n" . $debuggerCallback . "\n";

                $breakpointId++;
            }
        }

        file_put_contents($this->extensionConfigDir . 'breakpoints.txt', $breakpointsConfiguration, LOCK_EX);
    }

    private function _determineExtensionConfigDir(): void {
        $extensionConfigurationPath = $_ENV['CODEINSIGHTS_DIRECTORY'];

        if (empty($extensionConfigurationPath)) {
            dd('Extension hasn\'t been installed or is incorrectly configured. Missing "CODEINSIGHTS_DIRECTORY" environemnt variable showing where to look for breakpoints and debug dumps.');
        }

        if (substr($extensionConfigurationPath, -1) !== DIRECTORY_SEPARATOR)
        {
            $extensionConfigurationPath .= DIRECTORY_SEPARATOR;
        }

        $this->extensionConfigDir = $extensionConfigurationPath;
    }

    private function _verifyExtensionConfigDir(): void {
        if (file_exists($this->extensionConfigDir . 'logs/') === false) {
            d('Extension hasn\'t been installed or is incorrectly configured. Logs folder for debug dumps does not exist.');
            mkdir($this->extensionConfigDir . 'logs/', 0777, true);
        }

        if (is_writable($this->extensionConfigDir . 'logs/') === false) {
            dd('Extension hasn\'t been installed or is incorrectly configured. Logs folder for debug dumps is not writable.');
        }

        $breakpointsConfigurationFile = $this->extensionConfigDir . 'breakpoints.txt';

        if (is_writable($breakpointsConfigurationFile) === false) {
            if (touch($breakpointsConfigurationFile) !== true) {
                dd('Extension hasn\'t been installed or is incorrectly configured. Breakpoints configuration file is not writable.');
            }
        }
    }
}
