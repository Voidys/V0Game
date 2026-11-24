<?php

declare(strict_types=1);

namespace App\Twig;

use App\Game\Alliance\AllianceRepository;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Game\Engagement\AllianceGoals\AllianceGoals;
use App\Game\Engagement\Contracts\ContractBoard;
use App\Game\Engagement\Contracts\Contracts;
use App\Game\Engagement\Returning\ReturningPlayers;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Data for the engagement includes of the overview (weekly contracts line, returning-player welcome) and the alliance page
 * (alliance goal). Every function returns null while its feature is off, so the includes render nothing.
 */
final class PlayerEngagementExtension extends AbstractExtension
{
    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

    /** @return list<TwigFunction> the engagement include data functions */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('eng_contracts_line', $this->contractsLine(...)),
            new TwigFunction('eng_returning_summary', $this->returningSummary(...)),
            new TwigFunction('eng_alliance_goal', $this->allianceGoal(...)),
        ];
    }

    /**
     * Contracts done / total of this week for the overview line.
     *
     * @return array{done: int, total: int}|null
     */
    public function contractsLine(): ?array
    {
        $user = $this->state->USER ?? [];
        $universe = (int) ($user['universe'] ?? 0);
        $feature = new Contracts($this->db, $this->configs, $this->state);
        if (empty($user['id']) || !$feature->enabled($universe)) {
            return null;
        }
        $now = $this->state->runtime->timestamp;
        if (!$feature->ensureWeek($universe, (int) $user['id'], $now)) {
            return null;
        }
        $board = (new ContractBoard($this->db, $this->configs, $this->state))->summary((int) $user['id'], $universe, $now);

        return ['done' => $board['done'], 'total' => count($board['contracts'])];
    }

    /**
     * The unseen welcome summary of a returning player.
     *
     * @return array<string, mixed>|null
     */
    public function returningSummary(): ?array
    {
        $user = $this->state->USER ?? [];
        if (empty($user['id'])) {
            return null;
        }

        return (new ReturningPlayers($this->db, $this->configs, $this->state))->summary((int) $user['universe'], (int) $user['id'], $this->state->LNG);
    }

    /**
     * The alliance goal panel of the player's own alliance (null without alliance or when the feature is off).
     *
     * @return array<string, mixed>|null
     */
    public function allianceGoal(): ?array
    {
        $user = $this->state->USER ?? [];
        $universe = (int) ($user['universe'] ?? 0);
        $goals = new AllianceGoals($this->db, $this->configs, $this->state);
        if (empty($user['id']) || (int) ($user['ally_id'] ?? 0) <= 0 || !$goals->enabled($universe)) {
            return null;
        }
        $repository = new AllianceRepository($this->db, $this->configs, $this->state);
        $alliance = $repository->find((int) $user['ally_id']);
        if ($alliance === null) {
            return null;
        }
        $mayManage = !empty($repository->rightsOf($user, $alliance)['ADMIN']);

        return $goals->panel($universe, $alliance, $mayManage, $this->state->runtime->timestamp);
    }
}
