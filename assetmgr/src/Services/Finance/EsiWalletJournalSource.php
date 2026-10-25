<?php

namespace Triglavian\AssetManager\Services\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;

class EsiWalletJournalSource implements WalletJournalSource
{
    public function __construct(private readonly AssetAccessTokenProvider $tokens)
    {
    }

    public function fetchSince(int $characterId, ?string $lastKnownId = null): WalletJournalSnapshot
    {
        if ($characterId < 1) throw new RuntimeException('Invalid character ID for wallet journal sync.');

        $token = $this->tokens->accessTokenFor('character', $characterId, 'esi-wallet.read_character_wallet.v1');
        $baseUrl = rtrim((string) config('assetmgr.esi.base_url', 'https://esi.evetech.net'), '/');
        $url = $baseUrl . '/characters/' . $characterId . '/wallet/journal/';
        $pageCount = null;
        $expiresAt = null;
        $rowsById = [];
        $pagesFetched = 0;
        $overlapFound = false;

        for ($page = 1; $page <= ($pageCount ?? 1); $page++) {
            $response = Http::withToken($token)->withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => (string) config('assetmgr.esi.user_agent', 'Triglavian-Asset-Manager'),
                'X-Compatibility-Date' => (string) config('assetmgr.esi.compatibility_date', '2026-08-18'),
            ])->timeout(30)->retry(2, 500, throw: false)->get($url, ['page' => $page]);
            $response->throw();

            $pagesFetched++;
            $entries = $response->json();
            if (! is_array($entries) || ! array_is_list($entries)) {
                throw new RuntimeException('ESI returned an unexpected wallet journal page payload.');
            }

            $headerPageCount = (int) $response->header('X-Pages', '1');
            if ($headerPageCount < 1 || ($pageCount !== null && $headerPageCount !== $pageCount)) {
                throw new RuntimeException('ESI wallet journal page count changed during pagination.');
            }
            $pageCount = $headerPageCount;

            $expiresHeader = $response->header('Expires');
            if (is_string($expiresHeader) && $expiresHeader !== '') {
                $pageExpiry = Carbon::parse($expiresHeader);
                if ($expiresAt !== null && abs($expiresAt->diffInSeconds($pageExpiry, false)) > 5) {
                    throw new RuntimeException('ESI wallet journal cache expiry changed during pagination.');
                }
                $expiresAt = $pageExpiry;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry) || ! isset($entry['id'], $entry['date'], $entry['ref_type'])) {
                    throw new RuntimeException('ESI returned a malformed wallet journal entry.');
                }

                $id = (string) $entry['id'];
                if ($lastKnownId !== null && self::compareIds($id, $lastKnownId) <= 0) {
                    $overlapFound = true;
                }
                $rowsById[$id] = [
                    'external_id' => $id,
                    'record_at' => Carbon::parse($entry['date'])->toDateTimeString(),
                    'reference_type' => (string) $entry['ref_type'],
                    'first_party_id' => $entry['first_party_id'] ?? null,
                    'second_party_id' => $entry['second_party_id'] ?? null,
                    'amount' => isset($entry['amount']) ? number_format((float) $entry['amount'], 2, '.', '') : null,
                    'balance' => isset($entry['balance']) ? number_format((float) $entry['balance'], 2, '.', '') : null,
                    'reason' => $entry['reason'] ?? null,
                    'description' => $entry['description'] ?? null,
                    'context_id' => $entry['context_id'] ?? null,
                    'context_id_type' => $entry['context_id_type'] ?? null,
                    'payload' => json_encode($entry, JSON_THROW_ON_ERROR),
                ];
            }

            // Journal IDs increase over time. Once a page overlaps the newest locally stored ID,
            // all newer entries have been captured and older pages are already cached locally.
            if ($lastKnownId !== null && $overlapFound) break;
        }

        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo(now()->addSeconds(5))) {
            throw new RuntimeException('ESI wallet journal cache expired during pagination; retry after cache refresh.');
        }

        $characterName = DB::table('character_infos')->where('character_id', $characterId)->value('name');
        foreach ($rowsById as &$row) {
            $row['character_id'] = $characterId;
            $row['character_name'] = $characterName;
        }
        unset($row);

        return new WalletJournalSnapshot(
            characterId: $characterId,
            rows: array_values($rowsById),
            pagesReceived: $pagesFetched,
            fetchedAt: now()->toIso8601String(),
            sourceExpiresAt: $expiresAt?->toIso8601String(),
        );
    }

    private static function compareIds(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        return strlen($left) === strlen($right)
            ? strcmp($left, $right)
            : (strlen($left) <=> strlen($right));
    }
}
