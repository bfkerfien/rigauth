<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTriglavianFittingsTables extends Migration
{
    public function up()
    {
        Schema::create('tc_t2_stations', function (Blueprint $table) {
            $table->increments('id');
            $table->string('station_name');
            $table->string('solar_system_name');
            $table->unsignedBigInteger('eve_station_id')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('tc_fittings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('hull');
            $table->string('category')->default('other');
            $table->longText('fitting_text')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('category');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tc_fittings');
        Schema::dropIfExists('tc_t2_stations');
    }
}
