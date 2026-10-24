<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMktMarketTables extends Migration
{
    public function up()
    {
        Schema::create('mkt_oauth_states', function(Blueprint $t){ $t->char('state_hash',64)->primary(); $t->dateTime('expires_at'); });
        Schema::create('mkt_oauth_tokens', function(Blueprint $t){ $t->unsignedTinyInteger('id')->primary(); $t->unsignedBigInteger('character_id'); $t->string('character_name',100); $t->binary('encrypted_refresh_token'); $t->json('granted_scopes'); $t->timestamps(); });
        Schema::create('mkt_collection_runs', function(Blueprint $t){ $t->bigIncrements('id'); $t->unsignedBigInteger('structure_id'); $t->dateTime('started_at'); $t->dateTime('completed_at')->nullable(); $t->enum('status',['running','complete','failed']); $t->unsignedInteger('structure_order_count')->default(0); $t->unsignedInteger('jita_order_count')->default(0); $t->unsignedInteger('item_type_count')->default(0); $t->text('error_message')->nullable(); $t->index('started_at','mkt_runs_started_idx'); $t->index(['status','started_at'],'mkt_runs_status_started_idx'); });
        Schema::create('mkt_market_orders', function(Blueprint $t){ $t->bigIncrements('id'); $t->unsignedBigInteger('run_id'); $t->enum('source',['structure','jita_44']); $t->unsignedInteger('type_id'); $t->string('type_name')->nullable(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('location_id'); $t->unsignedInteger('system_id')->nullable(); $t->boolean('is_buy_order'); $t->decimal('price',24,2); $t->unsignedBigInteger('volume_remain'); $t->unsignedBigInteger('volume_total'); $t->unsignedBigInteger('min_volume')->default(1); $t->dateTime('issued'); $t->unsignedSmallInteger('duration'); $t->string('order_range',32); $t->dateTime('collected_at'); $t->foreign('run_id','mkt_orders_run_fk')->references('id')->on('mkt_collection_runs')->onDelete('cascade'); $t->unique(['run_id','source','order_id'],'mkt_run_source_order_uq'); $t->index(['run_id','type_id','source','is_buy_order'],'mkt_run_type_source_side_idx'); $t->index('collected_at','mkt_orders_collected_idx'); $t->index(['type_id','collected_at'],'mkt_type_time_idx'); });
        Schema::create('mkt_item_comparisons', function(Blueprint $t){ $t->unsignedBigInteger('run_id'); $t->unsignedInteger('type_id'); $t->string('type_name')->nullable(); $t->decimal('structure_sell_min',24,2)->nullable(); $t->decimal('jita_sell_min',24,2)->nullable(); $t->decimal('sell_difference',24,2)->nullable(); $t->decimal('sell_difference_percent',12,4)->nullable(); $t->decimal('structure_buy_max',24,2)->nullable(); $t->decimal('jita_buy_max',24,2)->nullable(); $t->unsignedBigInteger('structure_sell_volume')->default(0); $t->unsignedBigInteger('jita_sell_volume')->default(0); $t->primary(['run_id','type_id']); $t->foreign('run_id','mkt_comparisons_run_fk')->references('id')->on('mkt_collection_runs')->onDelete('cascade'); $t->index(['type_id','run_id'],'mkt_comparison_type_idx'); });
        Schema::create('mkt_market_type_volumes', function(Blueprint $t){ $t->unsignedInteger('type_id')->primary(); $t->decimal('volume',24,6); $t->timestamps(); });
        Schema::create('mkt_shame_entries', function(Blueprint $t){ $t->bigIncrements('id'); $t->string('character_name',120); $t->unsignedBigInteger('character_id')->nullable(); $t->text('reason'); $t->string('evidence_url',2048)->nullable(); $t->enum('status',['open','resolved'])->default('open'); $t->unsignedBigInteger('reported_by_user_id')->nullable(); $t->unsignedBigInteger('resolved_by_user_id')->nullable(); $t->dateTime('resolved_at')->nullable(); $t->timestamps(); $t->index(['status','created_at'],'mkt_shame_status_created_idx'); });
        Schema::create('mkt_doctrine_health_snapshots', function(Blueprint $t){ $t->bigIncrements('id'); $t->string('doctrine',120); $t->unsignedInteger('fit_count')->default(0); $t->unsignedInteger('missing_item_count')->default(0); $t->decimal('health_percent',7,3)->nullable(); $t->json('details')->nullable(); $t->dateTime('captured_at'); $t->index(['doctrine','captured_at'],'mkt_doctrine_health_time_idx'); });
    }
    public function down()
    {
        foreach (['mkt_doctrine_health_snapshots','mkt_shame_entries','mkt_market_type_volumes','mkt_item_comparisons','mkt_market_orders','mkt_collection_runs','mkt_oauth_tokens','mkt_oauth_states'] as $table) Schema::dropIfExists($table);
    }
}
