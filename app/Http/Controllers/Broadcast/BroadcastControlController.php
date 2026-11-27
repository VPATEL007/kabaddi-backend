<?php

namespace App\Http\Controllers\Broadcast;

use App\Events\MatchFeedUpdated;
use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\MatchBroadcastState;
use App\Services\Broadcast\BroadcastPublisher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Broadcast Feed panel on the scoring page (logged-in scorer).
 */
class BroadcastControlController extends Controller
{
    public function __construct(private BroadcastPublisher $publisher) {}

    public function status(Request $request, int $id)
    {
        $match = $this->authorizedMatch($request, $id);
        $state = MatchBroadcastState::firstOrNew(['match_id' => $match->id]);

        return response()->json([
            'success' => true,
            'data' => $this->stateResponse($state),
        ]);
    }

    public function enable(Request $request, int $id)
    {
        $data = $request->validate([
            'mode' => ['nullable', Rule::in([MatchBroadcastState::MODE_AUTO, MatchBroadcastState::MODE_MANUAL])],
        ]);

        $match = $this->authorizedMatch($request, $id);
        $state = MatchBroadcastState::firstOrCreate(['match_id' => $match->id]);
        $state->update([
            'enabled' => true,
            'mode' => $data['mode'] ?? $state->mode ?? MatchBroadcastState::MODE_AUTO,
        ]);

        // Publish the current state straight away so partners have something to show.
        $this->publisher->publish($match->id, BroadcastPublisher::EVENT_FEED_STARTED, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Broadcast feed enabled.',
            'data' => $this->stateResponse($state->fresh()),
        ]);
    }

    public function disable(Request $request, int $id)
    {
        $match = $this->authorizedMatch($request, $id);
        $state = MatchBroadcastState::firstOrCreate(['match_id' => $match->id]);
        $state->update(['enabled' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Broadcast feed disabled. The last published version stays readable.',
            'data' => $this->stateResponse($state->fresh()),
        ]);
    }

    public function mode(Request $request, int $id)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in([MatchBroadcastState::MODE_AUTO, MatchBroadcastState::MODE_MANUAL])],
        ]);

        $match = $this->authorizedMatch($request, $id);
        $state = MatchBroadcastState::firstOrCreate(['match_id' => $match->id]);
        $state->update(['mode' => $data['mode']]);

        return response()->json([
            'success' => true,
            'data' => $this->stateResponse($state->fresh()),
        ]);
    }

    /**
     * "Push now" (Manual mode) and "Re-publish" (resend current state). Works in both modes.
     */
    public function publish(Request $request, int $id)
    {
        $match = $this->authorizedMatch($request, $id);
        $state = MatchBroadcastState::where('match_id', $match->id)->first();

        if (!$state || !$state->enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Turn on the broadcast feed for this match first.',
            ]);
        }

        $snapshot = $this->publisher->publish($match->id, BroadcastPublisher::EVENT_MANUAL, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => "Published version {$snapshot->version}.",
            'data' => $this->stateResponse($state->fresh()),
        ]);
    }

    private function authorizedMatch(Request $request, int $id): GameMatch
    {
        $match = GameMatch::with('tournament')->findOrFail($id);
        $userId = $request->user()->id;

        $allowed = (int) $match->created_by === (int) $userId
            || ($match->tournament && (int) $match->tournament->created_by === (int) $userId);

        abort_unless($allowed, 403, 'Only the match or tournament organiser can control the broadcast feed.');

        return $match;
    }

    private function stateResponse(MatchBroadcastState $state): array
    {
        return [
            'match_id' => (int) $state->match_id,
            'enabled' => (bool) $state->enabled,
            'mode' => $state->mode ?? MatchBroadcastState::MODE_AUTO,
            'current_version' => (int) $state->current_version,
            'last_published_at' => $state->last_published_at?->toIso8601String(),
            'channel' => 'private-' . MatchFeedUpdated::channelName((int) $state->match_id),
        ];
    }
}
