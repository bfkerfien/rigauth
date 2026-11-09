<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTcExplorationCynoInfrastructureTable extends Migration
{
    public function up()
    {
        Schema::create('tc_exploration_cyno_infrastructure', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('region_name', 32);
            $table->unsignedBigInteger('solar_system_id')->unique();
            $table->string('system_name', 64);
            $table->boolean('cyno_generator')->default(false);
            $table->boolean('cyno_jammer')->default(false);
            $table->unsignedBigInteger('marked_by')->nullable();
            $table->timestamps();
            $table->index('region_name', 'tc_exploration_cyno_region_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tc_exploration_cyno_infrastructure');
    }
}
