<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class EnsureWormholeNoteColumns extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('tc_exploration_signatures')) {
            return;
        }

        Schema::table('tc_exploration_signatures', function (Blueprint $table) {
            if (!Schema::hasColumn('tc_exploration_signatures', 'wormhole_type')) {
                $table->string('wormhole_type', 64)->nullable();
            }
            if (!Schema::hasColumn('tc_exploration_signatures', 'connection_system')) {
                $table->string('connection_system', 128)->nullable();
            }
            if (!Schema::hasColumn('tc_exploration_signatures', 'wormhole_exits')) {
                $table->text('wormhole_exits')->nullable();
            }
        });
    }

    public function down()
    {
        // Intentionally non-destructive: these columns may have been created
        // by the earlier notes migration or repaired on an existing install.
    }
}
