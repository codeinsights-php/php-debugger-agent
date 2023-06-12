<?php

use Tests\WebSocketsClient;

beforeAll(function () {
   // echo 'Info that Agent and WebSockets server have to be running for these tests.' . "\n";
   // And the API key has to be associated with the Laravel sample project in the Core / WebSockets server
   // And the Laravel sample project has to have debugger Helper library included
});

beforeEach(function () {
   // Each test initializes clients again
   $this->webSocketsClientUser = new WebSocketsClient('user', $_ENV['TEST_USER_ID'], $_ENV['TEST_USER_SECRET']);
   $this->webSocketsClientServer = new WebSocketsClient('server', $_ENV['API_KEY_ID'], $_ENV['API_KEY_ACCESS_TOKEN']);
});

it('can connect to server and authenticate', function () {

   $this->webSocketsClientUser->skipMessageAuthenticationRequired = true;
   $this->webSocketsClientUser->skipMessageAuthenticationResult = false;
   $message = $this->webSocketsClientUser->receiveMessage();

   expect($message)->toBe('{"event":"server:authentication-result","header":{"result":true}}');

   $this->webSocketsClientServer->skipMessageAuthenticationRequired = true;
   $this->webSocketsClientServer->skipMessageAuthenticationResult = false;

   $message = $this->webSocketsClientServer->receiveMessage();

   expect($message)->toBe('{"event":"server:authentication-result","header":{"result":true}}');

});

it('adds logpoints', function () {

   $this->webSocketsClientUser->handleAuthenticationMessagesFirst();
   $this->webSocketsClientServer->handleAuthenticationMessagesFirst();

   $logpoint = addLogpoint();

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-add',
      'data' => $logpoint,
   ]);

   // Read data from confirmation that logpoint is propagated across servers
   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);
   $logpoint_id = $message['header']['logpoint_id'];
   $project_id = $message['header']['project_id'];

   // Only the server (Agent) receives info about the full path to the file (including webroot)
   $message = json_decode($this->webSocketsClientServer->receiveMessage(), true);
   $file_path = $message['header']['webroot'] . $message['data']['file_path'];

   // Wait for the confirmation from the Agent
   $message = $this->webSocketsClientUser->receiveMessage();

   // Closing connection early so that added logpoints get removed automatically
   // Otherwise the next test will fail due to an old logpoint still lingering from this test
   $this->webSocketsClientUser->closeConnection();

   expect($message)->toBe('{"event":"logpoint-added","header":{"logpoint_id":"' . $logpoint_id . '"}}');

   // Verify that Agent wrote the configuration file
   $logpointsConfiguration = file_get_contents(ini_get('codeinsights.directory') . ini_get('codeinsights.breakpoint_file'));

   expect($logpointsConfiguration)->toBe('version=1

id=' . $logpoint_id . '
debug_helper
' . $file_path . '
' . $logpoint['line_number'] . '
1
\\CodeInsights\\Debugger\\Helper::debug(\'\', \'\', get_defined_vars(), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), __FILE__, __LINE__, ' . $project_id . ');
');

});

it('removes logpoints', function () {

   $this->webSocketsClientUser->handleAuthenticationMessagesFirst();

   $logpoint = addLogpoint();

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-add',
      'data' => $logpoint,
   ]);

   // Read data from confirmation that logpoint is propagated across servers
   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);

   $logpoint_id = $message['header']['logpoint_id'];

   // Skip confirmation that logpoint was added
   $this->webSocketsClientUser->receiveMessage();

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-remove',
      'header' => [
         'logpoint_id' => $logpoint_id,
      ],
   ]);

   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);

   expect($message)->toMatchArray([
      'event' => 'logpoint-removal-pending',
      'header' => [
         'logpoint_id' => $logpoint_id,
         'removal_reason_code' => 101,
         'removal_reason_message' => 'Logpoint removed by a user',
      ],
   ]);

   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);

   expect($message)->toMatchArray([
      'event' => 'logpoint-removed',
      'header' => [
         'logpoint_id' => $logpoint_id,
      ],
   ]);

   // Verify that Agent wrote the configuration file
   $logpointsConfiguration = file_get_contents(ini_get('codeinsights.directory') . ini_get('codeinsights.breakpoint_file'));

   expect($logpointsConfiguration)->toBe('version=1' . "\n");

});

it('sends debug dumps', function () {

   $logpoint = addLogpoint();

   $this->webSocketsClientUser->handleAuthenticationMessagesFirst();

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-add',
      'data' => $logpoint,
   ]);

   // Skip confirmation that logpoint will be sent
   $this->webSocketsClientUser->receiveMessage();

   // Skip confirmation that logpoint was added
   $this->webSocketsClientUser->receiveMessage();

   // Trigger evaluation of the logpoint
   file_get_contents('https://laravel-sample-project.local/', false, stream_context_create([
      'ssl' => [
         'verify_peer' => false,
         'verify_peer_name' => false,
         'allow_self_signed' => true,
      ],
   ]));

   // Wait for the Agent to forward debug data
   $message = $this->webSocketsClientUser->receiveMessage();
   expect($message)->toBeJson();

   $expectedMessageHeader = '{"event":"debug-event","header":{"project_id":';
   $messageHeader = substr($message, 0, strlen($expectedMessageHeader));
   expect($messageHeader)->toBe($expectedMessageHeader);

});

it('dumps a specific variable', function() {

   $this->webSocketsClientUser->handleAuthenticationMessagesFirst();

   $logpoint = addLogpoint();
   $logpoint['type'] = 'log';
   $logpoint['log_message'] = 'Trying to log an undefined variable';
   $logpoint['log_variable'] = 'request([\'test\'])';

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-add',
      'data' => $logpoint,
   ]);

   // Skip confirmation that logpoint will be sent
   $this->webSocketsClientUser->receiveMessage();

   // Skip confirmation that logpoint was added
   $this->webSocketsClientUser->receiveMessage();

   // Trigger evaluation of the logpoint
   file_get_contents('https://laravel-sample-project.local/?test=get_variable', false, stream_context_create([
      'ssl' => [
         'verify_peer' => false,
         'verify_peer_name' => false,
         'allow_self_signed' => true,
      ],
   ]));

   // Wait for the Agent to forward debug data
   $message = $this->webSocketsClientUser->receiveMessage();

   // Closing connection early so that added logpoints get removed automatically
   // Otherwise the next test will fail due to an old logpoint still lingering from this test
   $this->webSocketsClientUser->closeConnection();

   expect($message)->toBeJson();

   $expectedMessageHeader = '{"event":"debug-event","header":{"project_id":';
   $messageHeader = substr($message, 0, strlen($expectedMessageHeader));
   expect($messageHeader)->toBe($expectedMessageHeader);

   // Check if debug event contains the dumped request variable
   // in a specific format (plain-text output generated by Symfony's VarDumper)
   // without checking the overall dump structure
   expect($message)->toContain('"dump_readable":"request([\'test\']) = array:1 [\n  \"test\" => \"get_variable\"\n];\n"');

});

it('reports errors evaluating logpoints', function() {

   $this->webSocketsClientUser->handleAuthenticationMessagesFirst();

   $logpoint = addLogpoint();
   $logpoint['type'] = 'log';
   $logpoint['log_message'] = 'Trying to log an undefined variable';
   $logpoint['log_variable'] = 'non_existent_function()';

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-add',
      'data' => $logpoint,
   ]);

   // Confirmation that logpoint will be sent
   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);
   $logpoint_id = $message['header']['logpoint_id'];

   // Skip confirmation that logpoint was added
   $this->webSocketsClientUser->receiveMessage();

   // Trigger evaluation of the logpoint
   file_get_contents('https://laravel-sample-project.local/', false, stream_context_create([
      'ssl' => [
         'verify_peer' => false,
         'verify_peer_name' => false,
         'allow_self_signed' => true,
      ],
   ]));

   // Server should receive info about a logpoint error and issue logpoint removal command to all Agents
   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);
   expect($message)->toMatchArray([
      'event' => 'logpoint-removal-pending',
      'header' => [
         'logpoint_id' => $logpoint_id,
         'removal_reason_code' => 102,
         'removal_reason_message' => $message['header']['removal_reason_message'],
      ],
   ]);

   // Agent should inform about the removal of the logpoint causing errors
   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);
   expect($message)->toMatchArray([
      'event' => 'logpoint-removed',
      'header' => [
         'logpoint_id' => $logpoint_id,
      ],
   ]);

});

it('removes logpoint upon encountering error evaluating logpoint', function() {

   $this->webSocketsClientUser->handleAuthenticationMessagesFirst();

   $logpoint = addLogpoint();
   $logpoint['type'] = 'log';
   $logpoint['log_message'] = 'Trying to log an undefined variable';
   $logpoint['log_variable'] = 'non_existent_function()';

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-add',
      'data' => $logpoint,
   ]);

   // Alternatively we can listen the absolute path sent by the server to all the Agents
   $file_path = realpath('../laravel-sample-project/' . $logpoint['file_path']);

   // Confirmation that logpoint will be sent
   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);

   // Skip confirmation that logpoint was added
   $this->webSocketsClientUser->receiveMessage();

   $logpointsConfiguration = file_get_contents(ini_get('codeinsights.directory') . ini_get('codeinsights.breakpoint_file'));

   // Check that Agent has listed the logpoint
   expect($logpointsConfiguration)->toBe('version=1

id=' . $message['header']['logpoint_id'] . '
debug_helper
' . $file_path . '
' . $message['data']['line_number'] . '
1
\\CodeInsights\\Debugger\\Helper::debug(non_existent_function(), \'non_existent_function()\', array(), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), __FILE__, __LINE__, ' . $message['header']['project_id'] . ');
');

   // Trigger evaluation of the logpoint
   file_get_contents('https://laravel-sample-project.local/', false, stream_context_create([
      'ssl' => [
         'verify_peer' => false,
         'verify_peer_name' => false,
         'allow_self_signed' => true,
      ],
   ]));

   // Server should receive info about a logpoint error and issue logpoint removal command to all Agents
   $this->webSocketsClientUser->receiveMessage();

   // Verify that Agent removed the faulty logpoint
   $logpointsConfiguration = file_get_contents(ini_get('codeinsights.directory') . ini_get('codeinsights.breakpoint_file'));

   expect($logpointsConfiguration)->toBe('version=1' . "\n");

});

it('reports error adding logpoint if the file does not exist', function () {

   $this->webSocketsClientUser->handleAuthenticationMessagesFirst();

   $logpoint = addLogpoint();

   $logpoint['file_path'] = $logpoint['file_path'] . '.non-existent-file-suffix';

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-add',
      'data' => $logpoint,
   ]);

   // Read data from confirmation that logpoint is propagated across servers
   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);
   $logpoint_id = $message['header']['logpoint_id'];

   // Agent should report that it was unable to add the file
   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);

   expect($message)->toMatchArray([
      'event' => 'logpoint-removal-pending',
      'header' => [
         'logpoint_id' => $logpoint_id,
         'removal_reason_code' => 103,
         'removal_reason_message' => $message['header']['removal_reason_message'],
      ],
      // TODO: Look also for detailed error reason code
   ]);

});

it('reports error adding logpoint if the hash of the file does not match', function () {

   $this->webSocketsClientUser->handleAuthenticationMessagesFirst();

   $logpoint = addLogpoint();

   $logpoint['file_hash'] = $logpoint['file_hash'] . 'invalid-hash';

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-add',
      'data' => $logpoint,
   ]);

   // Read data from confirmation that logpoint is propagated across servers
   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);
   $logpoint_id = $message['header']['logpoint_id'];

   // Agent should report that it was unable to add the file
   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);

   expect($message)->toMatchArray([
      'event' => 'logpoint-removal-pending',
      'header' => [
         'logpoint_id' => $logpoint_id,
         'removal_reason_code' => 103,
         'removal_reason_message' => $message['header']['removal_reason_message'],
      ],
      // TODO: Look also for detailed error reason code
   ]);

});

it('reports error adding logpoint if the file path is outside the webroot', function () {

   $this->webSocketsClientUser->handleAuthenticationMessagesFirst();

   $logpoint = addLogpoint();

   $logpoint['file_path'] = '../' . $logpoint['file_path'];
   $logpoint['file_path'] = '../codeinsights-php-debugger-agent/StartAgent.php';

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-add',
      'data' => $logpoint,
   ]);

   // Read data from confirmation that logpoint is propagated across servers
   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);
   $logpoint_id = $message['header']['logpoint_id'];

   // Agent should report that it was unable to add the file
   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);

   expect($message)->toMatchArray([
      'event' => 'logpoint-removal-pending',
      'header' => [
         'logpoint_id' => $logpoint_id,
         'removal_reason_code' => 103,
         'removal_reason_message' => $message['header']['removal_reason_message'],
      ],
      // TODO: Look also for detailed error reason code
   ]);

});

// TODO: Run different variations of tests:
// - encryption ON, compression ON
// - encryption ON, compression OFF
// - encryption OFF, compression ON
// - encryption OFF, compression OFF
// (it means restarting Agent, though, because only then configuration changes take effect)

it('compresses outgoing data', function() {

   // If debug dump tests were run succesfully, it means that decompression most probably worked behind the scenes just fine

})->skip(
   (isset($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENABLE_COMPRESSION_FOR_DEBUG_DUMPS']) === true
      && $_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENABLE_COMPRESSION_FOR_DEBUG_DUMPS'] == 'false'),
   'Agent wasn\'t started with the configuration that enables compression usage.'
);

it('encrypts outgoing data', function() {

   // If debug dump tests were run succesfully, it means that decryption most probably worked behind the scenes just fine

})->skip(
   $_ENV['CODEINSIGHTS_MESSAGING_SERVER_USE_E2E_ENCRYPTION'] == 'false',
   'Agent wasn\'t started with the configuration that enables end-to-end encryption usage.'
);

// TODO: How do we test that upon connection Agent reads full list of previously added logpoints?

function addLogpoint(): array
{
   $file_path = 'app/Http/Controllers/PostController.php';

   // Make path to the demo project webroot configurable?
   $real_file_path = '../laravel-sample-project/' . $file_path;

   $file_contents = file_get_contents($real_file_path);

   $file_hash = hash('xxh32', $file_contents);
   $line_number = 0;

   $lines = explode("\n", $file_contents);

   foreach ($lines as $line)
   {
      $line_number++;

      if (strpos($line, 'return view(\'posts.index\'') !== false)
      {
         break;
      }
   }

   return [
      'file_path' => $file_path,
      'file_hash' => $file_hash,
      'line_number' => $line_number,
      'type' => 'snapshot', // or "log"
      'log_message' => 'Logging the result of local context variables',
      // 'log_variable' => '$someValue',
   ];
}
