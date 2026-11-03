<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDivineReignExplorationTables extends Migration
{
    public function up()
    {
        Schema::create('dr_exploration_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('character_id')->index();
            $table->string('character_name', 120);
            $table->string('system_name', 64)->nullable();
            $table->dateTime('started_at')->index();
            $table->dateTime('stopped_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->enum('status', ['active', 'completed'])->default('active')->index();
            $table->timestamps();
        });
        Schema::create('dr_exploration_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('session_id')->index();
            $table->longText('inventory_text');
            $table->longText('inventory_json');
            $table->dateTime('captured_at')->index();
            $table->timestamps();
            $table->foreign('session_id')->references('id')->on('dr_exploration_sessions')->onDelete('cascade');
        });
        Schema::create('dr_exploration_sites', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('session_id')->index();
            $table->string('site_name', 180);
            $table->dateTime('recorded_at')->index();
            $table->timestamps();
            $table->foreign('session_id')->references('id')->on('dr_exploration_sessions')->onDelete('cascade');
        });
        Schema::create('dr_exploration_loot', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('site_id')->index();
            $table->string('item_name', 180);
            $table->unsignedInteger('type_id')->nullable()->index();
            $table->unsignedBigInteger('quantity')->default(0);
            $table->timestamps();
            $table->foreign('site_id')->references('id')->on('dr_exploration_sites')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('dr_exploration_loot');
        Schema::dropIfExists('dr_exploration_sites');
        Schema::dropIfExists('dr_exploration_snapshots');
        Schema::dropIfExists('dr_exploration_sessions');
    }
}
