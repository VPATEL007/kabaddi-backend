<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sent over WebSocket (Laravel Reverb) to broadcasters subscribed to
 * "private-broadcast.match.{matchId}". ShouldBroadcastNow skips the queue for speed.
 */
class MatchFeedUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $matchId,
        public string $event,
        public array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelName($this->matchId))];
    }

    public static function channelName(int $matchId): string
    {
        return "broadcast.match.{$matchId}";
    }

    public function broadcastAs(): string
    {
        return 'feed.updated';
    }

    public function broadcastWith(): array
    {
        $raids = $this->payload['in-match']['play-by-play']['raid'] ?? [];

        return [
            'event' => $this->event,
            'match-id' => (string) $this->matchId,
            'version' => $this->payload['meta']['version'] ?? null,
            'latest-raid' => $raids ? end($raids) : null,
            'data' => $this->payload,
        ];
    }
}
