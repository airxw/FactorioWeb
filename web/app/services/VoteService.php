<?php

namespace App\Services;

use App\Core\Database;

class VoteService
{
    private Database $db;

    public function __construct(Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function startVoteKick(string $player, string $target, int $requiredVotes): array
    {
        $this->endVote();

        $this->db->execute(
            'INSERT INTO votes (is_active, initiator, target, required_votes, start_time, created_at) VALUES (1, :initiator, :target, :required, :start, :created)',
            [
                ':initiator' => $player,
                ':target' => $target,
                ':required' => $requiredVotes,
                ':start' => time(),
                ':created' => time()
            ]
        );

        $voteId = $this->db->lastInsertId();

        $this->db->execute(
            'INSERT INTO vote_records (vote_id, player_name, vote_bool, voted_at) VALUES (:voteId, :player, 1, :votedAt)',
            [':voteId' => $voteId, ':player' => $player, ':votedAt' => time()]
        );

        return [
            'active' => true,
            'initiator' => $player,
            'target' => $target,
            'required' => $requiredVotes,
            'votes' => [$player => true],
            'startTime' => time()
        ];
    }

    public function processVote(string $player, bool $vote): array
    {
        $activeVote = $this->loadActiveVote();

        if (!$activeVote['active']) {
            return ['success' => false, 'message' => '没有进行中的投票'];
        }

        $existing = $this->db->query(
            'SELECT id FROM vote_records WHERE vote_id = :voteId AND player_name = :player',
            [':voteId' => $activeVote['id'], ':player' => $player]
        );

        if (!empty($existing)) {
            return ['success' => false, 'message' => '你已经投过票了'];
        }

        $this->db->execute(
            'INSERT INTO vote_records (vote_id, player_name, vote_bool, voted_at) VALUES (:voteId, :player, :voteBool, :votedAt)',
            [':voteId' => $activeVote['id'], ':player' => $player, ':voteBool' => $vote ? 1 : 0, ':votedAt' => time()]
        );

        $yesVotes = $this->db->query(
            'SELECT COUNT(*) as cnt FROM vote_records WHERE vote_id = :voteId AND vote_bool = 1',
            [':voteId' => $activeVote['id']]
        )[0]['cnt'];

        $totalVotes = $this->db->query(
            'SELECT COUNT(*) as cnt FROM vote_records WHERE vote_id = :voteId',
            [':voteId' => $activeVote['id']]
        )[0]['cnt'];

        return [
            'success' => true,
            'yesVotes' => (int)$yesVotes,
            'totalVotes' => (int)$totalVotes,
            'required' => $activeVote['required_votes'],
            'passed' => (int)$yesVotes >= $activeVote['required_votes']
        ];
    }

    public function checkVoteStatus(string $player = null): array
    {
        $activeVote = $this->loadActiveVote();

        if (!$activeVote['active']) {
            return ['active' => false];
        }

        $yesVotes = $this->db->query(
            'SELECT COUNT(*) as cnt FROM vote_records WHERE vote_id = :voteId AND vote_bool = 1',
            [':voteId' => $activeVote['id']]
        )[0]['cnt'];

        $totalVotes = $this->db->query(
            'SELECT COUNT(*) as cnt FROM vote_records WHERE vote_id = :voteId',
            [':voteId' => $activeVote['id']]
        )[0]['cnt'];

        $hasVoted = false;
        if ($player) {
            $voted = $this->db->query(
                'SELECT id FROM vote_records WHERE vote_id = :voteId AND player_name = :player',
                [':voteId' => $activeVote['id'], ':player' => $player]
            );
            $hasVoted = !empty($voted);
        }

        return [
            'active' => true,
            'target' => $activeVote['target'],
            'initiator' => $activeVote['initiator'],
            'yesVotes' => (int)$yesVotes,
            'totalVotes' => (int)$totalVotes,
            'required' => $activeVote['required_votes'],
            'startTime' => $activeVote['start_time'],
            'hasVoted' => $hasVoted,
            'passed' => (int)$yesVotes >= $activeVote['required_votes']
        ];
    }

    public function endVote(): void
    {
        $this->db->execute('UPDATE votes SET is_active = 0');
    }

    public function saveVoteCooldown(string $player, int $duration = 300): void
    {
        $this->db->execute(
            'INSERT OR REPLACE INTO vote_cooldowns (player_name, cooldown_until) VALUES (:player, :until)',
            [':player' => $player, ':until' => time() + $duration]
        );
    }

    public function checkVoteCooldown(string $player): bool
    {
        $result = $this->db->query(
            'SELECT cooldown_until FROM vote_cooldowns WHERE player_name = :player',
            [':player' => $player]
        );

        if (empty($result)) {
            return true;
        }

        if (time() > (int)$result[0]['cooldown_until']) {
            $this->db->execute('DELETE FROM vote_cooldowns WHERE player_name = :player', [':player' => $player]);
            return true;
        }

        return false;
    }

    public function getVoteCooldownRemaining(string $player): int
    {
        $result = $this->db->query(
            'SELECT cooldown_until FROM vote_cooldowns WHERE player_name = :player',
            [':player' => $player]
        );

        if (empty($result)) {
            return 0;
        }

        return max(0, (int)$result[0]['cooldown_until'] - time());
    }

    private function loadActiveVote(): ?array
    {
        $result = $this->db->query('SELECT * FROM votes WHERE is_active = 1 LIMIT 1');
        return $result[0] ?? ['active' => false];
    }
}
