<?php

namespace Tests;

use WebSocket\Client;
use Exception;

class WebSocketsClient
{
   private $client;
   private string $clientType;
   public int $clientId;
   public string $clientSecretKey;

   public bool $skipMessageAuthenticationRequired = true;
   public bool $skipMessageAuthenticationResult = true;

   public function __construct(string $clientType, int $clientId, string $clientSecretKey)
   {
      if ($clientType !== 'user' && $clientType !== 'server') {
         throw new Exception('Invalid WebSockets client type provided.');
      }

      $this->clientType = $clientType;
      $this->clientId = $clientId;
      $this->clientSecretKey = $clientSecretKey;

      $contextOptions = stream_context_create([
         'ssl' => [
            'verify_peer' => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
         ],
      ]);

      $this->client = new Client($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENDPOINT'], ['context' => $contextOptions]);
   }

   public function receiveMessage(): string
   {
      // Will block further code execution until something is received from the server
      // But will time out after 5 seconds or so
      $receivedMessage = $this->client->receive();

      $payload = json_decode($receivedMessage);

      // Analyse received message contents and perform authentication if necessary
      if (isset($payload->event) && $payload->event == 'server:connection-established')
      {
         $this->authenticate($payload->data->socket_id);

         if ($this->skipMessageAuthenticationRequired !== false)
         {
            return $this->receiveMessage();
         }
      }

      // Do not bother tests with authentication result message, unless specifically requested
      if (isset($payload->event) && $payload->event == 'server:authentication-result')
      {
         if ($this->skipMessageAuthenticationResult !== false)
         {
            return $this->receiveMessage();
         }
      }

      return $receivedMessage;
   }

   // Assumes no prior communication has been done and skips / "processes" first two messages after connecting
   public function handleAuthenticationMessagesFirst()
   {
      $this->skipMessageAuthenticationRequired = false;
      $this->skipMessageAuthenticationResult = false;
      $this->receiveMessage();
      $this->receiveMessage();
   }

   public function sendMessage(array $message)
   {
      $this->client->text(json_encode($message));
   }

   public function authenticate($socketId)
   {
      if ($this->clientType == 'user')
      {
         $this->_authenticateAsUser($socketId);
      }
      elseif ($this->clientType == 'server')
      {
         $this->_authenticateAsServer($socketId);
      }
   }

   private function _authenticateAsUser($socketId)
   {
      $authentication_signature = hash_hmac('sha256', $socketId . ':' . $this->clientId, $this->clientSecretKey);

      $this->sendMessage([
         'event' => 'authenticate-as-user',
         'data' => [
            'user_id' => $this->clientId,
            'auth' => $authentication_signature,
         ]
      ]);
   }

   private function _authenticateAsServer($socketId)
   {
      $authentication_signature = hash_hmac('sha256', $socketId . ':' . $this->clientId, $this->clientSecretKey);

      $this->sendMessage([
         'event' => 'authenticate-as-server',
         'data' => [
            'api_key_id' => $this->clientId,
            'auth' => $authentication_signature,
            'host_info' => [
               'internal_ip' => '127.0.0.1',
               'hostname' => gethostname(),
               'php_version' => phpversion(),
               'extension_version' => '0.1',
               'labels' => [
                  'env' => 'local',
               ],
            ],
         ]
      ]);
   }

   public function ping()
   {
      $this->sendMessage([
         'event' => 'ping',
         'data' => [],
      ]);
   }

   public function closeConnection()
   {
      $this->client->disconnect();
   }
}
