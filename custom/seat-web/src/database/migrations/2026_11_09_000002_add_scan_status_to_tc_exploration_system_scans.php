<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddScanStatusToTcExplorationSystemScans extends Migration
{
    public function up()
    {
        if (Schema::hasTable('tc_exploration_system_scans') &&
            !Schema::hasColumn('tc_exploration_system_scans', 'scan_status')) {
            Schema::table('tc_exploration_system_scans', function (Blueprint $table) {
                $table->string('scan_status', 32)->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('tc_exploration_system_scans') &&
            Schema::hasColumn('tc_exploration_system_scans', 'scan_status')) {
            Schema::table('tc_exploration_system_scans', function (Blueprint $table) {
                $table->dropColumn('scan_status');
            });
        }
    }
}
