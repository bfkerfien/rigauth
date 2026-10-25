<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assetmgr_assets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('owner_kind', 16)->index(); // character | corporation
            $table->unsignedBigInteger('owner_id')->index();
            $table->unsignedBigInteger('character_id')->nullable()->index();
            $table->string('character_name')->nullable()->index();
            $table->unsignedBigInteger('corporation_id')->nullable()->index();
            $table->unsignedBigInteger('item_id')->index();
            $table->unsignedBigInteger('type_id')->index();
            $table->string('type_name')->index();
            $table->unsignedBigInteger('quantity')->default(1);
            $table->unsignedBigInteger('location_id')->index();
            $table->string('location_name')->nullable()->index();
            $table->string('location_flag', 64)->nullable();
            $table->string('location_type', 32)->nullable()->index();
            $table->unsignedBigInteger('parent_item_id')->nullable()->index();
            $table->decimal('jita_unit_price', 20, 2)->nullable();
            $table->decimal('regional_unit_price', 20, 2)->nullable();
            $table->unsignedBigInteger('regional_region_id')->nullable()->index();
            $table->timestamp('price_updated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['owner_kind', 'owner_id', 'item_id'],
                'assetmgr_owner_item_unique'
            );
            $table->index(['owner_kind', 'owner_id', 'character_id'], 'assetmgr_owner_character_idx');
            $table->index(['type_id', 'location_id'], 'assetmgr_type_location_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assetmgr_assets');
    }
};
