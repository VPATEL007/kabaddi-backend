<?php

namespace App\Http\Controllers\Broadcast;

use App\Enums\MatchStatus;
use App\Events\MatchFeedUpdated;
use App\Http\Controllers\Controller;
use App\Models\BroadcastPartner;
use App\Models\GameMatch;
use App\Models\MatchBroadcastState;
use App\Services\Broadcast\BroadcastPublisher;
use Illuminate\Http\Request;

/**
 * Public API for broadcasters / media partners (X-API-Key header).
 */
class PartnerFeedController extends Controller
{
    public function __construct(private BroadcastPublisher $publisher) {}

    /**
     * GET /api/v1/broadcast/matches?status=live&date=2026-11-26
     * Matches this partner can see that have the feed switched on.
     */
    public function matches(Request $request)
    {
        $partner = $this->partner($request);

        $states = MatchBroadcastState::whereIn('match_id', $partner->accessibleMatchIds())
            ->where('current_version', '>', 0)
            ->get()
            ->keyBy('match_id');

        $query = GameMatch::with('teams.team')->whereIn('id', $states->keys());

        if ($date = $request->query('date')) {
            $query->whereDate('start_date', $date);
        }

        $statusFilter = $request->query('status');
        if ($statusFilter === 'live') {
            $query->whereIn('status', MatchStatus::isLive());
        } elseif ($statusFilter === 'completed') {
            $query->where('status', MatchStatus::COMPLETED->value);
        } elseif ($statusFilter === 'scheduled') {
            $query->where('status', MatchStatus::UPCOMING->value);
        }

        $matches = $query->orderByDesc('start_date')->get()->map(function (GameMatch $match) use ($states) {
            $state = $states->get($match->id);

            return [
                'match-id' => (string) $match->id,
                'tournament-id' => $match->tournament_id ? (string) $match->tournament_id : null,
                'title' => $match->title,
                'start-date' => $match->start_date,
                'start-time' => $match->start_time,
                'status' => $match->status,
                'feed-enabled' => (bool) $state->enabled,
                'version' => (int) $state->current_version,
                'channel' => 'private-' . MatchFeedUpdated::channelName($match->id),
                'teams' => $match->teams->map(fn($t) => [
                    'team-id' => (string) $t->team_id,
                    'team-name' => $t->team?->name,
                ])->values(),
            ];
        });

        return response()->json(['matches' => $matches]);
    }

    /**
     * GET /api/v1/broadcast/matches/{id}/live
     * Full current snapshot. Use on connect / reconnect; the WebSocket delivers every update after that.
     * ETag = version, so "If-None-Match" gives 304 when nothing changed.
     */
    public function live(Request $request, int $id)
    {
        $partner = $this->partner($request);
        $match = GameMatch::find($id);

        if (!$match || !$partner->canAccessMatch($match)) {
            return response()->json(['message' => 'Match not found.'], 404);
        }

        $payload = $this->publisher->latest($match->id);
        if (!$payload) {
            return response()->json(['message' => 'The live feed for this match has not started yet.'], 404);
        }

        $etag = '"' . $payload['meta']['version'] . '"';

        // Proxies/compression (e.g. Hostinger LiteSpeed) turn the ETag into a weak one: W/"42".
        // Accept both forms, and a comma-separated list.
        $sent = array_map(
            fn($tag) => preg_replace('#^W/#', '', trim($tag)),
            explode(',', (string) $request->header('If-None-Match'))
        );
        if (in_array($etag, $sent, true)) {
            return response('', 304)->header('ETag', $etag);
        }

        return response()->json($payload)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'no-cache');
    }

    /**
     * POST /api/v1/broadcast/socket-auth  (socket_id, channel_name)
     * Authorises a WebSocket subscription to "private-broadcast.match.{id}" for this partner.
     * Pusher-protocol clients call this automatically (channelAuthorization endpoint).
     */
    public function socketAuth(Request $request)
    {
        $partner = $this->partner($request);

        $data = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'regex:/^private-broadcast\.match\.\d+$/'],
        ]);

        $matchId = (int) substr($data['channel_name'], strlen('private-broadcast.match.'));
        $match = GameMatch::find($matchId);

        if (!$match || !$partner->canAccessMatch($match)) {
            return response()->json(['message' => 'You do not have access to this match.'], 403);
        }

        $key = config('broadcasting.connections.reverb.key');
        $secret = config('broadcasting.connections.reverb.secret');
        $signature = hash_hmac('sha256', $data['socket_id'] . ':' . $data['channel_name'], $secret);

        return response()->json(['auth' => $key . ':' . $signature]);
    }

    private function partner(Request $request): BroadcastPartner
    {
        return $request->attributes->get('broadcast_partner');
    }
}
