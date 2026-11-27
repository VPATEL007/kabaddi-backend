<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BroadcastPartnerAccess extends Model
{
    protected $table = 'broadcast_partner_access';

    protected $fillable = [
        'partner_id',
        'tournament_id',
        'match_id',
    ];

    public function partner()
    {
        return $this->belongsTo(BroadcastPartner::class, 'partner_id');
    }
}
