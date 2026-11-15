<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShipNameToDivineReignSessions extends Migration
{
    public function up()
    {
        Schema::table('dr_ratting_sessions', function (Blueprint $table) {
            $table->string('ship_name', 32)->default('Unknown')->after('character_name');
        });
    }

    public function down()
    {
        Schema::table('dr_ratting_sessions', function (Blueprint $table) {
            $table->dropColumn('ship_name');
        });
    }
}
