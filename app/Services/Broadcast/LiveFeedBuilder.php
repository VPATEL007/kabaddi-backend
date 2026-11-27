<?php

namespace App\Services\Broadcast;

use App\Enums\EventType;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Raid;
use RuntimeException;

/**
 * Builds the broadcaster live feed for one match, in the client's JSON format.
 *
 * Scoring rules mirror ScoreboardService::getMatchScoreboard() so the feed totals
 * always equal the scoring app's totals. Client mapping decisions:
 *  - client key names kept; numbers sent as strings; "successfull-raids" spelling corrected
 *  - raid-outcome-id: 1 = successful, 2 = unsuccessful, 3 = empty (skipped raids are left out)
 *  - extra-points = technical points + lineout points; bonus goes under raid-points.raid-bonus
 *  - tackle-points.tackle-bonus = super tackle extra point
 *  - cards and substitutions are included in "match-events"
 */
class LiveFeedBuilder
{
    public const OUTCOME_CODES = [
        'successful' => 1,
        'unsuccessful' => 2,
        'empty' => 3,
    ];

    private const STATUS_MAP = [
        'upcoming' => 'scheduled',
        'first_half' => 'live',
        'second_half' => 'live',
        'ongoing' => 'live',
        'timeout' => 'timeout',
        'completed' => 'completed',
    ];

    /**
     * Returns ['meta' => [...without version/published-at/event...], 'in-match' => [...]].
     */
    public function build(int $matchId): array
    {
        $match = GameMatch::with([
            'teams.team',
            'matchPlayers.user',
            'raids' => fn($q) => $q->orderBy('raid_number'),
            'raids.defenders',
            'raids.tacklers',
            'raids.defenderLineouts',
            'events' => fn($q) => $q->orderBy('id'),
        ])->findOrFail($matchId);

        $teamIds = [(int) $match->team_a_id, (int) $match->team_b_id];
        $teams = [];
        foreach ($teamIds as $teamId) {
            $teams[$teamId] = $this->emptyTeamStats();
        }

        $players = [];
        $playerTeam = [];
        foreach ($match->matchPlayers as $mp) {
            $players[(int) $mp->user_id] = $this->emptyPlayerStats();
            $playerTeam[(int) $mp->user_id] = (int) $mp->team_id;
        }

        $emptyStreak = array_fill_keys($teamIds, 0);
        $playByPlay = [];
        $lastRaid = null;

        foreach ($match->raids as $raid) {
            $lastRaid = $raid;
            $outcome = $raid->outcome;

            if (!isset(self::OUTCOME_CODES[$outcome])) {
                continue; // skipped / technical_point rows are not raids
            }

            $raidTeam = (int) $raid->raid_team_id;
            if (!isset($teams[$raidTeam])) {
                continue;
            }
            $defTeam = $raidTeam === $teamIds[0] ? $teamIds[1] : $teamIds[0];
            $raiderId = (int) $raid->raider_id;
            $before = [$raidTeam => $this->teamTotal($teams[$raidTeam]), $defTeam => $this->teamTotal($teams[$defTeam])];

            // Do-or-die: third raid after two consecutive empty raids by the same team.
            $isDoOrDie = ($emptyStreak[$raidTeam] ?? 0) >= 2;

            $this->applyRaid($raid, $raidTeam, $defTeam, $teams, $players);

            // Raid counters
            $teams[$raidTeam]['raids'][$outcome]++;
            $teams[$raidTeam]['raids']['total']++;
            if (isset($players[$raiderId])) {
                $players[$raiderId]['raids'][$outcome]++;
                $players[$raiderId]['raids']['total']++;
            }

            if ($isDoOrDie) {
                $raidPts = $this->teamTotal($teams[$raidTeam]) - $before[$raidTeam];
                $this->countDoOrDie($teams[$raidTeam]['do-or-die'], $outcome, $raidPts);
                if (isset($players[$raiderId])) {
                    $this->countDoOrDie($players[$raiderId]['do-or-die'], $outcome, $raidPts);
                }
            }

            $emptyStreak[$raidTeam] = ($outcome === 'empty' && !$isDoOrDie) ? $emptyStreak[$raidTeam] + 1 : 0;

            $raider = $match->matchPlayers->firstWhere('user_id', $raiderId);
            $playByPlay[] = [
                'raid-number' => $this->s($raid->raid_number),
                'raid-half' => $this->s($raid->half),
                'raiding-team-id' => $this->s($raidTeam),
                'raiding-player-id' => $this->s($raiderId),
                'player-jersey-no' => $this->s($raider?->user?->jersey_no),
                'raid-outcome-id' => $this->s(self::OUTCOME_CODES[$outcome]),
                'do-or-die' => $isDoOrDie ? 'true' : 'false',
                // team[0] is always the raiding team
                'team' => [
                    ['team-id' => $this->s($raidTeam), 'points' => $this->s($this->teamTotal($teams[$raidTeam]) - $before[$raidTeam])],
                    ['team-id' => $this->s($defTeam), 'points' => $this->s($this->teamTotal($teams[$defTeam]) - $before[$defTeam])],
                ],
            ];
        }

        // Technical points (non-raid events)
        $matchEvents = [];
        foreach ($match->events as $event) {
            $type = $event->type?->value;

            if ($type === EventType::TECHNICAL_POINT->value && isset($teams[(int) $event->team_id])) {
                $teams[(int) $event->team_id]['extra']['technical']++;
            }

            if ($mapped = $this->mapMatchEvent($event, $playerTeam)) {
                $matchEvents[] = $mapped;
            }
        }

        $this->checkTotals($teams, $players, $playerTeam);

        // Team blocks
        $teamBlocks = [];
        foreach ($teamIds as $teamId) {
            $teamName = $match->teams->firstWhere('team_id', $teamId)?->team?->name;
            $teamPlayers = $match->matchPlayers->where('team_id', $teamId);
            $teamBlocks[] = $this->teamBlock($teamId, $teamName, $teams[$teamId], $teamPlayers, $players);
        }

        $scores = [];
        foreach ($teamIds as $teamId) {
            $scores[(string) $teamId] = $this->teamTotal($teams[$teamId]);
        }

        $status = $match->status instanceof MatchStatus ? $match->status->value : $match->status;
        $currentHalf = $lastRaid?->half ?? ($status === 'second_half' ? 2 : 1);

        return [
            'meta' => [
                'match-id' => (string) $match->id,
                'tournament-id' => $match->tournament_id ? (string) $match->tournament_id : null,
                'status' => self::STATUS_MAP[$status] ?? $status,
                'current-half' => (string) $currentHalf,
                'last-raid-number' => $lastRaid ? (int) $lastRaid->raid_number : 0,
                'score' => $scores,
            ],
            'in-match' => [
                'team-players-statistics' => ['team' => $teamBlocks],
                'play-by-play' => ['raid' => $playByPlay],
                'match-events' => ['event' => $matchEvents],
                'match-details' => ['result' => $this->result($match, $status, $currentHalf, $lastRaid, $teamIds, $scores)],
            ],
        ];
    }

    /**
     * Applies one raid's points. Must stay in line with ScoreboardService.
     */
    private function applyRaid(Raid $raid, int $raidTeam, int $defTeam, array &$teams, array &$players): void
    {
        $raiderId = (int) $raid->raider_id;
        $tackler = $raid->tacklers; // hasOne
        $tacklerId = $tackler ? (int) $tackler->user_id : null;
        $lineouts = $raid->defenderLineouts->count();

        if ($raid->outcome === 'successful') {
            $touches = $raid->defenders->count();
            $teams[$raidTeam]['raid']['touch'] += $touches;
            if (isset($players[$raiderId])) {
                $players[$raiderId]['raid']['touch'] += $touches;
            }
            foreach ($raid->defenders as $defender) {
                if (isset($players[(int) $defender->user_id])) {
                    $players[(int) $defender->user_id]['tackles']['unsuccessful']++;
                }
                $teams[$defTeam]['tackles']['unsuccessful']++;
            }

            $teams[$raidTeam]['extra']['lineout'] += $lineouts;

            if ($raid->bonus_point) {
                $teams[$raidTeam]['raid']['bonus']++;
                if (isset($players[$raiderId])) {
                    $players[$raiderId]['raid']['bonus']++;
                }
            }

            if ($raid->super_raid && isset($players[$raiderId])) {
                $teams[$raidTeam]['raids']['super']++;
                $players[$raiderId]['raids']['super']++;
            }

            if ($tacklerId) {
                $teams[$defTeam]['tackle']['capture']++;
                $teams[$defTeam]['tackles']['successful']++;
                if (isset($players[$tacklerId])) {
                    $players[$tacklerId]['tackle']['capture']++;
                    $players[$tacklerId]['tackles']['successful']++;
                }
            }

            if ($raid->all_out) {
                $teams[$raidTeam]['all-out'] += 2;
            }
        }

        if ($raid->outcome === 'unsuccessful') {
            $teams[$raidTeam]['extra']['lineout'] += $lineouts;

            if ($tacklerId) {
                $teams[$defTeam]['tackle']['capture']++;
                $teams[$defTeam]['tackles']['successful']++;
                if (isset($players[$tacklerId])) {
                    $players[$tacklerId]['tackle']['capture']++;
                    $players[$tacklerId]['tackles']['successful']++;
                    if ($raid->super_tackle) {
                        $players[$tacklerId]['tackle']['bonus']++;
                        $players[$tacklerId]['tackles']['super']++;
                        $teams[$defTeam]['tackle']['bonus']++;
                        $teams[$defTeam]['tackles']['super']++;
                    }
                }
            }

            if ($raid->bonus_point) {
                $teams[$raidTeam]['raid']['bonus']++;
                if (isset($players[$raiderId])) {
                    $players[$raiderId]['raid']['bonus']++;
                }
            }

            if ($raid->raider_lineout) {
                $teams[$defTeam]['extra']['lineout']++;
            }

            if ($raid->all_out) {
                $teams[$defTeam]['all-out'] += 2;
            }
        }
    }

    private function countDoOrDie(array &$bucket, string $outcome, int $points): void
    {
        $bucket['total']++;
        $bucket[$outcome]++;
        $bucket['points'] += max($points, 0);
    }

    private function teamTotal(array $t): int
    {
        return $t['raid']['touch'] + $t['raid']['bonus']
            + $t['tackle']['capture'] + $t['tackle']['bonus']
            + $t['all-out']
            + $t['extra']['technical'] + $t['extra']['lineout'];
    }

    /**
     * Player raid + tackle points must equal the team's raid + tackle points.
     */
    private function checkTotals(array $teams, array $players, array $playerTeam): void
    {
        foreach ($teams as $teamId => $t) {
            $playerSum = 0;
            foreach ($players as $playerId => $p) {
                if (($playerTeam[$playerId] ?? null) === $teamId) {
                    $playerSum += $p['raid']['touch'] + $p['raid']['bonus'] + $p['tackle']['capture'] + $p['tackle']['bonus'];
                }
            }
            $teamSum = $t['raid']['touch'] + $t['raid']['bonus'] + $t['tackle']['capture'] + $t['tackle']['bonus'];

            if ($playerSum !== $teamSum) {
                // Happens only if a raider/tackler is no longer in match_players. Log, don't block the feed.
                report(new RuntimeException("Live feed totals mismatch for team {$teamId}: players {$playerSum}, team {$teamSum}"));
            }
        }
    }

    private function teamBlock(int $teamId, ?string $name, array $t, $teamPlayers, array $players): array
    {
        // Revival order: players who are out, oldest out first (same order RaidService revives them).
        $outQueue = $teamPlayers
            ->filter(fn($mp) => !$mp->is_substitute && !$mp->is_playing)
            ->sortBy('updated_at')
            ->values()
            ->pluck('user_id')
            ->map(fn($id) => (int) $id)
            ->all();

        $onCourt = $teamPlayers->filter(fn($mp) => !$mp->is_substitute && $mp->is_playing)->count();

        $playerBlocks = [];
        foreach ($teamPlayers as $mp) {
            $userId = (int) $mp->user_id;
            $p = $players[$userId] ?? $this->emptyPlayerStats();
            $revival = array_search($userId, $outQueue, true);
            $raidTotal = $p['raid']['touch'] + $p['raid']['bonus'];
            $tackleTotal = $p['tackle']['capture'] + $p['tackle']['bonus'];

            $playerBlocks[] = [
                'player-id' => $this->s($userId),
                'jersey-no' => $this->s($mp->user?->jersey_no),
                'player-name' => $mp->user?->fullname,
                'player-on-court' => (!$mp->is_substitute && $mp->is_playing) ? 'true' : 'false',
                'player-revival-order' => $revival === false ? '0' : $this->s($revival + 1),
                'points' => [
                    'total-points' => $this->s($raidTotal + $tackleTotal),
                    'raid-points' => [
                        'total' => $this->s($raidTotal),
                        'touch' => $this->s($p['raid']['touch']),
                        'bonus' => $this->s($p['raid']['bonus']),
                    ],
                    'tackle-points' => [
                        'total' => $this->s($tackleTotal),
                        'capture' => $this->s($p['tackle']['capture']),
                        'bonus' => $this->s($p['tackle']['bonus']),
                    ],
                ],
                'raids' => $this->raidCounters($p['raids']),
                'tackles' => $this->tackleCounters($p['tackles']),
                'cards' => [
                    'green' => $this->s($mp->green_card ?? 0),
                    'yellow' => $this->s($mp->yellow_card ?? 0),
                    'red' => $this->s($mp->red_card ?? 0),
                ],
                'do-or-die' => $this->doOrDieCounters($p['do-or-die']),
            ];
        }

        $raidTotal = $t['raid']['touch'] + $t['raid']['bonus'];
        $tackleTotal = $t['tackle']['capture'] + $t['tackle']['bonus'];

        return [
            'team-name' => $name,
            'team-id' => $this->s($teamId),
            'no-of-players-on-court' => $this->s($onCourt),
            'points' => [
                'total-points' => $this->s($this->teamTotal($t)),
                'tackle-points' => [
                    'total' => $this->s($tackleTotal),
                    'capture' => $this->s($t['tackle']['capture']),
                    'tackle-bonus' => $this->s($t['tackle']['bonus']),
                ],
                'raid-points' => [
                    'total' => $this->s($raidTotal),
                    'touch' => $this->s($t['raid']['touch']),
                    'raid-bonus' => $this->s($t['raid']['bonus']),
                ],
                'all-out-points' => $this->s($t['all-out']),
                'extra-points' => $this->s($t['extra']['technical'] + $t['extra']['lineout']),
            ],
            'raids' => $this->raidCounters($t['raids']),
            'tackles' => $this->tackleCounters($t['tackles']),
            'do-or-die' => $this->doOrDieCounters($t['do-or-die']),
            'players' => ['player' => $playerBlocks],
        ];
    }

    private function raidCounters(array $r): array
    {
        return [
            'total-raids' => $this->s($r['total']),
            'super-raids' => $this->s($r['super']),
            'successful-raids' => $this->s($r['successful']),
            'unsuccessful-raids' => $this->s($r['unsuccessful']),
            'empty-raids' => $this->s($r['empty']),
        ];
    }

    private function tackleCounters(array $t): array
    {
        return [
            'total-tackles' => $this->s($t['successful'] + $t['unsuccessful']),
            'super-tackles' => $this->s($t['super']),
            'successful-tackles' => $this->s($t['successful']),
            'unsuccessful-tackles' => $this->s($t['unsuccessful']),
        ];
    }

    private function doOrDieCounters(array $d): array
    {
        return [
            'total-raids' => $this->s($d['total']),
            'successful-raids' => $this->s($d['successful']),
            'unsuccessful-raids' => $this->s($d['unsuccessful']),
            'empty-raids' => $this->s($d['empty']),
            'points' => $this->s($d['points']),
        ];
    }

    private function mapMatchEvent($event, array $playerTeam): ?array
    {
        $type = $event->type?->value;
        $base = [
            'event-id' => $this->s($event->id),
            'half' => $this->s($event->half),
            'raid-number' => $this->s($event->raid_number),
            'time' => $event->created_at?->toIso8601String(),
        ];

        if ($type === EventType::CARD->value) {
            return $base + [
                'type' => 'card',
                'team-id' => $this->s($event->team_id),
                'player-id' => $this->s($event->user_id),
                'card' => str_replace('_card', '', (string) $event->card_type), // green | yellow | red
            ];
        }

        if ($type === EventType::UPDATE_SUBSTITUTES->value) {
            $in = $out = [];
            foreach ((array) $event->payload as $row) {
                $id = (int) ($row['id'] ?? 0);
                $entry = ['player-id' => $this->s($id), 'team-id' => $this->s($playerTeam[$id] ?? null)];
                if (!empty($row['is_substitute'])) {
                    $out[] = $entry;
                } else {
                    $in[] = $entry;
                }
            }

            return $base + [
                'type' => 'substitution',
                'players-in' => $in,
                'players-out' => $out,
            ];
        }

        if ($type === EventType::TECHNICAL_POINT->value) {
            return $base + [
                'type' => 'technical-point',
                'team-id' => $this->s($event->team_id),
            ];
        }

        return null;
    }

    private function result(GameMatch $match, ?string $status, $currentHalf, ?Raid $lastRaid, array $teamIds, array $scores): array
    {
        // Teams alternate raids (a skipped raid still uses the turn).
        $nextRaidingTeam = null;
        if ($lastRaid) {
            $nextRaidingTeam = (int) $lastRaid->raid_team_id === $teamIds[0] ? $teamIds[1] : $teamIds[0];
        }

        $resultType = '';
        $winner = null;
        $text = '';

        if ($status === MatchStatus::COMPLETED->value) {
            $winner = $match->winner_team_id ? (int) $match->winner_team_id : null;
            if ($winner) {
                $loser = $winner === $teamIds[0] ? $teamIds[1] : $teamIds[0];
                $margin = $scores[(string) $winner] - $scores[(string) $loser];
                $winnerName = $match->teams->firstWhere('team_id', $winner)?->team?->name;
                $resultType = 'win';
                $text = "{$winnerName} won by {$margin} point" . ($margin === 1 ? '' : 's');
            } else {
                $resultType = 'draw';
                $text = 'Match drawn';
            }
        }

        return [
            'Stage' => $match->stage,
            'Half' => (string) $currentHalf,
            'RaidingClubId' => $this->s($nextRaidingTeam),
            'result-type' => $resultType,
            'winning-team-id' => $this->s($winner),
            'result' => $text,
        ];
    }

    private function emptyTeamStats(): array
    {
        return [
            'raid' => ['touch' => 0, 'bonus' => 0],
            'tackle' => ['capture' => 0, 'bonus' => 0],
            'all-out' => 0,
            'extra' => ['technical' => 0, 'lineout' => 0],
            'raids' => ['total' => 0, 'super' => 0, 'successful' => 0, 'unsuccessful' => 0, 'empty' => 0],
            'tackles' => ['super' => 0, 'successful' => 0, 'unsuccessful' => 0],
            'do-or-die' => ['total' => 0, 'successful' => 0, 'unsuccessful' => 0, 'empty' => 0, 'points' => 0],
        ];
    }

    private function emptyPlayerStats(): array
    {
        return [
            'raid' => ['touch' => 0, 'bonus' => 0],
            'tackle' => ['capture' => 0, 'bonus' => 0],
            'raids' => ['total' => 0, 'super' => 0, 'successful' => 0, 'unsuccessful' => 0, 'empty' => 0],
            'tackles' => ['super' => 0, 'successful' => 0, 'unsuccessful' => 0],
            'do-or-die' => ['total' => 0, 'successful' => 0, 'unsuccessful' => 0, 'empty' => 0, 'points' => 0],
        ];
    }

    private function s($value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
