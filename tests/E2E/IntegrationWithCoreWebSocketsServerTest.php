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

   expect($message)->toBe('{"event":"server:authentication-result","data":{"result":true}}');

   $this->webSocketsClientServer->skipMessageAuthenticationRequired = true;
   $this->webSocketsClientServer->skipMessageAuthenticationResult = false;

   $message = $this->webSocketsClientServer->receiveMessage();

   expect($message)->toBe('{"event":"server:authentication-result","data":{"result":true}}');

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
   $logpoint_id = $message['data']['logpoint_id'];
   $project_id = $message['data']['project_id'];

   // Only the server (Agent) receives info about the full path to the file (including webroot)
   $message = json_decode($this->webSocketsClientServer->receiveMessage(), true);
   $file_path = $message['data']['file_path'];

   // Wait for the confirmation from the Agent
   $message = $this->webSocketsClientUser->receiveMessage();

   // Closing connection early so that added logpoints get removed automatically
   // Otherwise the next test will fail due to an old logpoint still lingering from this test
   $this->webSocketsClientUser->closeConnection();

   expect($message)->toBe('{"event":"logpoint-added","data":{"logpoint_id":"' . $logpoint_id . '"}}');

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

   $logpoint_id = $message['data']['logpoint_id'];

   // Skip confirmation that logpoint was added
   $this->webSocketsClientUser->receiveMessage();

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-remove',
      'data' => [
         'logpoint_id' => $logpoint_id,
      ],
   ]);

   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);

   expect($message)->toMatchArray([
      'event' => 'logpoint-removal-pending',
      'data' => [
         'logpoint_id' => $logpoint_id,
         'removal_reason_code' => 101,
         'removal_reason_message' => 'Logpoint removed by a user',
      ],
   ]);

   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);

   expect($message)->toMatchArray([
      'event' => 'logpoint-removed',
      'data' => [
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

   // Trigger evaluation of the breakpoint
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

   $messageHeader = substr($message, 0, 44);
   expect($messageHeader)->toBe('{"event":"debug-event","data":{"project_id":');

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

   // Trigger evaluation of the breakpoint
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

   $messageHeader = substr($message, 0, 44);
   expect($messageHeader)->toBe('{"event":"debug-event","data":{"project_id":');

   // Check if debug event contains the dumped request variable
   // in a specific format (plain-text output generated by Symfony's VarDumper)
   // without checking the overall dump structure
   expect($message)->toContain('"dump_readable":"request([\'test\']) = array:1 [\n  \"test\" => \"get_variable\"\n];\n"');

});

it('reports errors evaluating breakpoints', function() {

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
   $logpoint_id = $message['data']['logpoint_id'];

   // Skip confirmation that logpoint was added
   $this->webSocketsClientUser->receiveMessage();

   // Trigger evaluation of the breakpoint
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
      'data' => [
         'logpoint_id' => $logpoint_id,
         'removal_reason_code' => 102,
         'removal_reason_message' => $message['data']['removal_reason_message'],
      ],
   ]);

   // Agent should inform about the removal of the logpoint causing errors
   $message = json_decode($this->webSocketsClientUser->receiveMessage(), true);
   expect($message)->toMatchArray([
      'event' => 'logpoint-removed',
      'data' => [
         'logpoint_id' => $logpoint_id,
      ],
   ]);

});

it('removes logpoint upon encountering error evaluating breakpoint')->todo();

it('reports error adding logpoint if the file does not exist')->todo();

it('reports error adding logpoint if the hash of the file does not match')->todo();

it('compresses outgoing data')->todo();

it('encrypts outgoing data')->todo();

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
