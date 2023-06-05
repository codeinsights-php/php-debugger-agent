<?php

use Tests\WebSocketsClient;

beforeAll(function () {
   // echo 'Info that Agent and WebSockets server have to be running for these tests.' . "\n";
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

   $file_path = '/data/www/laravel-sample-project/app/Http/Controllers/NewsletterController.php';

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
   $project_id = $message['data']['project_id'];

   // Wait for the confirmation from the Agent
   $message = $this->webSocketsClientUser->receiveMessage();

   expect($message)->toBe('{"event":"logpoint-added","data":{"logpoint_id":"' . $logpoint_id . '","project_id":' . $project_id . '}}');

   // Verify that Agent wrote the configuration file
   $logpointsConfiguration = file_get_contents(ini_get('codeinsights.directory') . ini_get('codeinsights.breakpoint_file'));

   expect($logpointsConfiguration)->toBe('version=1

id=' . $logpoint_id . '
debug_helper
' . $file_path . '
18
1
\\CodeInsights\\Debugger\\Helper::debug(\'\', \'\', get_defined_vars(), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), __FILE__, __LINE__);
');

   $this->webSocketsClientUser->closeConnection();

});

it('removes logpoints', function () {

   $this->webSocketsClientUser->handleAuthenticationMessagesFirst();

   $file_path = '/data/www/laravel-sample-project/app/Http/Controllers/NewsletterController.php';

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

   // Verify that Agent wrote the configuration file
   $logpointsConfiguration = file_get_contents(ini_get('codeinsights.directory') . ini_get('codeinsights.breakpoint_file'));

   expect($logpointsConfiguration)->toBe('version=1' . "\n");

});

// TODO: Add test for failing to add logpoint - file does not exist
// TODO: Add test for failing to add logpoint - file hash mismatch

// TODO: How do we test that debug dumps are being sent?
// TODO: How do we test that errors evaluating logpoints are being sent to server?
// TODO: How do we test that Agent removes logpoint upon error evaluating logpoint?

// TODO: How do we test that upon connection Agent reads full list of previously added logpoints?
