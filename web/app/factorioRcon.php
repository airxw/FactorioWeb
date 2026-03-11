<?php

class FactorioRCON
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

    public function __construct($host = '127.0.0.1', $port = 27015, $password = '', $timeout = 5)
    {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->timeout = $timeout;
    }

    public function connect()
    {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        
        if (!$this->socket) {
            throw new Exception("无法连接到 RCON 服务器: {$this->host}:{$this->port} - {$errstr}");
        }

        stream_set_timeout($this->socket, $this->timeout);
        stream_set_blocking($this->socket, true);

        if (!empty($this->password)) {
            $this->authenticate();
        }

        $this->connected = true;
        return true;
    }

    private function authenticate()
    {
        $this->sendPacket(self::SERVERDATA_AUTH, $this->password);
        
        $response = $this->readPacket();
        
        if ($response['type'] === self::SERVERDATA_AUTH_RESPONSE && $response['id'] === -1) {
            throw new Exception("RCON 认证失败: 密码错误");
        }

        return true;
    }

    public function sendCommand($command)
    {
        if (!$this->connected) {
            $this->connect();
        }

        $this->sendPacket(self::SERVERDATA_EXECCOMMAND, $command);
        
        $response = $this->readResponse();
        
        return $response;
    }

    private function sendPacket($type, $body)
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
            throw new Exception("发送 RCON 数据包失败");
        }

        return $id;
    }

    private function readPacket()
    {
        $header = fread($this->socket, 4);
        
        if (strlen($header) < 4) {
            throw new Exception("读取 RCON 数据包头失败");
        }

        $size = unpack('V', $header)[1];
        
        if ($size < 4 || $size > 4110) {
            throw new Exception("无效的 RCON 数据包大小: {$size}");
        }

        $data = fread($this->socket, $size);
        
        if (strlen($data) < $size) {
            throw new Exception("读取 RCON 数据包体失败");
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

    private function readResponse()
    {
        $response = '';
        $startTime = time();
        $timeout = $this->timeout;
        
        while (true) {
            $meta = stream_get_meta_data($this->socket);
            if ($meta['eof'] || $meta['timed_out']) {
                break;
            }
            
            if ((time() - $startTime) >= $timeout) {
                break;
            }
            
            $packet = $this->readPacketSafe();
            
            if ($packet === null) {
                break;
            }
            
            if ($packet['type'] === self::SERVERDATA_RESPONSE_VALUE) {
                $response .= $packet['body'];
                
                if (strlen($packet['body']) < 4000) {
                    break;
                }
            }
        }
        
        return $response;
    }
    
    private function readPacketSafe()
    {
        $header = @fread($this->socket, 4);
        
        if (strlen($header) < 4) {
            return null;
        }

        $size = unpack('V', $header)[1];
        
        if ($size < 4 || $size > 4110) {
            return null;
        }

        $data = @fread($this->socket, $size);
        
        if (strlen($data) < $size) {
            return null;
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

    public function isConnected()
    {
        if (!$this->connected || !$this->socket) {
            return false;
        }
        
        $meta = @stream_get_meta_data($this->socket);
        if ($meta['eof'] || $meta['timed_out']) {
            $this->connected = false;
            return false;
        }
        
        return true;
    }

    public function disconnect()
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

    public static function quickCommand($command, $host = '127.0.0.1', $port = 27015, $password = '')
    {
        $rcon = new self($host, $port, $password);
        
        try {
            $rcon->connect();
            $result = $rcon->sendCommand($command);
            $rcon->disconnect();
            return $result;
        } catch (Exception $e) {
            return null;
        }
    }

    public static function testConnection($host = '127.0.0.1', $port = 27015, $password = '')
    {
        try {
            $rcon = new self($host, $port, $password, 3);
            $rcon->connect();
            $rcon->disconnect();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
