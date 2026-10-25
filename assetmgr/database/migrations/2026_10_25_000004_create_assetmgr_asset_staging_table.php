<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assetmgr_asset_staging', function (Blueprint $table): void {
            $table->uuid('sync_run_id');
            $table->string('owner_kind', 16);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('item_id');
            $table->json('payload');
            $table->timestamp('created_at');
            $table->primary(['sync_run_id', 'item_id']);
            $table->index(['owner_kind', 'owner_id', 'sync_run_id'], 'assetmgr_staging_owner_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assetmgr_asset_staging');
    }
};
