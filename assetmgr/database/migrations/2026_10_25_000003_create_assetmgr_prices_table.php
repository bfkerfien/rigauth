<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assetmgr_prices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('region_id')->default(10000002);
            $table->string('source', 32); // e.g. esi-market-history, esi-orders, configured-provider
            $table->string('price_basis', 24); // buy, sell, average, custom
            $table->decimal('unit_price', 20, 2)->nullable();
            $table->timestamp('source_expires_at')->nullable();
            $table->timestamp('fetched_at')->index();
            $table->timestamps();
            $table->unique(['type_id', 'region_id', 'source', 'price_basis'], 'assetmgr_price_key_unique');
            $table->index(['region_id', 'type_id'], 'assetmgr_price_region_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assetmgr_prices');
    }
};
