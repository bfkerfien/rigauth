<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDivineReignRattingTables extends Migration
{
    public function up()
    {
        Schema::create('dr_ratting_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('character_id')->index();
            $table->string('character_name', 120);
            $table->string('system_name', 64)->default('MC6-5J');
            $table->dateTime('started_at')->index();
            $table->dateTime('stopped_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->decimal('asset_start_isk', 24, 2)->nullable();
            $table->decimal('asset_end_isk', 24, 2)->nullable();
            $table->decimal('asset_delta_isk', 24, 2)->nullable();
            $table->decimal('bounty_isk', 24, 2)->default(0);
            $table->decimal('total_income_isk', 24, 2)->nullable();
            $table->decimal('isk_per_hour', 24, 2)->nullable();
            $table->enum('status', ['active', 'completed'])->default('active')->index();
            $table->timestamps();
        });
        Schema::create('dr_ratting_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('session_id')->index();
            $table->unsignedBigInteger('character_id')->index();
            $table->enum('event_type', ['dread_guristas', 'hauler', 'estamel'])->index();
            $table->enum('event_action', ['spawn', 'sighting', 'kill'])->default('sighting');
            $table->string('location', 160)->nullable();
            $table->decimal('loot_isk', 24, 2)->default(0);
            $table->text('notes')->nullable();
            $table->dateTime('occurred_at')->index();
            $table->timestamps();
            $table->foreign('session_id')->references('id')->on('dr_ratting_sessions')->onDelete('cascade');
        });
        Schema::create('dr_asset_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('session_id')->index();
            $table->unsignedBigInteger('character_id')->index();
            $table->enum('phase', ['start', 'stop']);
            $table->dateTime('captured_at')->index();
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('unpriced_count')->default(0);
            $table->decimal('total_value_isk', 24, 2)->default(0);
            $table->string('source', 160);
            $table->timestamps();
            $table->foreign('session_id')->references('id')->on('dr_ratting_sessions')->onDelete('cascade');
        });
        Schema::create('dr_settings', function (Blueprint $table) {
            $table->string('setting_key', 100)->primary();
            $table->text('setting_value')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('dr_settings');
        Schema::dropIfExists('dr_asset_snapshots');
        Schema::dropIfExists('dr_ratting_events');
        Schema::dropIfExists('dr_ratting_sessions');
    }
}
