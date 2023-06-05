<?php

use Tests\WebSocketsClient;

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
