<?php
/**
 * RCON 连接池客户端
 * 
 * 用于与 RCON 连接池守护进程通信
 */

class RconPoolClient
{
    private $socketPath;
    private $timeout;
    
    public function __construct($socketPath = null, $timeout = 5)
    {
        $this->socketPath = $socketPath ?: dirname(__DIR__) . '/run/rconPool.sock';
        $this->timeout = $timeout;
    }
    
    public function execute($command)
    {
        return $this->sendRequest([
            'action' => 'execute',
            'command' => $command
        ]);
    }
    
    public function ping()
    {
        return $this->sendRequest(['action' => 'ping']);
    }
    
    public function status()
    {
        return $this->sendRequest(['action' => 'status']);
    }
    
    public function reload()
    {
        return $this->sendRequest(['action' => 'reload']);
    }
    
    private function sendRequest($data)
    {
        if (!file_exists($this->socketPath)) {
            return ['success' => false, 'error' => 'RCON 连接池未运行'];
        }
        
        $socket = @socket_create(AF_UNIX, SOCK_STREAM, 0);
        if ($socket === false) {
            return ['success' => false, 'error' => '创建 Socket 失败'];
        }
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
        
        if (!@socket_connect($socket, $this->socketPath)) {
            socket_close($socket);
            return ['success' => false, 'error' => '连接 RCON 连接池失败'];
        }
        
        $request = json_encode($data) . "\n";
        $written = socket_write($socket, $request, strlen($request));
        
        if ($written === false) {
            socket_close($socket);
            return ['success' => false, 'error' => '发送请求失败'];
        }
        
        $response = '';
        while (($chunk = socket_read($socket, 4096)) !== false && $chunk !== '') {
            $response .= $chunk;
            if (strpos($response, "\n") !== false) {
                break;
            }
        }
        
        socket_close($socket);
        
        $result = json_decode(trim($response), true);
        
        if ($result === null) {
            return ['success' => false, 'error' => '解析响应失败'];
        }
        
        return $result;
    }
    
    public static function quickExecute($command)
    {
        $client = new self();
        return $client->execute($command);
    }
    
    public static function isAvailable()
    {
        $client = new self();
        $result = $client->ping();
        return $result['success'] ?? false;
    }
}
