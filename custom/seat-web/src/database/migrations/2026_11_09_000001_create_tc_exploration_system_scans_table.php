<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTcExplorationSystemScansTable extends Migration
{
    public function up()
    {
        Schema::create('tc_exploration_system_scans', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('region_name', 32);
            $table->unsignedBigInteger('solar_system_id')->unique();
            $table->string('system_name', 64);
            $table->unsignedInteger('signature_count')->default(0);
            $table->unsignedBigInteger('scanned_by')->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();
            $table->index(['region_name', 'scanned_at'], 'tc_exploration_scans_region_date_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tc_exploration_system_scans');
    }
}
