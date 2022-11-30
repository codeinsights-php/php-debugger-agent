<?php

namespace CodeInsights\Debugger\Agent;

use Exception, stdClass;

class WebSocketsClient {

    private Agent $agent;
    private array $commandHandlers;
    private bool $isConnectedToServer = false;
    private bool $useE2Eencryption;
    private string $channelName;
    public $connection;

    public function __construct()
    {
        $this->agent = new Agent($this);
        $this->useE2Eencryption = (isset($_ENV['CODEINSIGHTS_MESSAGING_SERVER_USE_E2E_ENCRYPTION']) && $_ENV['CODEINSIGHTS_MESSAGING_SERVER_USE_E2E_ENCRYPTION'] === 'true');

        if ($this->useE2Eencryption)
        {
            d('E2E encryption enabled.');
        }

        $this->channelName = 'private-' . ($this->useE2Eencryption === true ? 'encrypted-' : '') . $_ENV['API_KEY_ID'] . '-' . $_ENV['API_KEY_ACCESS_TOKEN'];
    }

    public function handleIncomingMessage(stdClass $message) : array
    {
        if (isset($message->event) === false)
        {
            d('Invalid message received.');
            return $this->doNotRespond();
        }

        // Some messages (e.g. during authentication) are not encrypted
        if (isset($message->data->ciphertext) && isset($message->data->nonce))
        {
            $encryptionKey = base64_decode($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENCRYPTION_KEY_BASE64_ENCODED']);
            $nonce = base64_decode($message->data->nonce);
            $encryptedMessage = base64_decode($message->data->ciphertext);

            $decryptedMessage = sodium_crypto_secretbox_open($encryptedMessage, $nonce, $encryptionKey);

            $message->data = json_decode($decryptedMessage);

            d('Decrypted payload:');
            print_r($message->data);
        }

        switch ($message->event)
        {
            case 'pusher:connection_established':
                return $this->handleCommandConnectionEstablished($message->data);
            case 'pusher_internal:subscription_succeeded':
                $this->isConnectedToServer = true;
                return $this->doNotRespond();
            default:
                if (isset($this->commandHandlers[$message->event])) {
                    return $this->agent->{ $this->commandHandlers[$message->event] }($message->data);
                } else {
                    d('Unknown command received.');
                }
        }

        return $this->doNotRespond();
    }

    private function handleCommandConnectionEstablished(stdClass $request) : array
    {
        // TODO: Add some verbose logging here
        $apiEndpoint = $_ENV['API_ENDPOINT'] . 'ide/authenticate-connection/';
        $data = [
            'api_key_id' => $_ENV['API_KEY_ID'],
            'channel_name' => $this->channelName,
            'socket_id' => $request->socket_id,
        ];

        // use key 'http' even if you send the request to https://...
        $options = array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
            ),
        );

        if (isset($_ENV['ENVIRONMENT']) && $_ENV['ENVIRONMENT'] == 'dev') {
            $options['ssl'] = array(
                'verify_peer' => false,
                'verify_peer_name' => false,
            );
        }

        $context  = stream_context_create($options);
        $result = file_get_contents($apiEndpoint, false, $context);

        if ($result === false) {
            d('Error retrieving authentication signature.');
            sleep(30);
            die();
        }

        $response = json_decode($result);

        if ($response === false && isset($response->auth) !== true) {
            d('Invalid API response when retrieving authentication signature.');
            sleep(30);
            die();
        }

        $authentication_signature = $response->auth;

        return [
            'event' => 'pusher:subscribe',
            'data' => [
                'auth' => $authentication_signature,
                'channel' => $this->channelName,
            ]
        ];
    }

    public function prepareResponse($event, array $response) : array
    {
        if ($this->useE2Eencryption === true)
        {
            // TODO: Add logger
            d('Message to be sent (before encryption)');
            print_r($response);

            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox(json_encode($response), $nonce, base64_decode($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENCRYPTION_KEY_BASE64_ENCODED']));

            $response = [
                'nonce' => base64_encode($nonce),
                'ciphertext' => base64_encode($ciphertext),
            ];
        }

        return [
            'event' => $event,
            'channel' => $this->channelName,
            'data' => $response,
        ];
    }

    public function sendMessage($event, $data)
    {
        if ($this->useE2Eencryption === true)
        {
            d('Message to be sent (before encryption)');
            print_r($data);

            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($data, $nonce, base64_decode($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENCRYPTION_KEY_BASE64_ENCODED']));

            $data = [
                'nonce' => base64_encode($nonce),
                'ciphertext' => base64_encode($ciphertext),
            ];
        }

        $this->connection->send(json_encode(
            [
                'event' => $event,
                'channel' => $this->channelName,
                'data' => $data,
            ]
        ));
    }

    public function doNotRespond() : array
    {
        return [];
    }

    public function performMaintenance() : void
    {
        if ($this->isConnectedToServer)
        {
            $this->agent->performMaintenance();
        }
    }

    public function registerCallback($command, $handler) : void
    {
        $this->commandHandlers[$command] = $handler;
    }
}

function d(mixed $variable = '')
{
    echo $variable . "\n";
}
