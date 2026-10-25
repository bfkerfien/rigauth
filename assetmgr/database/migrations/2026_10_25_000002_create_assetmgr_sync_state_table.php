<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assetmgr_sync_state', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('owner_kind', 16);
            $table->unsignedBigInteger('owner_id');
            $table->string('dataset', 32)->default('assets');
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_success_at')->nullable()->index();
            $table->timestamp('next_eligible_at')->nullable()->index();
            $table->unsignedInteger('http_status')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('pages_received')->default(0);
            $table->unsignedInteger('records_received')->default(0);
            $table->timestamps();
            $table->unique(['owner_kind', 'owner_id', 'dataset'], 'assetmgr_sync_owner_dataset_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assetmgr_sync_state');
    }
};
