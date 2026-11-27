<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class MatchPlayer extends Model
{
    protected $fillable = [
        'match_id',
        'team_id',
        'user_id',
        'is_captain',
        'is_playing',
        'is_substitute',
        'green_card',
        'yellow_card',
        'red_card',
    ];

    protected $casts = [
        'is_captain' => 'boolean',
        'is_playing' => 'boolean',
        'is_substitute' => 'boolean',
        'green_card' => 'integer',
        'yellow_card' => 'integer',
        'red_card' => 'integer',
    ];

    /**
     * Columns that belong to the person, not to the court slot.
     */
    public const PERSONAL_COLUMNS = ['user_id', 'is_captain', 'green_card', 'yellow_card', 'red_card'];

    /**
     * Replacement: the two players trade rows. Each row is a slot, and its is_playing,
     * is_substitute, updated_at and id stay put. The incoming player therefore takes the
     * outgoing player's exact state, including their place in the revival queue
     * (1,2,3 out, 1 replaced by 4 => 4,2,3). Query builder, so updated_at is not touched.
     */
    public static function swapSlots(self $a, self $b): void
    {
        $aPersonal = $a->only(self::PERSONAL_COLUMNS);
        $bPersonal = $b->only(self::PERSONAL_COLUMNS);

        DB::table('match_players')->where('id', $a->id)->update($bPersonal);
        DB::table('match_players')->where('id', $b->id)->update($aPersonal);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function match()
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
