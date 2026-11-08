<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTcExplorationSignaturesTable extends Migration
{
    public function up()
    {
        Schema::create('tc_exploration_signatures', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('region_name', 32);
            $table->unsignedBigInteger('solar_system_id');
            $table->string('system_name', 64);
            $table->string('signature_id', 16);
            $table->string('group_name', 128)->nullable();
            $table->string('type_name', 128)->nullable();
            $table->string('signature_name', 255)->nullable();
            $table->decimal('signal_strength', 6, 2)->nullable();
            $table->text('raw_data')->nullable();
            $table->unsignedBigInteger('scanned_by')->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();
            $table->unique(['solar_system_id', 'signature_id'], 'tc_exploration_system_signature_unique');
            $table->index(['region_name', 'solar_system_id'], 'tc_exploration_region_system_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tc_exploration_signatures');
    }
}
