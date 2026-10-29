<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMiningLedgerTables extends Migration
{
    public function up()
    {
        Schema::create('mining_operations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('corporation_id')->nullable()->index();
            $table->string('name', 160);
            $table->string('system_name', 120);
            $table->string('location_name', 190)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->unsignedBigInteger('created_by_user_id')->index();
            $table->timestamps();
            $table->index(['system_name', 'started_at']);
        });

        Schema::create('mining_operation_members', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('operation_id');
            $table->unsignedBigInteger('character_id')->index();
            $table->string('character_name', 120);
            $table->timestamps();
            $table->unique(['operation_id', 'character_id'], 'mining_member_operation_character_unique');
        });

        Schema::create('mining_imports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('filename', 255);
            $table->char('sha256', 64)->unique();
            $table->unsignedBigInteger('uploaded_by_user_id')->index();
            $table->unsignedInteger('rows_imported')->default(0);
            $table->string('status', 24)->default('completed');
            $table->text('error_summary')->nullable();
            $table->timestamps();
        });

        Schema::create('mining_yields', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('operation_id')->nullable()->index();
            $table->unsignedBigInteger('corporation_id')->nullable()->index();
            $table->unsignedBigInteger('character_id')->index();
            $table->string('character_name', 120);
            $table->unsignedBigInteger('type_id')->index();
            $table->string('type_name', 190);
            $table->unsignedBigInteger('quantity');
            $table->string('source_method', 24)->default('manual');
            $table->string('source_reference', 255)->nullable();
            $table->unsignedBigInteger('import_id')->nullable()->index();
            $table->dateTime('recorded_at')->index();
            $table->decimal('unit_price_isk', 20, 2)->nullable();
            $table->decimal('estimated_value_isk', 24, 2)->nullable();
            $table->string('verification_status', 32)->default('reported');
            $table->unsignedBigInteger('created_by_user_id')->index();
            $table->timestamps();
            $table->index(['character_id', 'recorded_at']);
            $table->index(['corporation_id', 'recorded_at']);
        });

        Schema::create('mining_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('type_id')->primary();
            $table->decimal('jita_sell_isk', 20, 2)->nullable();
            $table->dateTime('retrieved_at')->nullable();
            $table->string('source', 40)->default('esi_region_orders');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('mining_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('action', 60);
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('mining_audit_logs');
        Schema::dropIfExists('mining_prices');
        Schema::dropIfExists('mining_yields');
        Schema::dropIfExists('mining_imports');
        Schema::dropIfExists('mining_operation_members');
        Schema::dropIfExists('mining_operations');
    }
}
