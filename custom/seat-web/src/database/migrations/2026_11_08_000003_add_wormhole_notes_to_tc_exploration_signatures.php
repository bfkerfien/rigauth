<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

class AddWormholeNotesToTcExplorationSignatures extends Migration
{
    public function up()
    {
        Schema::table('tc_exploration_signatures', function (Blueprint $table) {
            $table->string('wormhole_type', 64)->nullable();
            $table->string('connection_system', 128)->nullable();
            $table->text('wormhole_exits')->nullable();
        });
    }

    public function down()
    {
        Schema::table('tc_exploration_signatures', function (Blueprint $table) {
            $table->dropColumn(['wormhole_type', 'connection_system', 'wormhole_exits']);
        });
    }
}
