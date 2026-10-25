<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assetmgr_contracts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('owner_kind', 16)->index();
            $table->unsignedBigInteger('owner_id')->index();
            $table->unsignedBigInteger('character_id')->nullable()->index();
            $table->string('character_name')->nullable();
            $table->unsignedBigInteger('corporation_id')->nullable()->index();
            $table->string('corporation_name')->nullable();
            $table->string('contract_id', 64);
            $table->unsignedBigInteger('issuer_id')->nullable()->index();
            $table->unsignedBigInteger('issuer_corporation_id')->nullable()->index();
            $table->unsignedBigInteger('assignee_id')->nullable()->index();
            $table->unsignedBigInteger('acceptor_id')->nullable();
            $table->string('contract_type', 32)->nullable()->index();
            $table->string('availability', 32)->nullable()->index();
            $table->string('status', 32)->nullable()->index();
            $table->string('title', 255)->nullable();
            $table->boolean('for_corporation')->default(false);
            $table->unsignedBigInteger('start_location_id')->nullable()->index();
            $table->unsignedBigInteger('end_location_id')->nullable()->index();
            $table->timestamp('date_issued')->nullable()->index();
            $table->timestamp('date_expired')->nullable()->index();
            $table->timestamp('date_accepted')->nullable();
            $table->timestamp('date_completed')->nullable();
            $table->unsignedInteger('days_to_complete')->nullable();
            $table->decimal('price', 30, 2)->nullable();
            $table->decimal('reward', 30, 2)->nullable();
            $table->decimal('collateral', 30, 2)->nullable();
            $table->decimal('buyout', 30, 2)->nullable();
            $table->decimal('volume', 30, 2)->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['owner_kind', 'owner_id', 'contract_id'], 'assetmgr_contract_owner_unique');
            $table->index(['owner_kind', 'owner_id', 'status', 'date_issued'], 'assetmgr_contract_owner_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assetmgr_contracts');
    }
};
