<?php

namespace App\Services;

class VoteService
{
    private StateService $stateService;
    private string $voteStateFile = 'voteState';
    private string $voteCooldownFile = 'voteCooldown';

    public function __construct(StateService $stateService = null)
    {
        $this->stateService = $stateService ?? new StateService();
    }

    public function startVoteKick(string $player, string $target, int $requiredVotes): array
    {
        $state = $this->loadVoteState();
        
        $state['active'] = true;
        $state['initiator'] = $player;
        $state['target'] = $target;
        $state['required'] = $requiredVotes;
        $state['votes'] = [$player => true];
        $state['startTime'] = time();
        
        $this->saveVoteState($state);
        
        return $state;
    }

    public function processVote(string $player, bool $vote): array
    {
        $state = $this->loadVoteState();
        
        if (!$state['active']) {
            return ['success' => false, 'message' => '没有进行中的投票'];
        }
        
        if (isset($state['votes'][$player])) {
            return ['success' => false, 'message' => '你已经投过票了'];
        }
        
        $state['votes'][$player] = $vote;
        $this->saveVoteState($state);
        
        $yesVotes = count(array_filter($state['votes']));
        $totalVotes = count($state['votes']);
        
        return [
            'success' => true,
            'yesVotes' => $yesVotes,
            'totalVotes' => $totalVotes,
            'required' => $state['required'],
            'passed' => $yesVotes >= $state['required']
        ];
    }

    public function checkVoteStatus(string $player = null): array
    {
        $state = $this->loadVoteState();
        
        if (!$state['active']) {
            return ['active' => false];
        }
        
        $yesVotes = count(array_filter($state['votes']));
        $totalVotes = count($state['votes']);
        
        return [
            'active' => true,
            'target' => $state['target'],
            'initiator' => $state['initiator'],
            'yesVotes' => $yesVotes,
            'totalVotes' => $totalVotes,
            'required' => $state['required'],
            'startTime' => $state['startTime'],
            'hasVoted' => $player ? isset($state['votes'][$player]) : false,
            'passed' => $yesVotes >= $state['required']
        ];
    }

    public function endVote(): void
    {
        $this->saveVoteState([
            'active' => false,
            'initiator' => null,
            'target' => null,
            'required' => 0,
            'votes' => [],
            'startTime' => 0
        ]);
    }

    public function saveVoteCooldown(string $player, int $duration = 300): void
    {
        $cooldowns = $this->stateService->loadState($this->voteCooldownFile);
        $cooldowns[$player] = time() + $duration;
        $this->stateService->saveState($this->voteCooldownFile, $cooldowns);
    }

    public function checkVoteCooldown(string $player): bool
    {
        $cooldowns = $this->stateService->loadState($this->voteCooldownFile);
        
        if (!isset($cooldowns[$player])) {
            return true;
        }
        
        if (time() > $cooldowns[$player]) {
            unset($cooldowns[$player]);
            $this->stateService->saveState($this->voteCooldownFile, $cooldowns);
            return true;
        }
        
        return false;
    }

    public function getVoteCooldownRemaining(string $player): int
    {
        $cooldowns = $this->stateService->loadState($this->voteCooldownFile);
        
        if (!isset($cooldowns[$player])) {
            return 0;
        }
        
        return max(0, $cooldowns[$player] - time());
    }

    public function saveVoteState(array $state): void
    {
        $this->stateService->saveState($this->voteStateFile, $state);
    }

    public function loadVoteState(): array
    {
        return $this->stateService->loadState($this->voteStateFile);
    }
}
