<?php

namespace App\Services\Broadcast;

use App\Enums\EventType;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Raid;
use RuntimeException;

/**
 * Builds the broadcaster live feed for one match, in the client's JSON format
 * (structure and key names follow the client's sample "Live Scoring API.json").
 *
 * Scoring rules mirror ScoreboardService::getMatchScoreboard() so the feed totals
 * always equal the scoring app's totals. Client mapping decisions:
 *  - client key names kept; numbers sent as strings; "successfull-raids" spelling corrected
 *  - player-on-court only when on court, player-revival-order only when out (as in the sample)
 *  - extra-points = technical points + lineout points; bonus goes under raid-points
 *  - tackle-bonus-points = super tackle extra point
 *  - removed: points-last-n-minutes, skill-points, time-off-court, avg-raid-time,
 *    raid-map-locations, phase-of-play
 *  - added: match-events (cards, substitutions, technical points)
 */
class LiveFeedBuilder
{
    /**
     * raid-outcome-id. Follows the client's sample data (2 = empty, 3 = unsuccessful).
     * NOTE: the client's written answer said 2 = unsuccessful, 3 = empty. Confirm with the client;
     * to switch, swap the two values below.
     */
    public const OUTCOME_CODES = [
        'successful' => 1,
        'empty' => 2,
        'unsuccessful' => 3,
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
            $teams[$teamId] = $this->emptyStats(true);
        }

        $players = [];
        $playerTeam = [];
        foreach ($match->matchPlayers as $mp) {
            $players[(int) $mp->user_id] = $this->emptyStats(false);
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

            $before = [$raidTeam => $this->pointParts($teams[$raidTeam]), $defTeam => $this->pointParts($teams[$defTeam])];
            $raiderBefore = isset($players[$raiderId]) ? $players[$raiderId]['raid'] : null;

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
                $after = $this->pointParts($teams[$raidTeam]);
                $touch = $after['touch'] - $before[$raidTeam]['touch'];
                $bonus = $after['bonus'] - $before[$raidTeam]['bonus'];
                $this->countDoOrDie($teams[$raidTeam]['do-or-die'], $outcome, (bool) $raid->super_raid, $touch, $bonus);
                if ($raiderBefore !== null) {
                    $this->countDoOrDie(
                        $players[$raiderId]['do-or-die'],
                        $outcome,
                        (bool) $raid->super_raid,
                        $players[$raiderId]['raid']['touch'] - $raiderBefore['touch'],
                        $players[$raiderId]['raid']['bonus'] - $raiderBefore['bonus'],
                    );
                }
            }

            $emptyStreak[$raidTeam] = ($outcome === 'empty' && !$isDoOrDie) ? $emptyStreak[$raidTeam] + 1 : 0;

            $raider = $match->matchPlayers->firstWhere('user_id', $raiderId);
            $raidDelta = $this->delta($before[$raidTeam], $this->pointParts($teams[$raidTeam]));
            $defDelta = $this->delta($before[$defTeam], $this->pointParts($teams[$defTeam]));

            $playByPlay[] = [
                'raid-number' => $this->s($raid->raid_number),
                'raiding-team-id' => $this->s($raidTeam),
                'raiding-player-id' => $this->s($raiderId),
                'player-jersey-no' => $this->s($raider?->user?->jersey_no),
                'raid-outcome-id' => $this->s(self::OUTCOME_CODES[$outcome]),
                'raid-half' => $this->s($raid->half),
                // team[0] is always the raiding team
                'team' => [
                    $this->raidingTeamRaidPoints($raidTeam, $raidDelta),
                    $this->defendingTeamRaidPoints($defTeam, $defDelta),
                ],
            ];
        }

        // Technical points (non-raid events) + match events
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
        $currentHalf = (int) ($lastRaid?->half ?? ($status === 'second_half' ? 2 : 1));

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
                'match-details' => ['result' => $this->result($match, $status, $currentHalf, $lastRaid, $teamIds, $scores)],
                'match-events' => ['event' => $matchEvents],
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

    private function countDoOrDie(array &$bucket, string $outcome, bool $superRaid, int $touch, int $bonus): void
    {
        $bucket['total']++;
        if ($outcome === 'successful') {
            $bucket['successful']++;
        } else {
            $bucket['failed']++; // unsuccessful or empty do-or-die raid
        }
        if ($superRaid) {
            $bucket['super']++;
        }
        $bucket['touch'] += max($touch, 0);
        $bucket['bonus'] += max($bonus, 0);
    }

    private function pointParts(array $t): array
    {
        return [
            'touch' => $t['raid']['touch'],
            'bonus' => $t['raid']['bonus'],
            'capture' => $t['tackle']['capture'],
            'tackle-bonus' => $t['tackle']['bonus'],
            'all-out' => $t['all-out'],
            'extra' => $t['extra']['technical'] + $t['extra']['lineout'],
        ];
    }

    private function delta(array $before, array $after): array
    {
        $d = [];
        foreach ($after as $k => $v) {
            $d[$k] = $v - $before[$k];
        }
        return $d;
    }

    private function teamTotal(array $t): int
    {
        return array_sum($this->pointParts($t));
    }

    private function raidingTeamRaidPoints(int $teamId, array $d): array
    {
        $total = (string) array_sum($d);

        return [
            'team-id' => (string) $teamId,
            'total-points' => $total,
            'points' => [
                'total-points' => $total,
                'raid-points' => [
                    'total-raid-points' => (string) ($d['touch'] + $d['bonus']),
                    'touch-points' => (string) $d['touch'],
                    'raid-bonus-points' => (string) $d['bonus'],
                ],
                'tackle-points' => [
                    'total-tackle-points' => (string) ($d['capture'] + $d['tackle-bonus']),
                ],
                'all-out-points' => (string) $d['all-out'],
                'extra-points' => (string) $d['extra'],
            ],
        ];
    }

    private function defendingTeamRaidPoints(int $teamId, array $d): array
    {
        $total = (string) array_sum($d);

        return [
            'team-id' => (string) $teamId,
            'total-points' => $total,
            'points' => [
                'total-points' => $total,
                'raid-points' => [
                    'total-raid-points' => (string) ($d['touch'] + $d['bonus']),
                ],
                'tackle-points' => [
                    'total-tackle-points' => (string) ($d['capture'] + $d['tackle-bonus']),
                    'capture-points' => (string) $d['capture'],
                    'tackle-bonus-points' => (string) $d['tackle-bonus'],
                ],
                'all-out-points' => (string) $d['all-out'],
                'extra-points' => (string) $d['extra'],
            ],
        ];
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
        foreach ($teamPlayers->sortBy(fn($mp) => (int) ($mp->user?->jersey_no ?? 999)) as $mp) {
            $userId = (int) $mp->user_id;
            $p = $players[$userId] ?? $this->emptyStats(false);
            $raidTotal = $p['raid']['touch'] + $p['raid']['bonus'];
            $tackleTotal = $p['tackle']['capture'] + $p['tackle']['bonus'];

            $block = [
                'player-id' => $this->s($userId),
                'jersey-no' => $this->s($mp->user?->jersey_no),
                'player-name' => $mp->user?->fullname,
            ];

            // As in the client format: on-court flag only when on court, revival order only when out.
            if (!$mp->is_substitute && $mp->is_playing) {
                $block['player-on-court'] = 'true';
            } else {
                $revival = array_search($userId, $outQueue, true);
                if ($revival !== false) {
                    $block['player-revival-order'] = (string) ($revival + 1);
                }
            }

            $playerBlocks[] = $block + [
                'points' => [
                    'total-points' => $this->s($raidTotal + $tackleTotal),
                    'raid-points' => [
                        'total-raid-points' => $this->s($raidTotal),
                        'touch-points' => [
                            'total-touch-points' => $this->s($p['raid']['touch']),
                        ],
                        'raid-bonus-points' => $this->s($p['raid']['bonus']),
                    ],
                    'tackle-points' => [
                        'total-tackle-points' => $this->s($tackleTotal),
                        'capture-points' => [
                            'total-capture-points' => $this->s($p['tackle']['capture']),
                        ],
                        'tackle-bonus-points' => $this->s($p['tackle']['bonus']),
                    ],
                ],
                'raids' => $this->raidCounters($p['raids']),
                'tackles' => $this->tackleCounters($p['tackles']),
                'cards' => [
                    'green-card' => $this->card($mp->green_card),
                    'yellow-card' => $this->card($mp->yellow_card),
                    'red-card' => $this->card($mp->red_card),
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
                    'total-tackle-points' => $this->s($tackleTotal),
                    'capture-points' => $this->s($t['tackle']['capture']),
                    'tackle-bonus-points' => $this->s($t['tackle']['bonus']),
                ],
                'raid-points' => [
                    'total-raid-points' => $this->s($raidTotal),
                    'touch-points' => $this->s($t['raid']['touch']),
                    'raid-bonus-points' => $this->s($t['raid']['bonus']),
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

    private function card($count): array
    {
        $count = (string) ((int) $count);

        return ['count' => $count, '#text' => $count];
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
            'successful-raids' => $this->s($d['successful']), // client sample: "successfull-raids"
            'failed-raids' => $this->s($d['failed']),
            'super-raids' => $this->s($d['super']),
            'raid-points' => $this->s($d['touch'] + $d['bonus']),
            'touch-points' => $this->s($d['touch']),
            'bonus-points' => $this->s($d['bonus']),
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
                'card' => str_replace('_', '-', (string) $event->card_type), // green-card | yellow-card | red-card
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

    private function result(GameMatch $match, ?string $status, int $currentHalf, ?Raid $lastRaid, array $teamIds, array $scores): array
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
            $nameOf = fn($id) => $match->teams->firstWhere('team_id', $id)?->team?->name;
            $winner = $match->winner_team_id ? (int) $match->winner_team_id : null;

            if ($winner) {
                $loser = $winner === $teamIds[0] ? $teamIds[1] : $teamIds[0];
                $resultType = 'W';
                // Same text format as the client sample: "Kenya Beat Singapore  (39-26 )"
                $text = sprintf('%s Beat %s  (%d-%d )', $nameOf($winner), $nameOf($loser), $scores[(string) $winner], $scores[(string) $loser]);
            } else {
                $resultType = 'D';
                $text = sprintf('%s Drew With %s  (%d-%d )', $nameOf($teamIds[0]), $nameOf($teamIds[1]), $scores[(string) $teamIds[0]], $scores[(string) $teamIds[1]]);
            }
        }

        return [
            'Stage' => $match->stage,
            'Half' => $currentHalf === 2 ? '2nd Half' : '1st Half',
            'RaidingClubId' => $this->s($nextRaidingTeam),
            'result-type' => $resultType,
            'winning-team-id' => $this->s($winner),
            '#text' => $text,
        ];
    }

    private function emptyStats(bool $team): array
    {
        $stats = [
            'raid' => ['touch' => 0, 'bonus' => 0],
            'tackle' => ['capture' => 0, 'bonus' => 0],
            'raids' => ['total' => 0, 'super' => 0, 'successful' => 0, 'unsuccessful' => 0, 'empty' => 0],
            'tackles' => ['super' => 0, 'successful' => 0, 'unsuccessful' => 0],
            'do-or-die' => ['total' => 0, 'successful' => 0, 'failed' => 0, 'super' => 0, 'touch' => 0, 'bonus' => 0],
        ];

        if ($team) {
            $stats['all-out'] = 0;
            $stats['extra'] = ['technical' => 0, 'lineout' => 0];
        }

        return $stats;
    }

    private function s($value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
