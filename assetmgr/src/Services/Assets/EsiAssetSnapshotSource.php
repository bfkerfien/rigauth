<?php

namespace Triglavian\AssetManager\Services\Assets;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EsiAssetSnapshotSource implements AssetSnapshotSource
{
    public function __construct(private readonly AssetAccessTokenProvider $tokens)
    {
    }

    public function fetchComplete(string $ownerKind, int $ownerId): CompleteAssetSnapshot
    {
        if (! in_array($ownerKind, ['character', 'corporation'], true) || $ownerId < 1) {
            throw new RuntimeException('Invalid owner requested for ESI asset sync.');
        }

        $token = $this->tokens->accessTokenFor($ownerKind, $ownerId);
        if ($token === '') {
            throw new RuntimeException('SeAT did not provide an access token for asset sync.');
        }

        $baseUrl = rtrim((string) config('assetmgr.esi.base_url', 'https://esi.evetech.net/latest'), '/');
        $path = $ownerKind === 'character'
            ? '/characters/' . $ownerId . '/assets/'
            : '/corporations/' . $ownerId . '/assets/';

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => (string) config('assetmgr.esi.user_agent', 'Triglavian-Asset-Manager'),
        ];

        $rowsByItemId = [];
        $pageCount = null;
        $expiresAt = null;

        for ($page = 1; $page <= ($pageCount ?? 1); $page++) {
            $response = Http::withToken($token)
                ->withHeaders($headers)
                ->timeout(30)
                ->retry(2, 500, throw: false)
                ->get($baseUrl . $path, ['page' => $page]);

            $response->throw();

            $pageRows = $response->json();
            if (! is_array($pageRows) || ! array_is_list($pageRows)) {
                throw new RuntimeException('ESI returned an unexpected asset page payload.');
            }

            $headerPageCount = (int) $response->header('X-Pages', '1');
            if ($headerPageCount < 1 || ($pageCount !== null && $headerPageCount !== $pageCount)) {
                throw new RuntimeException('ESI page count changed during asset synchronization.');
            }
            $pageCount = $headerPageCount;

            $expiresHeader = $response->header('Expires');
            if (is_string($expiresHeader) && $expiresHeader !== '') {
                $pageExpiry = Carbon::parse($expiresHeader);
                if ($expiresAt !== null && abs($expiresAt->diffInSeconds($pageExpiry, false)) > 5) {
                    throw new RuntimeException('ESI cache expiry changed during asset pagination.');
                }
                $expiresAt = $pageExpiry;
            }

            foreach ($pageRows as $row) {
                if (! is_array($row) || ! isset($row['item_id'], $row['type_id'], $row['location_id'])) {
                    throw new RuntimeException('ESI returned an asset without required identifiers.');
                }
                $rowsByItemId[(string) $row['item_id']] = [
                    'item_id' => (int) $row['item_id'],
                    'type_id' => (int) $row['type_id'],
                    'location_id' => (int) $row['location_id'],
                    'location_flag' => $row['location_flag'] ?? null,
                    'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                    'parent_item_id' => ($row['location_type'] ?? null) === 'item' ? (int) $row['location_id'] : null,
                    'character_id' => $ownerKind === 'character' ? $ownerId : null,
                    'corporation_id' => $ownerKind === 'corporation' ? $ownerId : null,
                ];
            }
        }

        // ESI data is cache-backed; if the cache expired while pages were fetched,
        // refuse to reconcile a possibly mixed-time snapshot.
        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo(now()->addSeconds(5))) {
            throw new RuntimeException('ESI asset cache expired during pagination; retry after the cache refreshes.');
        }

        return new CompleteAssetSnapshot(
            ownerKind: $ownerKind,
            ownerId: $ownerId,
            rows: array_values($rowsByItemId),
            pagesReceived: $pageCount ?? 1,
            fetchedAt: now()->toIso8601String(),
            allPagesFetched: true,
            sourceExpiresAt: $expiresAt?->toIso8601String(),
        );
    }
}
