<?php

namespace App\Services;

require_once dirname(__DIR__) . '/factorioRcon.php';

class RconService
{
    private ?\FactorioRCON $connection = null;
    private string $host;
    private int $port;
    private string $password;
    private int $timeout;
    private string $serverId;

    public function __construct(string $serverId = 'default', array $config = null)
    {
        $this->serverId = $serverId;
        
        if ($config) {
            $this->host = $config['rcon_host'] ?? '127.0.0.1';
            $this->port = $config['rcon_port'] ?? 27015;
            $this->password = $config['rcon_password'] ?? '';
            $this->timeout = $config['rcon_timeout'] ?? 5;
        } else {
            $this->host = '127.0.0.1';
            $this->port = 27015;
            $this->password = '';
            $this->timeout = 5;
        }
    }

    public function connect(): bool
    {
        try {
            $this->connection = new \FactorioRCON($this->host, $this->port, $this->password, $this->timeout);
            $this->connection->connect();
            return true;
        } catch (\Exception $e) {
            $this->connection = null;
            return false;
        }
    }

    public function disconnect(): void
    {
        if ($this->connection) {
            $this->connection->disconnect();
            $this->connection = null;
        }
    }

    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    public function executeCommand(string $command): ?string
    {
        if (!$this->connection) {
            if (!$this->connect()) {
                return null;
            }
        }

        try {
            return $this->connection->sendCommand($command);
        } catch (\Exception $e) {
            $this->disconnect();
            return null;
        }
    }

    public function sendChatMessage(string $message): bool
    {
        $command = sprintf('[color=yellow][Server]: %s[/color]', $message);
        $result = $this->executeCommand($command);
        return $result !== null;
    }

    public function getOnlinePlayers(): array
    {
        $result = $this->executeCommand('/players online');
        
        if ($result === null) {
            return [];
        }

        $players = [];
        if (preg_match_all('/\((\d+)\)\s+([^\s]+)/', $result, $matches)) {
            foreach ($matches[2] as $name) {
                $players[] = trim($name);
            }
        }
        
        return $players;
    }

    public function getOnlinePlayerCount(): int
    {
        return count($this->getOnlinePlayers());
    }

    public function kickPlayer(string $player, string $reason = ''): bool
    {
        $command = $reason 
            ? sprintf('/kick %s %s', $player, $reason)
            : sprintf('/kick %s', $player);
        
        return $this->executeCommand($command) !== null;
    }

    public function banPlayer(string $player, string $reason = ''): bool
    {
        $command = $reason 
            ? sprintf('/ban %s %s', $player, $reason)
            : sprintf('/ban %s', $player);
        
        return $this->executeCommand($command) !== null;
    }

    public function giveItem(string $player, string $item, int $count = 1): bool
    {
        $command = sprintf('/c game.players["%s"].insert{name="%s", count=%d}', $player, $item, $count);
        return $this->executeCommand($command) !== null;
    }

    public function getPlayerPosition(string $player): ?array
    {
        $command = sprintf('/c game.players["%s"].print(game.players["%s"].position.x .. "," .. game.players["%s"].position.y)', $player, $player, $player);
        $result = $this->executeCommand($command);
        
        if ($result && preg_match('/([\d.]+),([\d.]+)/', $result, $matches)) {
            return [
                'x' => (float)$matches[1],
                'y' => (float)$matches[2]
            ];
        }
        
        return null;
    }

    public function getServerInfo(): ?array
    {
        $result = $this->executeCommand('/c game.print("info")');
        return $result ? ['response' => $result] : null;
    }

    public function getServerId(): string
    {
        return $this->serverId;
    }
}
