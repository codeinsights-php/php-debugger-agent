<?php

namespace CodeInsights\Debugger\Agent;

use Exception;
use stdClass;

class WebSocketsClient
{
    private Agent $agent;
    private bool $isConnectedToServer = false;
    private bool $useE2Eencryption;
    private string $channelName;
    public $connection;

    public function __construct()
    {
        $this->agent = new Agent($this);
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

        $this->channelName = 'private-' . ($this->useE2Eencryption === true ? 'encrypted-' : '') . $_ENV['API_KEY_ID'] . '-' . $_ENV['API_KEY_ACCESS_TOKEN'];
    }

    public function handleIncomingMessage(array $message): array
    {
        if (isset($message['event']) === false) {
            d('Invalid message received.');
            return $this->doNotRespond();
        }

        // Some messages (e.g. during authentication) are not encrypted
        if (isset($message['data']['ciphertext']) && isset($message['data']['nonce'])) {
            $encryptionKey = base64_decode($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENCRYPTION_KEY_BASE64_ENCODED']);
            $nonce = base64_decode($message['data']['nonce']);
            $encryptedMessage = base64_decode($message['data']['ciphertext']);

            // TODO: Verify that decoding was successful, notify about potentially incorrect encryption key
            $decryptedMessage = sodium_crypto_secretbox_open($encryptedMessage, $nonce, $encryptionKey);

            if ($decryptedMessage === false)
            {
                d('Could not decrypt message. Please verify if the same encryption key is used by client (IDE) and Agent.');

                // TODO: Use simultaneously also subscription to an unencrypted channel and Send this message there?
                return $this->prepareResponse('client-agent-encountered-error', ['errorCode' => 'MESSAGE_DECRYPTION_FAILED'], $forceSendingWithoutEncryption = true);
            }

            $message['data'] = json_decode($decryptedMessage);

            d('Decrypted payload:');
            print_r($message['data']);
        }

        switch ($message['event']) {
            case 'server:connection-established':
                return $this->handleCommandConnectionEstablished($message['data']);

            case 'server:authentication-result':

                if ($message['data']['result'] === true)
                {
                    d('Connected successfully.');
                    $this->isConnectedToServer = true;
                    return ['event' => 'logpoints-list', 'data' => []];
                }

                // TODO: Handle rejections gracefully (do not reconnect)

                return $this->doNotRespond();

            case 'pusher:error':

                // stdClass Object
                // (
                //     [event] => pusher:error
                //     [data] => stdClass Object
                //         (
                //             [code] =>
                //             [message] => The data content of this event exceeds the allowed maximum (10240 bytes). See https://pusher.com/docs/channels/server_api/http-api#publishing-events for more info
                //         )
                // )

                if (isset($message['data']['message']) && strpos($message['data']['message'], 'The data content of this event exceeds the allowed maximum (10240 bytes)') !== false) {
                    return $this->prepareResponse('client-agent-encountered-error', ['errorCode' => 'DATA_CONTENT_LIMIT_EXCEEDED']);
                }

                // TODO: Perform additional logging / reporting of received Pusher error messages

                return $this->doNotRespond();

            default:
                $action = str_replace('-', ' ', $message['event']);
                $action = ucwords($action);
                $action = str_replace(' ', '', $action);
                $action = 'handle' . $action;

                if (method_exists($this->agent, $action))
                {
                    return $this->agent->$action((array) $message['data']);
                } else {
                    d('Unknown command received: ' . $message['event'] . ' (' . $action . ')');
                }
        }

        return $this->doNotRespond();
    }

    private function handleCommandConnectionEstablished(array $request): array
    {
        $authentication_signature = hash_hmac('sha256', $request['socket_id'] . ':' . $_ENV['API_KEY_ID'], $_ENV['API_KEY_ACCESS_TOKEN']);

        return [
            'event' => 'authenticate-as-server',
            'data' => [
            'api_key_id' => $_ENV['API_KEY_ID'],
            'auth' => $authentication_signature,
            'host_info' => [
                'internal_ip' => $this->_getLocalIpAddresses(),
                'hostname' => gethostname(),
                'php_version' => phpversion(),
                // TODO: Retrieve extension version
                'extension_version' => '0.1',
            ],
            ]
        ];
    }

    private function _getLocalIpAddresses()
    {
        $ip_addresses = array();

        $network_interfaces = net_get_interfaces();

        foreach ($network_interfaces as $network_interface_id => $network_interface)
        {
            if ($network_interface_id == 'lo')
            {
                continue;
            }

            if (isset($network_interface['unicast']))
            {
                foreach ($network_interface['unicast'] as $info)
                {
                    if (isset($info['address']))
                    {
                        $ip_addresses[] = $info['address'];
                    }
                }
            }
        }

        return implode(', ', $ip_addresses);
    }

    public function prepareResponse($event, array $response, bool $forceSendingWithoutEncryption = false): array
    {
        if ($this->useE2Eencryption === true && $forceSendingWithoutEncryption !== true) {
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
            'data' => $response,
        ];
    }

    // TODO: DRY up to include prepareResponse() functionality
    public function sendMessage($event, $data, $compress = false)
    {
        d('Message to be sent (before optional encryption and compression)');
        print_r($data);
        echo "\n";

        if ($compress === true) {
            $data = gzdeflate($data, 9, ZLIB_ENCODING_DEFLATE);

            if ($this->useE2Eencryption !== true) {
                $data = base64_encode($data);
            }
        }

        if ($this->useE2Eencryption === true) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($data, $nonce, base64_decode($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENCRYPTION_KEY_BASE64_ENCODED']));

            $data = [
                'nonce' => base64_encode($nonce),
                'ciphertext' => base64_encode($ciphertext),
            ];

            if ($compress === true) {
                $data['compressed'] = true;
            }
        } elseif ($compress === true) {
            $data = [
                'message' => $data,
                'compressed' => true,
            ];
        }

        $dataToSend = json_encode([
            'event' => $event,
            'channel' => $this->channelName,
            'data' => $data,
        ]);

        d('Sent message size: ' . strlen($dataToSend));

        $this->connection->send($dataToSend);
    }

    public function doNotRespond(): array
    {
        return [];
    }

    public function performMaintenance(): void
    {
        if ($this->isConnectedToServer) {
            $this->agent->performMaintenance();
        }
    }
}
