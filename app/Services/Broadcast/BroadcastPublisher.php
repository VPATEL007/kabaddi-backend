<?php

namespace App\Services\Broadcast;

use App\Events\MatchFeedUpdated;
use App\Models\MatchBroadcastState;
use App\Models\MatchFeedSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Publishes new versions of a match's live feed.
 *
 * Flow: build feed -> version + 1 (row lock) -> save snapshot -> cache -> push over WebSocket.
 * Auto mode: scoring code calls trigger(); the publish runs after the HTTP response has been
 * sent to the scorer, so scoring stays fast and partners get the update well under a second.
 * Registered as a singleton so several triggers in one request collapse into one publish.
 */
class BroadcastPublisher
{
    public const EVENT_MATCH_STARTED = 'match.started';
    public const EVENT_RAID_COMPLETED = 'raid.completed';
    public const EVENT_RAID_SKIPPED = 'raid.skipped';
    public const EVENT_CORRECTED = 'match.corrected';
    public const EVENT_CARD = 'card.issued';
    public const EVENT_SUBSTITUTION = 'substitution';
    public const EVENT_TECHNICAL_POINT = 'technical.point';
    public const EVENT_LINEUP = 'lineup.updated';
    public const EVENT_STATUS = 'match.status';
    public const EVENT_ENDED = 'match.ended';
    public const EVENT_FEED_STARTED = 'feed.started';
    public const EVENT_MANUAL = 'manual.publish';

    /** @var array<int, string> matchId => event name, waiting to be published */
    private array $pending = [];

    private bool $flushRegistered = false;

    public function __construct(private LiveFeedBuilder $builder) {}

    /**
     * One cache entry per version. A version's content never changes, so entries can't go stale
     * and two publishes finishing in the wrong order can't overwrite each other.
     */
    public static function cacheKey(int $matchId, int $version): string
    {
        return "broadcast:feed:{$matchId}:v{$version}";
    }

    /**
     * Called from scoring code after a change. Publishes only when the feed is on and in Auto mode.
     */
    public function trigger(int $matchId, string $event): void
    {
        try {
            $state = MatchBroadcastState::where('match_id', $matchId)->first();
            if (!$state || !$state->enabled || !$state->isAuto()) {
                return;
            }
        } catch (Throwable $e) {
            report($e); // never break scoring because of the feed
            return;
        }

        // A correction in the same request wins over other events.
        if (($this->pending[$matchId] ?? null) !== self::EVENT_CORRECTED) {
            $this->pending[$matchId] = $event;
        }

        if (!$this->flushRegistered) {
            $this->flushRegistered = true;
            app()->terminating(fn() => $this->flush());
        }
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        $this->flushRegistered = false;

        foreach ($pending as $matchId => $event) {
            try {
                $this->publish($matchId, $event);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Builds and publishes a new version now. Used by trigger() and by the manual "Push now" button.
     */
    public function publish(int $matchId, string $event, ?int $userId = null): MatchFeedSnapshot
    {
        // Build inside the lock so versions are always in the same order as the data.
        $snapshot = DB::transaction(function () use ($matchId, $event, $userId) {
            $state = MatchBroadcastState::where('match_id', $matchId)->lockForUpdate()->firstOrFail();

            $feed = $this->builder->build($matchId);
            $version = $state->current_version + 1;
            $publishedAt = now();

            $meta = $feed['meta'];
            $payload = [
                'meta' => [
                    'match-id' => $meta['match-id'],
                    'tournament-id' => $meta['tournament-id'],
                    'status' => $meta['status'],
                    'current-half' => $meta['current-half'],
                    'version' => $version,
                    'event' => $event,
                    'last-raid-number' => $meta['last-raid-number'],
                    'published-at' => $publishedAt->toIso8601String(),
                    'score' => $meta['score'],
                ],
                'in-match' => $feed['in-match'],
            ];

            $snapshot = MatchFeedSnapshot::create([
                'match_id' => $matchId,
                'version' => $version,
                'event' => $event,
                'payload' => $payload,
            ]);

            $state->update([
                'current_version' => $version,
                'last_published_at' => $publishedAt,
                'published_by' => $userId,
            ]);

            return $snapshot;
        });

        Cache::put(self::cacheKey($matchId, $snapshot->version), $snapshot->payload, now()->addDays(2));

        try {
            broadcast(new MatchFeedUpdated($matchId, $event, $snapshot->payload));
        } catch (Throwable $e) {
            // WebSocket server down: snapshot is saved, partners can still fetch it over REST.
            report($e);
        }

        return $snapshot;
    }

    /**
     * Current version number, read from the database (source of truth). 0 = feed not started.
     */
    public function latestVersion(int $matchId): int
    {
        return (int) MatchBroadcastState::where('match_id', $matchId)->value('current_version');
    }

    /**
     * Payload of the current version (or of $version when given), from cache or database.
     */
    public function latest(int $matchId, ?int $version = null): ?array
    {
        $version ??= $this->latestVersion($matchId);
        if ($version < 1) {
            return null;
        }

        $payload = Cache::get(self::cacheKey($matchId, $version));
        if ($payload === null) {
            $payload = MatchFeedSnapshot::where('match_id', $matchId)->where('version', $version)->first()?->payload;
            if ($payload !== null) {
                Cache::put(self::cacheKey($matchId, $version), $payload, now()->addDays(2));
            }
        }

        return $payload;
    }
}
