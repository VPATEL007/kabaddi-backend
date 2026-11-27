<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchFeedSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'match_id',
        'version',
        'event',
        'payload',
    ];

    protected $casts = [
        'version' => 'integer',
        'payload' => 'array',
    ];
}
