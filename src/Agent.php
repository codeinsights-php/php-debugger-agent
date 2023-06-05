<?php

namespace CodeInsights\Debugger\Agent;

use stdClass;

class Agent
{
    private array $breakpoints = [];
    private array $processTimestamps = [];

    private string $extensionConfigDir;

    public function __construct(
        private WebSocketsClient $webSocketsClient,
    ) {
        $this->_determineExtensionConfigDir();
        $this->_verifyExtensionConfigDir();

        // Clear existing breakpoints from the configuration file upon startup
        $this->saveBreakpointsInConfigurationFile();
    }

    public function handleLogpointAdd(array $request): array
    {
        d('Handling command setBreakpoint');

        if (file_exists($request['file_path']) !== true) {
            return $this->webSocketsClient->prepareResponse('logpoint-add-error', $request + [
                'error' => true,
                'errorMessage' => 'File does not exist.',
            ]);
        }

        $fileHash = hash('xxh32', file_get_contents($request['file_path']));

        if ($request['file_hash'] !== $fileHash) {
            return $this->webSocketsClient->prepareResponse('logpoint-add-error', $request + [
                'error' => true,
                'errorMessage' => 'PHP file contents in production environment differ. Please make sure you are using identical version ' .
                    'to the one in production environment when setting breakpoints.',
            ]);
        }

        $this->addBreakpoint($request);

        $this->saveBreakpointsInConfigurationFile();

        return $this->webSocketsClient->prepareResponse('logpoint-added', [
            'logpoint_id' => $request['logpoint_id'],
            'project_id' => $request['project_id'],
        ]);
    }

    public function handleLogpointRemove(array $request): array
    {
        // TODO: Verify if the logpoint actually exists
        $projectId = $this->breakpoints[$request['logpoint_id']]['project_id'];

        // TODO: Validate if logpoint really was removed (if the logpoint really did exist)
        $this->removeBreakpoint($request['logpoint_id']);

        return $this->webSocketsClient->prepareResponse('logpoint-removed', [
            'logpoint_id' => $request['logpoint_id'],
            'project_id' => $projectId,
        ]);
    }

    public function handleLogpointsList(array $request): array
    {
        $this->breakpoints = $request;

        return $this->webSocketsClient->doNotRespond();
    }

    public function performMaintenance(): void
    {
        // d('Performing maintenance');

        $this->sendLogsToServer();
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

            if (substr($logFilename, -8) == '.message') {
                $messageRaw = file_get_contents($logFile);
                $message = json_decode($messageRaw);

                if ($message->type == 'error-when-evaluating-breakpoint') {
                    $this->removeBreakpoint($message->breakpoint_id);
                }

                $this->webSocketsClient->sendMessage('client-debugging-event-' . $message->type, $messageRaw);
            }
            else {
                $this->webSocketsClient->sendMessage('client-debugging-event', file_get_contents($logFile), compress: true);
            }

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

    private function removeBreakpoint($breakpointToRemove_id): bool
    {
        if (isset($this->breakpoints[$breakpointToRemove_id]) === false)
        {
            return false;
        }

        unset($this->breakpoints[$breakpointToRemove_id]);
        $this->saveBreakpointsInConfigurationFile();

        return true;
    }

    private function addBreakpoint(array $breakpointToAdd): void {
        // Should we check if the breakpoint hasn't been already added?

        // TODO: Add support for conditional breakpoints
        $breakpointToAdd['condition'] = '1';

        // Placeholder for future functionality
        // E.g. capability to add timers and counters instead of logpoints
        $breakpointToAdd['type'] = 'debug_helper';

        $this->breakpoints[$breakpointToAdd['logpoint_id']] = $breakpointToAdd;
    }

    private function saveBreakpointsInConfigurationFile(): void
    {
        $debuggerCallback = '\\CodeInsights\\Debugger\\Helper::debug(\'\', \'\', get_defined_vars(), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), __FILE__, __LINE__);';
        $breakpointsConfiguration = 'version=1' . "\n";

        foreach ($this->breakpoints as $breakpoint) {
            $breakpointsConfiguration .= "\n";
            $breakpointsConfiguration .= 'id=' . $breakpoint['logpoint_id'] . "\n";
            $breakpointsConfiguration .= $breakpoint['type'] . "\n";
            $breakpointsConfiguration .= $breakpoint['file_path'] . "\n";
            $breakpointsConfiguration .= $breakpoint['line_number'] . "\n";
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
