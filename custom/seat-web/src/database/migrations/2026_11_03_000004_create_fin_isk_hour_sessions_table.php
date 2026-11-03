<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

class CreateFinIskHourSessionsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('fin_isk_hour_sessions')) {
            return;
        }

        Schema::create('fin_isk_hour_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('started_at');
            $table->dateTime('stopped_at')->nullable();
            $table->unsignedBigInteger('telemetry_units')->default(0);
            $table->unsignedInteger('corp_ticks_count')->default(0);
            $table->decimal('corp_ticks_isk', 20, 2)->default(0);
            $table->decimal('telemetry_value_isk', 20, 2)->default(0);
            $table->decimal('srp_expenses_isk', 20, 2)->default(0);
            $table->decimal('character_expenses_isk', 20, 2)->default(0);
            $table->decimal('net_isk', 22, 2)->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->decimal('isk_per_hour', 24, 2)->default(0);
            $table->timestamps();
            $table->index(['started_at', 'stopped_at'], 'fin_isk_hour_sessions_period_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fin_isk_hour_sessions');
    }
}
