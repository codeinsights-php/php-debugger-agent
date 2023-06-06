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

   $file_path = 'app/Http/Controllers/NewsletterController.php';

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-add',
      'data' => [
         'file_path' => $file_path,
         // TODO: Read hash dynamically
         'file_hash' => '489ffad6',
         'line_number' => 18,
      ],
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
18
1
\\CodeInsights\\Debugger\\Helper::debug(\'\', \'\', get_defined_vars(), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), __FILE__, __LINE__, ' . $project_id . ');
');

});

it('removes logpoints', function () {

   $this->webSocketsClientUser->handleAuthenticationMessagesFirst();

   $file_path = 'app/Http/Controllers/NewsletterController.php';

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-add',
      'data' => [
         'file_path' => $file_path,
         'file_hash' => '489ffad6',
         'line_number' => 18,
      ],
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

   $this->webSocketsClientUser->handleAuthenticationMessagesFirst();

   $this->webSocketsClientUser->sendMessage([
      'event' => 'logpoint-add',
      'data' => [
         'file_path' => $file_path,
         'file_hash' => $file_hash,
         'line_number' => $line_number,
      ],
   ]);

   // Skip confirmation that logpoint will be sent
   $this->webSocketsClientUser->receiveMessage();

   // Skip confirmation that logpoint was added
   $this->webSocketsClientUser->receiveMessage();

   // Trigger evaluation of the breakpoint
   file_get_contents('https://laravel-sample-project.local/');

   // Wait for the Agent to forward debug data
   $message = $this->webSocketsClientUser->receiveMessage();
   expect($message)->toBeJson();

   $messageHeader = substr($message, 0, 44);
   expect($messageHeader)->toBe('{"event":"debug-event","data":{"project_id":');

});

// TODO: Add test for failing to add logpoint - file does not exist
// TODO: Add test for failing to add logpoint - file hash mismatch

// TODO: How do we test that errors evaluating logpoints are being sent to server?
// TODO: How do we test that Agent removes logpoint upon error evaluating logpoint?

// TODO: How do we test that upon connection Agent reads full list of previously added logpoints?
