<?php

namespace App\Console\Commands;

use App\Models\BroadcastPartner;
use App\Models\BroadcastPartnerAccess;
use Illuminate\Console\Command;

/**
 * php artisan broadcast:partner "Star Sports" --tournament=12 --match=1024
 * php artisan broadcast:partner --id=3 --tournament=15        (add access to an existing partner)
 * php artisan broadcast:partner --id=3 --rotate-key           (issue a new key, old one stops working)
 * php artisan broadcast:partner --id=3 --deactivate
 */
class CreateBroadcastPartner extends Command
{
    protected $signature = 'broadcast:partner
        {name? : Partner name (when creating)}
        {--id= : Existing partner id}
        {--tournament=* : Tournament ids the partner may see}
        {--match=* : Match ids the partner may see}
        {--rate-limit=60 : REST requests per minute}
        {--rotate-key : Issue a new API key}
        {--deactivate : Turn the partner off}';

    protected $description = 'Create or manage a broadcaster / media partner for the live feed';

    public function handle(): int
    {
        $plainKey = null;

        if ($id = $this->option('id')) {
            $partner = BroadcastPartner::find($id);
            if (!$partner) {
                $this->error("Partner {$id} not found.");
                return self::FAILURE;
            }
        } else {
            $name = $this->argument('name');
            if (!$name) {
                $this->error('Give a partner name, or --id for an existing partner.');
                return self::FAILURE;
            }
            [$partner, $plainKey] = BroadcastPartner::createWithKey($name, (int) $this->option('rate-limit'));
            $this->info("Created partner #{$partner->id}: {$partner->name}");
        }

        if ($this->option('deactivate')) {
            $partner->update(['is_active' => false]);
            $this->info('Partner deactivated.');
            return self::SUCCESS;
        }

        if ($this->option('rotate-key')) {
            $plainKey = 'kbd_' . \Illuminate\Support\Str::random(40);
            $partner->update([
                'api_key_hash' => BroadcastPartner::hashKey($plainKey),
                'api_key_prefix' => substr($plainKey, 0, 12),
                'is_active' => true,
            ]);
        }

        foreach ((array) $this->option('tournament') as $tournamentId) {
            BroadcastPartnerAccess::firstOrCreate(['partner_id' => $partner->id, 'tournament_id' => (int) $tournamentId]);
            $this->line("Access granted: tournament {$tournamentId}");
        }

        foreach ((array) $this->option('match') as $matchId) {
            BroadcastPartnerAccess::firstOrCreate(['partner_id' => $partner->id, 'match_id' => (int) $matchId]);
            $this->line("Access granted: match {$matchId}");
        }

        if ($plainKey) {
            $this->newLine();
            $this->warn('API key (shown once, store it safely and send it to the partner):');
            $this->line($plainKey);
        }

        return self::SUCCESS;
    }
}
