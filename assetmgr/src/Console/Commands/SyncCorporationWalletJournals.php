<?php

namespace Triglavian\AssetManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Triglavian\AssetManager\Jobs\SyncCorporationWalletJournal;

class SyncCorporationWalletJournals extends Command
{
    protected $signature = 'assetmgr:sync-corporation-wallet-journals';
    protected $description = 'Queue wallet journal syncs for all divisions of allowlisted corporations';

    public function handle(): int
    {
        if (! config('assetmgr.enabled', false) || ! config('assetmgr.sync.enabled', false) || ! config('assetmgr.corporation_sync_enabled', false)) {
            $this->error('Asset Manager, synchronization, or corporation sync is disabled in configuration.');
            return self::FAILURE;
        }

        $corporationIds = array_values(array_unique(array_filter(
            array_map('intval', (array) config('assetmgr.allowed_corporation_ids', [])),
            static fn (int $id): bool => $id > 0
        )));
        if ($corporationIds === []) {
            $this->warn('No corporation IDs are allowlisted for Asset Manager sync.');
            return self::SUCCESS;
        }

        $queued = 0;
        $skipped = 0;
        foreach ($corporationIds as $corporationId) {
            for ($division = 1; $division <= 7; $division++) {
                $dataset = 'corp_wallet_journal_' . $division;
                $state = DB::table('assetmgr_sync_state')
                    ->where('owner_kind', 'corporation')
                    ->where('owner_id', $corporationId)
                    ->where('dataset', $dataset)
                    ->first();

                if ($state?->next_eligible_at && Carbon::parse($state->next_eligible_at)->isFuture()) {
                    $skipped++;
                    continue;
                }

                SyncCorporationWalletJournal::dispatch($corporationId, $division);
                $queued++;
            }
        }

        $this->info(sprintf('Queued %d corporation wallet division sync(s); skipped %d inside ESI cache cooldown.', $queued, $skipped));
        return self::SUCCESS;
    }
}
