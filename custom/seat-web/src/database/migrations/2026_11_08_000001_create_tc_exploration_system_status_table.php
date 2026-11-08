<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTcExplorationSystemStatusTable extends Migration
{
    public function up()
    {
        Schema::create('tc_exploration_system_status', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('region_name', 32);
            $table->unsignedBigInteger('solar_system_id');
            $table->string('system_name', 64);
            $table->unsignedBigInteger('marked_by')->nullable();
            $table->timestamps();

            $table->unique(['region_name', 'solar_system_id'], 'tc_exploration_region_system_unique');
            $table->index('marked_by');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tc_exploration_system_status');
    }
}
