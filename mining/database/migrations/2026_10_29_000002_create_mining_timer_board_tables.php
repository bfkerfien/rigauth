<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMiningTimerBoardTables extends Migration
{
    public function up()
    {
        Schema::create('mining_timers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('corporation_id')->nullable()->index();
            $table->string('title', 160);
            $table->string('system_name', 120)->index();
            $table->string('anomaly_name', 190)->nullable();
            $table->dateTime('started_at')->index();
            $table->dateTime('expires_at')->index();
            $table->string('status', 24)->default('active')->index();
            $table->string('notes', 1000)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->index();
            $table->timestamps();
        });
        Schema::create('mining_anomalies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('corporation_id')->nullable()->index();
            $table->string('system_name', 120)->index();
            $table->string('anomaly_name', 190);
            $table->text('scan_text')->nullable();
            $table->string('status', 24)->default('present')->index();
            $table->dateTime('first_seen_at')->index();
            $table->dateTime('last_seen_at')->index();
            $table->unsignedBigInteger('created_by_user_id')->index();
            $table->timestamps();
            $table->index(['system_name', 'anomaly_name', 'status'], 'mining_anomaly_system_name_status_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('mining_anomalies');
        Schema::dropIfExists('mining_timers');
    }
}
