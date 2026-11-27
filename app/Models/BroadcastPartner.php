<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BroadcastPartner extends Model
{
    protected $fillable = [
        'name',
        'api_key_hash',
        'api_key_prefix',
        'rate_limit',
        'is_active',
    ];

    protected $hidden = ['api_key_hash'];

    protected $casts = [
        'is_active' => 'boolean',
        'rate_limit' => 'integer',
    ];

    public function access()
    {
        return $this->hasMany(BroadcastPartnerAccess::class, 'partner_id');
    }

    public static function hashKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    /**
     * Create a partner and return [partner, plainKey]. The plain key is shown once only.
     */
    public static function createWithKey(string $name, int $rateLimit = 60): array
    {
        $plainKey = 'kbd_' . Str::random(40);

        $partner = self::create([
            'name' => $name,
            'api_key_hash' => self::hashKey($plainKey),
            'api_key_prefix' => substr($plainKey, 0, 12),
            'rate_limit' => $rateLimit,
            'is_active' => true,
        ]);

        return [$partner, $plainKey];
    }

    public static function findByKey(?string $plainKey): ?self
    {
        if (!$plainKey) {
            return null;
        }

        return self::where('api_key_hash', self::hashKey($plainKey))
            ->where('is_active', true)
            ->first();
    }

    public function canAccessMatch(GameMatch $match): bool
    {
        return $this->access()
            ->where(function ($q) use ($match) {
                $q->where('match_id', $match->id);
                if ($match->tournament_id) {
                    $q->orWhere('tournament_id', $match->tournament_id);
                }
            })
            ->exists();
    }

    /**
     * Ids of matches this partner may see (direct grants + all matches of granted tournaments).
     */
    public function accessibleMatchIds(): array
    {
        $grants = $this->access()->get();

        $matchIds = $grants->pluck('match_id')->filter()->all();
        $tournamentIds = $grants->pluck('tournament_id')->filter()->all();

        if ($tournamentIds) {
            $matchIds = array_merge(
                $matchIds,
                GameMatch::whereIn('tournament_id', $tournamentIds)->pluck('id')->all()
            );
        }

        return array_values(array_unique($matchIds));
    }
}
