<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateTcExplorationAsteroidSitesTable extends Migration
{
    public function up()
    {
        Schema::create('tc_exploration_asteroid_sites', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('solar_system_id');
            $table->string('system_name', 64);
            $table->string('site_name', 128);
            $table->string('site_category', 64)->default('Asteroid Site');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['solar_system_id', 'site_name'], 'tc_exploration_asteroid_system_site_unique');
            $table->index('solar_system_id', 'tc_exploration_asteroid_system_idx');
        });

        // Initial manually confirmed asteroid sites for Q-4DEC.
        // Resolve the system ID from the imported EVE SDE rather than hard-coding it.
        $system = DB::table('mapDenormalize')
            ->where('groupID', 5)
            ->where('itemName', 'Q-4DEC')
            ->first(['itemID', 'itemName']);

        if ($system) {
            $now = now();
            foreach (['Enormous Mercoxit', 'Large Mordunium'] as $siteName) {
                DB::table('tc_exploration_asteroid_sites')->insertOrIgnore([
                    'solar_system_id' => (int) $system->itemID,
                    'system_name' => (string) $system->itemName,
                    'site_name' => $siteName,
                    'site_category' => 'Asteroid Site',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('tc_exploration_asteroid_sites');
    }
}
