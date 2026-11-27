<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchBroadcastState extends Model
{
    public const MODE_AUTO = 'auto';
    public const MODE_MANUAL = 'manual';

    protected $fillable = [
        'match_id',
        'enabled',
        'mode',
        'current_version',
        'last_published_at',
        'published_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'current_version' => 'integer',
        'last_published_at' => 'datetime',
    ];

    public function match()
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function isAuto(): bool
    {
        return $this->mode === self::MODE_AUTO;
    }
}
