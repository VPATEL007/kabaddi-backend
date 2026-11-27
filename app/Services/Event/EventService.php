<?php

namespace App\Services\Event;

use App\Enums\EventType;
use App\Models\EventLog;
use App\Models\MatchPlayer;
use App\Services\Broadcast\BroadcastPublisher;
use App\Services\Event\EventServiceInterface;
use App\Services\Raid\RaidServiceInterface;
use App\Services\Scoreboard\ScoreboardServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EventService implements EventServiceInterface
{
    public function list(array $filters): LengthAwarePaginator
    {
        $query = EventLog::with('raid');

        if (isset($filters['match_id'])) {
            $query->where('match_id', $filters['match_id']);
        }

        if (isset($filters['team_id'])) {
            $query->where('team_id', $filters['team_id']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $perPage = (int) request()->get('per_page', 15);
        $perPage = $perPage > 0 ? $perPage : 15;

        $paginator = $query->orderByDesc('id')->paginate($perPage);
        $paginator->getCollection()->transform(function ($eventLog) {
            $eventLog->raid_dto = $eventLog->raid_dto;
            return $eventLog;
        });
        return $paginator;
    }

    public function store(array $data): EventLog
    {
        $event = EventLog::create([
            'type' => $data['event_type'],
            'team_id' => $data['team_id'] ?? null,
            'match_id' => $data['match_id'],
            'half' => $data['half'] ?? null,
            'raid_number' => $data['raid_number'] ?? null,
            'summary' => $data['summary'] ?? null,
        ]);

        $scoreService = app(ScoreboardServiceInterface::class);
        $scoreboard = $scoreService->getMatchScoreboard($data['match_id']);
        $event->score_after_raid = $scoreboard->teamBreakdowns ?? [];
        $event->save();

        app(BroadcastPublisher::class)->trigger((int) $data['match_id'], match ($event->type?->value) {
            EventType::TECHNICAL_POINT->value => BroadcastPublisher::EVENT_TECHNICAL_POINT,
            EventType::CARD->value => BroadcastPublisher::EVENT_CARD,
            EventType::UPDATE_SUBSTITUTES->value => BroadcastPublisher::EVENT_SUBSTITUTION,
            default => BroadcastPublisher::EVENT_STATUS,
        });

        return $event->refresh();
    }

    public function update(EventLog $event, array $data): EventLog
    {
        $event->update([
            'type' => $data['event_type'] ?? $event->type,
            'team_id' => $data['team_id'] ?? $event->team_id,
            'match_id' => $data['match_id'] ?? $event->match_id,
            'raid_id' => $data['raid_id'] ?? $event->raid_id,
            'half' => $data['half'] ?? $event->half,
            'raid_number' => $data['raid_number'] ?? $event->raid_number,
            'summary' => $data['summary'] ?? $event->summary,
        ]);

        $scoreService = app(ScoreboardServiceInterface::class);
        $scoreboard = $scoreService->getMatchScoreboard($data['match_id']);
        $event->score_after_raid = $scoreboard->teamBreakdowns ?? [];
        $event->save();

        app(BroadcastPublisher::class)->trigger((int) $event->match_id, BroadcastPublisher::EVENT_CORRECTED);

        return $event->refresh();
    }

    public function delete(EventLog $event): void
    {
        if ($event->raid_id) {
            $raidService = app(RaidServiceInterface::class);
            $raidService->undoLastRaid($event->match_id);
        }

        if ($event->type && ($event->type->value === EventType::CARD->value)) {
            $matchPlayer = MatchPlayer::where('match_id', $event->match_id)
                ->where('user_id', $event->user_id)
                ->first();
            if ($event->card_type == "green_card") {
                $matchPlayer->update([
                    'green_card' => 0,
                ]);
            }
            if ($event->card_type == "yellow_card") {
                $matchPlayer->update([
                    'yellow_card' => 0,
                ]);
            }
            if ($event->card_type == "red_card") {
                $matchPlayer->update([
                    'red_card' => 0,
                ]);
            }
        }

        if ($event->type && ($event->type->value === EventType::UPDATE_SUBSTITUTES->value)) {
            $subPlayers = $event->payload;

            // Replacements: the two players traded slots, so trade them back.
            $swappedBack = [];
            foreach ($subPlayers as $subPlayer) {
                if (empty($subPlayer['slot_with'])) {
                    continue;
                }
                $pair = MatchPlayer::where('match_id', $event->match_id)
                    ->whereIn('user_id', [$subPlayer['id'], $subPlayer['slot_with']])
                    ->get();
                if ($pair->count() === 2) {
                    MatchPlayer::swapSlots($pair[0], $pair[1]);
                }
                $swappedBack[] = (int) $subPlayer['id'];
                $swappedBack[] = (int) $subPlayer['slot_with'];
            }

            foreach ($subPlayers as $subPlayer) {
                if (in_array((int) $subPlayer['id'], $swappedBack, true)) {
                    continue;
                }
                $matchPlayer = MatchPlayer::where('match_id', $event->match_id)
                    ->where('user_id', $subPlayer['id'])
                    ->first();
                if ($subPlayer['is_substitute']) {
                    $matchPlayer->update([
                        'is_substitute' => false,
                        'is_playing' => true,
                    ]);
                } else {
                    $matchPlayer->update([
                        'is_substitute' => true,
                        'is_playing' => false,
                    ]);
                }
            }
        }

        $event->delete();

        app(BroadcastPublisher::class)->trigger((int) $event->match_id, BroadcastPublisher::EVENT_CORRECTED);
    }
}
