<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assetmgr_market_orders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('owner_kind', 16)->default('character');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('character_id')->nullable()->index();
            $table->string('character_name')->nullable();
            $table->string('order_id', 64);
            $table->unsignedBigInteger('type_id')->index();
            $table->string('type_name')->nullable();
            $table->unsignedBigInteger('location_id')->index();
            $table->string('location_name')->nullable();
            $table->unsignedBigInteger('region_id')->nullable()->index();
            $table->boolean('is_buy_order')->default(false);
            $table->boolean('is_corporation_order')->default(false);
            $table->decimal('price', 30, 2);
            $table->unsignedBigInteger('volume_total')->default(0);
            $table->unsignedBigInteger('volume_remain')->default(0);
            $table->unsignedInteger('duration')->nullable();
            $table->string('order_range', 32)->nullable();
            $table->unsignedBigInteger('min_volume')->nullable();
            $table->decimal('escrow', 30, 2)->nullable();
            $table->timestamp('issued')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['owner_kind', 'owner_id', 'order_id'], 'assetmgr_market_order_unique');
            $table->index(['owner_kind', 'owner_id', 'is_buy_order', 'issued'], 'assetmgr_market_order_owner_side_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assetmgr_market_orders');
    }
};
