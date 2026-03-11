<?php

namespace App\Services;

class RconService
{
    private $socket;
    private $host;
    private $port;
    private $password;
    private $timeout;
    private $connected = false;
    private $requestId = 0;

    const SERVERDATA_EXECCOMMAND = 2;
    const SERVERDATA_AUTH = 3;
    const SERVERDATA_AUTH_RESPONSE = 2;
    const SERVERDATA_RESPONSE_VALUE = 0;

    public function __construct(string $host = '127.0.0.1', int $port = 27015, string $password = '', int $timeout = 5)
    {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->timeout = $timeout;
    }

    public function connect(): bool
    {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        
        if (!$this->socket) {
            throw new \Exception("无法连接到 RCON 服务器: {$this->host}:{$this->port} - {$errstr}");
        }

        stream_set_timeout($this->socket, $this->timeout);
        stream_set_blocking($this->socket, true);

        if (!empty($this->password)) {
            $this->authenticate();
        }

        $this->connected = true;
        return true;
    }

    private function authenticate(): bool
    {
        $this->sendPacket(self::SERVERDATA_AUTH, $this->password);
        
        $response = $this->readPacket();
        
        if ($response['type'] === self::SERVERDATA_AUTH_RESPONSE && $response['id'] === -1) {
            throw new \Exception("RCON 认证失败: 密码错误");
        }

        return true;
    }

    public function sendCommand(string $command): string
    {
        if (!$this->connected) {
            $this->connect();
        }

        $this->sendPacket(self::SERVERDATA_EXECCOMMAND, $command);
        
        return $this->readResponse();
    }

    private function sendPacket(int $type, string $body): int
    {
        $this->requestId++;
        $id = $this->requestId;
        
        $packet = pack('VV', $id, $type);
        $packet .= $body . "\x00";
        $packet .= "\x00";
        
        $length = strlen($packet);
        $packet = pack('V', $length) . $packet;
        
        $written = fwrite($this->socket, $packet);
        
        if ($written === false) {
            throw new \Exception("发送 RCON 数据包失败");
        }

        return $id;
    }

    private function readPacket(): array
    {
        $header = fread($this->socket, 4);
        
        if (strlen($header) < 4) {
            throw new \Exception("读取 RCON 数据包头失败");
        }

        $size = unpack('V', $header)[1];
        
        if ($size < 4 || $size > 4110) {
            throw new \Exception("无效的 RCON 数据包大小: {$size}");
        }

        $data = fread($this->socket, $size);
        
        if (strlen($data) < $size) {
            throw new \Exception("读取 RCON 数据包体失败");
        }

        $id = unpack('V', substr($data, 0, 4))[1];
        $type = unpack('V', substr($data, 4, 4))[1];
        $body = substr($data, 8, -2);

        return [
            'id' => $id,
            'type' => $type,
            'body' => $body
        ];
    }

    private function readResponse(): string
    {
        $response = '';
        $attempts = 0;
        $maxAttempts = 10;

        do {
            $packet = $this->readPacket();
            
            if ($packet['type'] === self::SERVERDATA_RESPONSE_VALUE) {
                $response .= $packet['body'];
            }

            $attempts++;
            
            if ($attempts >= $maxAttempts) {
                break;
            }

            $meta = stream_get_meta_data($this->socket);
            if ($meta['eof'] || $meta['timed_out']) {
                break;
            }

        } while (true);

        return $response;
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->socket && !feof($this->socket);
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public static function quickCommand(string $command, string $host = '127.0.0.1', int $port = 27015, string $password = ''): ?string
    {
        $rcon = new self($host, $port, $password);
        
        try {
            $rcon->connect();
            $result = $rcon->sendCommand($command);
            $rcon->disconnect();
            return $result;
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function testConnection(string $host = '127.0.0.1', int $port = 27015, string $password = ''): bool
    {
        try {
            $rcon = new self($host, $port, $password, 3);
            $rcon->connect();
            $rcon->disconnect();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
