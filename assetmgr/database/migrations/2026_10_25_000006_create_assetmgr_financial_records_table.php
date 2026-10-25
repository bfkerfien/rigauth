<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assetmgr_financial_records', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('owner_kind', 16);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('character_id')->nullable()->index();
            $table->string('character_name')->nullable();
            $table->string('record_kind', 32);
            $table->string('external_id', 64);
            $table->timestamp('record_at')->nullable()->index();
            $table->string('reference_type', 100)->nullable()->index();
            $table->unsignedBigInteger('type_id')->nullable()->index();
            $table->string('type_name')->nullable();
            $table->unsignedBigInteger('location_id')->nullable()->index();
            $table->string('location_name')->nullable();
            $table->unsignedBigInteger('quantity')->nullable();
            $table->decimal('unit_price', 30, 2)->nullable();
            $table->boolean('is_buy')->nullable();
            $table->unsignedBigInteger('first_party_id')->nullable()->index();
            $table->unsignedBigInteger('second_party_id')->nullable()->index();
            $table->decimal('amount', 30, 2)->nullable();
            $table->decimal('balance', 30, 2)->nullable();
            $table->text('reason')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('context_id_type', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['owner_kind', 'owner_id', 'record_kind', 'external_id'], 'assetmgr_financial_record_unique');
            $table->index(['owner_kind', 'owner_id', 'record_kind', 'record_at'], 'assetmgr_financial_owner_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assetmgr_financial_records');
    }
};
