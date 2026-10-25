<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assetmgr_valuation_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('owner_kind', 16);
            $table->unsignedBigInteger('owner_id');
            $table->timestamp('snapshot_at')->index();
            $table->unsignedBigInteger('asset_count')->default(0);
            $table->unsignedBigInteger('quantity_sum')->default(0);
            $table->decimal('jita_total_value', 30, 2)->default(0);
            $table->decimal('regional_total_value', 30, 2)->default(0);
            $table->unsignedBigInteger('jita_unpriced_count')->default(0);
            $table->unsignedBigInteger('regional_unpriced_count')->default(0);
            $table->timestamps();
            $table->unique(['owner_kind', 'owner_id', 'snapshot_at'], 'assetmgr_valuation_owner_time_unique');
            $table->index(['owner_kind', 'owner_id', 'snapshot_at'], 'assetmgr_valuation_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assetmgr_valuation_snapshots');
    }
};
