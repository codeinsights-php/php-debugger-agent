<?php

namespace CodeInsights\Debugger\Agent;

use Exception;
use stdClass;

class WebSocketsClient
{
    private Agent $agent;
    private bool $isConnectedToServer = false;
    public $connection;

    public function __construct()
    {
        $this->agent = new Agent($this);
    }

    public function handleIncomingMessage(array $message): void
    {
        if (isset($message['event']) === false) {
            d('Invalid message received.');
            return;
        }

        // Some messages (e.g. during authentication) are not encrypted
        if (isset($message['encrypted']) && $message['encrypted'] === true) {
            $encryptionKey = base64_decode($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENCRYPTION_KEY_BASE64_ENCODED']);
            $nonce = base64_decode($message['nonce']);
            $encryptedMessage = base64_decode($message['ciphertext']);

            // Verify that decoding was successful, notify about potentially incorrect encryption key
            $decryptedMessage = sodium_crypto_secretbox_open($encryptedMessage, $nonce, $encryptionKey);

            if ($decryptedMessage === false)
            {
                d('Could not decrypt message. Please verify if the same encryption key is used by client (IDE) and Agent.');

                // TODO: Use simultaneously also subscription to an unencrypted channel and Send this message there?
                $this->sendMessage(['event' => 'client-agent-encountered-error', ['errorCode' => 'MESSAGE_DECRYPTION_FAILED']], false);
                return;
            }

            $message['data'] = json_decode($decryptedMessage);

            d('Decrypted payload:');
            print_r($message['data']);
        }

        switch ($message['event']) {
            case 'server:connection-established':
                $this->handleCommandConnectionEstablished($message['header']);
                return;

            case 'server:authentication-result':

                if ($message['header']['result'] === true)
                {
                    d('Connected successfully.');
                    $this->isConnectedToServer = true;
                    $this->sendMessage(['event' => 'logpoints-list']);
                }

                // TODO: Handle rejections gracefully (do not reconnect)

                return;

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
                    $this->sendMessage(['event' => 'client-agent-encountered-error', 'data' => ['errorCode' => 'DATA_CONTENT_LIMIT_EXCEEDED']]);
                }

                // TODO: Perform additional logging / reporting of received Pusher error messages

                return;

            default:
                $action = str_replace('-', ' ', $message['event']);
                $action = ucwords($action);
                $action = str_replace(' ', '', $action);
                $action = 'handle' . $action;

                if (method_exists($this->agent, $action))
                {
                    $this->agent->$action($message);
                } else {
                    d('Unknown command received: ' . $message['event'] . ' (' . $action . ')');
                }
        }
    }

    private function handleCommandConnectionEstablished(array $request): array
    {
        $authentication_signature = hash_hmac('sha256', $request['socket_id'] . ':' . $_ENV['API_KEY_ID'], $_ENV['API_KEY_ACCESS_TOKEN']);

        $this->sendMessage([
            'event' => 'authenticate-as-server',
            'header' => [
                'api_key_id' => $_ENV['API_KEY_ID'],
                'auth' => $authentication_signature,
            ],
            'data' => [
                'host_info' => [
                    'internal_ip' => $this->_getLocalIpAddresses(),
                    'hostname' => gethostname(),
                    'php_version' => phpversion(),
                    // TODO: Retrieve extension version
                    'extension_version' => '0.1',
                ],
            ],
        ]);
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

    public function sendMessage($message, $encrypt = false, $compress = false)
    {
        d('Message to be sent:');
        print_r($message);
        echo "\n";

        d('Performing compression: ' . (int) $compress);
        d('Performing encryption: ' . (int) $encrypt);

        if ($compress === true) {
            $messageToCompress = is_array($message['data']) ? json_encode($message['data']) : $message['data'];
            $message['data'] = gzdeflate($messageToCompress, 9, ZLIB_ENCODING_DEFLATE);

            if ($encrypt !== true) {
                $message['data'] = base64_encode($message['data']);
            }

            $message['compressed'] = true;
        }

        if ($encrypt === true) {
            $messageToEncrypt = is_array($message['data']) ? json_encode($message['data']) : $message['data'];
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($messageToEncrypt, $nonce, base64_decode($_ENV['CODEINSIGHTS_MESSAGING_SERVER_ENCRYPTION_KEY_BASE64_ENCODED']));

            unset($message['data']);

            $message = $message + [
                'nonce' => base64_encode($nonce),
                'ciphertext' => base64_encode($ciphertext),
                'encrypted' => true,
            ];
        }

        $dataToSend = json_encode($message);

        d('Sending message:' . "\n" . $dataToSend);
        d('Message size: ' . strlen($dataToSend));

        $this->connection->send($dataToSend);
    }

    public function performMaintenance(): void
    {
        if ($this->isConnectedToServer) {
            $this->agent->performMaintenance();
        }
    }
}
