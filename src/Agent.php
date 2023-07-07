<?php

namespace CodeInsights\Debugger\Agent;

use stdClass;

class Agent
{
    private array $breakpoints = [];
    private array $processTimestamps = [];

    private string $extensionConfigDir;
    private bool $useE2Eencryption;

    public function __construct(
        private WebSocketsClient $webSocketsClient,
    ) {
        $this->_determineExtensionConfigDir();
        $this->_verifyExtensionConfigDir();

        // Determine if end-to-end encryption is enabled and should be used
        $this->useE2Eencryption = (isset($_ENV['CODEINSIGHTS_MESSAGING_SERVER_USE_E2E_ENCRYPTION']) && $_ENV['CODEINSIGHTS_MESSAGING_SERVER_USE_E2E_ENCRYPTION'] === 'true');

        if ($this->useE2Eencryption) {
            d('E2E encryption enabled.');
        }

        if ($this->useE2Eencryption === true && empty($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENCRYPTION_KEY_BASE64_ENCODED']) === true) {
            dd('Incomplete configuration, .env has end-to-end encryption enabled, but no encryption key has been provided.');
        }

        if ($this->useE2Eencryption === true && strlen(base64_decode($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENCRYPTION_KEY_BASE64_ENCODED'])) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            dd('Incomplete configuration, .env has end-to-end encryption enabled, but encryption key has incorrect length.');
        }
    }

    public function handleLogpointAdd(array $request): void
    {
        d('Handling command setBreakpoint');

        $breakpointAdded = $this->addBreakpoint($request, true);

        $this->saveBreakpointsInConfigurationFile();

        if ($breakpointAdded) {
            $this->webSocketsClient->sendMessage([
                'event' => 'logpoint-added',
                'header' => [
                    'logpoint_id' => $request['header']['logpoint_id'],
                    'project_id' => $request['header']['project_id'],
                ],
            ]);
        }
    }

    public function handleLogpointRemove(array $request): void
    {
        $this->removeBreakpoint($request['header']['logpoint_id']);
    }

    public function handleLogpointsList(array $request): void
    {
        // Should we inform the server about errors adding logpoints upon Agent startup?
        foreach ($request['data'] as $logpoint)
        {
            // TODO: Decrypt each logpoint's 'data'
            $this->addBreakpoint($logpoint);
        }

        $this->saveBreakpointsInConfigurationFile();
    }

    public function performMaintenance(): void
    {
        // d('Performing maintenance');
        // TODO: Check if connection hasn't been authenticated X seconds after connecting
        $this->sendLogsToServer();
    }

    private function sendLogsToServer(): void
    {
        // TODO: Make time interval between process runs configurable
        $intervalInSecondsBetweenLogSends = 1;

        if (isset($_ENV['TIME_INTERVAL_IN_SECONDS_BETWEEN_DEBUG_DUMPS_BEING_SENT'])
            && $_ENV['TIME_INTERVAL_IN_SECONDS_BETWEEN_DEBUG_DUMPS_BEING_SENT'] >= 0) {
                // Zero seconds should result in at least 100ms between event runs
                // because that is how often the React event loop periodic timer ticks
                // as configured (hard-coded) in StartAgent.php
                $intervalInSecondsBetweenLogSends = $_ENV['TIME_INTERVAL_IN_SECONDS_BETWEEN_DEBUG_DUMPS_BEING_SENT'];
        }

        if ($this->alreadyRun('sending-logs', $intervalInSecondsBetweenLogSends)) {
            return;
        }

        // d('Sending logs');

        // TODO: Create "logs" folder automatically if it does not exist
        $logPath = $this->extensionConfigDir . 'messages-outgoing/';
        $logs = scandir($logPath, SCANDIR_SORT_ASCENDING);

        foreach ($logs as $logFilename) {
            if ($logFilename == '.' || $logFilename == '..') {
                continue;
            }

            $logFile = $logPath . $logFilename;

            $dataToSend = file_get_contents($logFile);
            d('Sending raw message from Helper:' . "\n" . $dataToSend);

            $message = json_decode($dataToSend, true);

            $compressMessage = true;

            if (isset($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENABLE_COMPRESSION_FOR_DEBUG_DUMPS'])
                && $_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENABLE_COMPRESSION_FOR_DEBUG_DUMPS'] == 'false')
            {
                $compressMessage = false;
            }

            $encryptMessage = $this->useE2Eencryption;

            if ($message['event'] == 'logpoint-error-evaluating') {
                $encryptMessage = false;
            }

            // TODO: Enable data compression (configurable to make it compatible with end-to-end testing?)
            $this->webSocketsClient->sendMessage($message, $encryptMessage, $compressMessage);

            if ($message['event'] == 'logpoint-error-evaluating') {
                $this->removeBreakpoint($message['header']['logpoint_id']);
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

    private function removeBreakpoint($breakpointToRemove_id): void
    {
        if (isset($this->breakpoints[$breakpointToRemove_id]) === false)
        {
            // TODO: Raise error?
            d('Unexpected error. Logpoint that was requested to be removed does not exist.');
            return;
        }

        $projectId = $this->breakpoints[$breakpointToRemove_id]['project_id'];

        d('Removing breakpoint ' . $breakpointToRemove_id);

        unset($this->breakpoints[$breakpointToRemove_id]);
        $this->saveBreakpointsInConfigurationFile();

        $this->webSocketsClient->sendMessage([
            'event' => 'logpoint-removed',
            'header' => [
                'logpoint_id' => $breakpointToRemove_id,
                'project_id' => $projectId,
            ],
        ]);
    }

    private function addBreakpoint(array $logpoint, bool $informServerAboutErrors = false): bool {
        // Should we check if the breakpoint hasn't been already added?

        $logpoint = array_merge($logpoint['header'], $logpoint['data']);

        $filePath = $logpoint['webroot'] . $logpoint['file_path'];

        if (file_exists($filePath) !== true) {
            $errorMessage = 'File does not exist.';
            $this->_reportErrorAddingLogpoint($logpoint['logpoint_id'], $logpoint['project_id'], 1001, $errorMessage, $informServerAboutErrors);
            return false;
        }

        // Realpath also fails if path does not exist, so we do it only as an extra precaution
        // after checking if the requested file exists
        $realPath = realpath(dirname($filePath)) . DIRECTORY_SEPARATOR;

        if (substr($realPath, 0, strlen($logpoint['webroot'])) !== $logpoint['webroot']) {
            $errorMessage = 'Invalid file path - trying to set logpoint outside the webroot.';
            $this->_reportErrorAddingLogpoint($logpoint['logpoint_id'], $logpoint['project_id'], 1000, $errorMessage, $informServerAboutErrors);
            return false;
        }

        $fileHash = hash('xxh32', file_get_contents($filePath));

        if ($logpoint['file_hash'] !== $fileHash) {
            $errorMessage = 'PHP file contents in production environment differ. Please make sure you are using identical version ' .
                'to the one in production environment when setting breakpoints.';
            $this->_reportErrorAddingLogpoint($logpoint['logpoint_id'], $logpoint['project_id'], 1002, $errorMessage, $informServerAboutErrors);
            return false;
        }

        // TODO: Add support for conditional breakpoints
        $logpoint['condition'] = '1';

        $this->breakpoints[$logpoint['logpoint_id']] = $logpoint;

        return true;
    }

    private function _reportErrorAddingLogpoint(string $logpointId, int $projectId, int $errorCode, string $errorReason, bool $informServerAboutErrors): void {

        d('Error adding logpoint ' . $logpointId . ' - (' . $errorCode . ') ' . $errorReason);

        if ($informServerAboutErrors) {
            $this->webSocketsClient->sendMessage([
                'event' => 'logpoint-error-adding',
                'header' => [
                    'logpoint_id' => $logpointId,
                    'project_id' => $projectId,
                ],
                'data' => [
                    'errorCode' => $errorCode,
                    'errorMessage' => $errorReason,
                ],
            ]);
        }
    }

    private function saveBreakpointsInConfigurationFile(): void
    {
        $breakpointsConfiguration = 'version=1' . "\n";

        foreach ($this->breakpoints as $breakpoint) {

            switch ($breakpoint['type'])
            {
                case 'snapshot':
                    $debuggerCallback = '\\CodeInsights\\Debugger\\Helper::debug(\'\', \'\', get_defined_vars(), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), __FILE__, __LINE__, %PROJECT_ID%);';
                    break;
                case 'log':
                    // TODO: Sanitize variable to avoid code injection
                    $variable_to_dump = $breakpoint['log_variable'];
                    $variable_to_dump = str_replace(';', '', $variable_to_dump);
                    $variable_to_dump = str_replace(',', '', $variable_to_dump);

                    $debuggerCallback = '\\CodeInsights\\Debugger\\Helper::debug(' . $variable_to_dump . ', \'' . addslashes($breakpoint['log_variable']) . '\', array(), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), __FILE__, __LINE__, %PROJECT_ID%);';

                    break;
                default:
                    // TODO: Throw an error?
                    d('Unrecognized logpoint type encountered, not saving');
                    print_r($breakpoint);
                    continue 2;
            }

            $debuggerCallbackWithParams = str_replace('%PROJECT_ID%', $breakpoint['project_id'], $debuggerCallback);
            $breakpointsConfiguration .= "\n";
            $breakpointsConfiguration .= 'id=' . $breakpoint['logpoint_id'] . "\n";
            // This breakpoint "type" triggers error reporting mechanism upon faulty evaluation:
            $breakpointsConfiguration .= 'debug_helper' . "\n";
            $breakpointsConfiguration .= $breakpoint['webroot'] . $breakpoint['file_path'] . "\n";
            $breakpointsConfiguration .= $breakpoint['line_number'] . "\n";
            $breakpointsConfiguration .= $breakpoint['condition'] . "\n";
            $breakpointsConfiguration .= $debuggerCallbackWithParams . "\n";
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
        $logsFolder = $this->extensionConfigDir . 'messages-outgoing/';

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
