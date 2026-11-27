<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Broadcasters / media partners who receive the live feed.
        Schema::create('broadcast_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('api_key_hash', 64)->unique();
            $table->string('api_key_prefix', 12);
            $table->unsignedInteger('rate_limit')->default(60); // requests per minute on REST
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Which tournaments / matches a partner may see. One of the two ids is set.
        Schema::create('broadcast_partner_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('broadcast_partners')->cascadeOnDelete();
            $table->unsignedBigInteger('tournament_id')->nullable()->index();
            $table->unsignedBigInteger('match_id')->nullable()->index();
            $table->timestamps();
        });

        // Per-match feed switch and version counter.
        Schema::create('match_broadcast_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('match_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('mode', 10)->default('auto'); // auto | manual
            $table->unsignedInteger('current_version')->default(0);
            $table->timestamp('last_published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamps();
        });

        // Every published version of a match feed.
        Schema::create('match_feed_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('match_id');
            $table->unsignedInteger('version');
            $table->string('event', 40);
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['match_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_feed_snapshots');
        Schema::dropIfExists('match_broadcast_states');
        Schema::dropIfExists('broadcast_partner_access');
        Schema::dropIfExists('broadcast_partners');
    }
};
