<?php

namespace Triglavian\AssetManager\Services\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;
use Triglavian\AssetManager\Services\Assets\AssetMetadataResolver;

class EsiWalletTransactionSource implements WalletTransactionSource
{
    private const PAGE_SIZE = 2500;

    public function __construct(
        private readonly AssetAccessTokenProvider $tokens,
        private readonly AssetMetadataResolver $metadata,
    ) {
    }

    public function fetchSince(int $characterId, ?string $lastKnownId = null): WalletTransactionSnapshot
    {
        if ($characterId < 1) throw new RuntimeException('Invalid character ID for wallet transaction sync.');

        $token = $this->tokens->accessTokenFor('character', $characterId, 'esi-wallet.read_character_wallet.v1');
        $baseUrl = rtrim((string) config('assetmgr.esi.base_url', 'https://esi.evetech.net'), '/');
        $url = $baseUrl . '/characters/' . $characterId . '/wallet/transactions/';
        $cursor = null;
        $requestsFetched = 0;
        $expiresAt = null;
        $rowsById = [];
        $overlapFound = false;

        while (true) {
            $query = [];
            if ($cursor !== null) $query['from_id'] = $cursor;

            $response = Http::withToken($token)->withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => (string) config('assetmgr.esi.user_agent', 'Triglavian-Asset-Manager'),
                'X-Compatibility-Date' => (string) config('assetmgr.esi.compatibility_date', '2026-08-18'),
            ])->timeout(30)->retry(2, 500, throw: false)->get($url, $query);
            $response->throw();
            $requestsFetched++;

            $entries = $response->json();
            if (! is_array($entries) || ! array_is_list($entries)) {
                throw new RuntimeException('ESI returned an unexpected wallet transaction payload.');
            }

            $expiresHeader = $response->header('Expires');
            if (is_string($expiresHeader) && $expiresHeader !== '') {
                $pageExpiry = Carbon::parse($expiresHeader);
                if ($expiresAt !== null && abs($expiresAt->diffInSeconds($pageExpiry, false)) > 5) {
                    throw new RuntimeException('ESI wallet transaction cache expiry changed during pagination.');
                }
                $expiresAt = $pageExpiry;
            }

            if ($entries === []) break;

            $batchIds = [];
            foreach ($entries as $entry) {
                if (! is_array($entry) || ! isset(
                    $entry['transaction_id'],
                    $entry['date'],
                    $entry['type_id'],
                    $entry['location_id'],
                    $entry['quantity'],
                    $entry['unit_price'],
                    $entry['is_buy']
                )) {
                    throw new RuntimeException('ESI returned a malformed wallet transaction.');
                }

                $id = (string) $entry['transaction_id'];
                $batchIds[] = $id;
                if ($lastKnownId !== null && self::compareIds($id, $lastKnownId) <= 0) {
                    $overlapFound = true;
                }

                $quantity = max(0, (int) $entry['quantity']);
                $unitPrice = (float) $entry['unit_price'];
                $isBuy = (bool) $entry['is_buy'];
                $amount = $unitPrice * $quantity * ($isBuy ? -1 : 1);
                $rowsById[$id] = [
                    'external_id' => $id,
                    'record_at' => Carbon::parse($entry['date'])->toDateTimeString(),
                    'reference_type' => $isBuy ? 'market_buy' : 'market_sell',
                    'first_party_id' => $entry['client_id'] ?? null,
                    'second_party_id' => null,
                    'amount' => number_format($amount, 2, '.', ''),
                    'balance' => null,
                    'reason' => $isBuy ? 'Buy' : 'Sell',
                    'description' => null,
                    'context_id' => $entry['journal_ref_id'] ?? null,
                    'context_id_type' => 'journal_ref_id',
                    'type_id' => (int) $entry['type_id'],
                    'type_name' => null,
                    'location_id' => (int) $entry['location_id'],
                    'location_name' => null,
                    'quantity' => $quantity,
                    'unit_price' => number_format($unitPrice, 2, '.', ''),
                    'is_buy' => $isBuy,
                    'payload' => json_encode($entry, JSON_THROW_ON_ERROR),
                ];
            }

            if ($lastKnownId !== null && $overlapFound) break;
            if (count($entries) < self::PAGE_SIZE) break;

            $oldestId = $batchIds[0];
            foreach ($batchIds as $id) {
                if (self::compareIds($id, $oldestId) < 0) $oldestId = $id;
            }

            if ($cursor !== null && self::compareIds($oldestId, $cursor) >= 0) {
                throw new RuntimeException('ESI wallet transaction pagination made no progress.');
            }

            $cursor = $oldestId;
        }

        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo(now()->addSeconds(5))) {
            throw new RuntimeException('ESI wallet transaction cache expired during pagination; retry after cache refresh.');
        }

        $metadataRows = array_map(static fn (array $row): array => [
            'item_id' => (int) $row['external_id'],
            'type_id' => $row['type_id'],
            'quantity' => $row['quantity'],
            'location_id' => $row['location_id'],
            'location_type' => null,
        ], array_values($rowsById));
        $resolvedRows = $this->metadata->resolve('character', $characterId, $metadataRows);
        $metadataById = [];
        foreach ($resolvedRows as $row) $metadataById[(string) $row['item_id']] = $row;

        foreach ($rowsById as $id => &$row) {
            $resolved = $metadataById[$id] ?? [];
            $row['type_name'] = $resolved['type_name'] ?? ('Type ' . $row['type_id']);
            $row['location_name'] = $resolved['location_name'] ?? null;
            $row['character_id'] = $characterId;
            $row['character_name'] = $resolved['character_name'] ?? null;
        }
        unset($row);

        return new WalletTransactionSnapshot(
            characterId: $characterId,
            rows: array_values($rowsById),
            requestsFetched: $requestsFetched,
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
