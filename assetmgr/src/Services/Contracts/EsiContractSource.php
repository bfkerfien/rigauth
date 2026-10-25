<?php

namespace Triglavian\AssetManager\Services\Contracts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Triglavian\AssetManager\Services\Assets\AssetAccessTokenProvider;

class EsiContractSource implements ContractSource
{
    public function __construct(private readonly AssetAccessTokenProvider $tokens)
    {
    }

    public function fetchCharacterContracts(int $characterId): ContractSnapshot
    {
        if ($characterId < 1) throw new RuntimeException('Invalid character ID for contract sync.');

        $token = $this->tokens->accessTokenFor('character', $characterId, 'esi-contracts.read_character_contracts.v1');
        $baseUrl = rtrim((string) config('assetmgr.esi.base_url', 'https://esi.evetech.net'), '/');
        $url = $baseUrl . '/characters/' . $characterId . '/contracts/';
        $pageCount = null;
        $expiresAt = null;
        $rowsById = [];

        for ($page = 1; $page <= ($pageCount ?? 1); $page++) {
            $response = Http::withToken($token)->withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => (string) config('assetmgr.esi.user_agent', 'Triglavian-Asset-Manager'),
                'X-Compatibility-Date' => (string) config('assetmgr.esi.compatibility_date', '2026-08-18'),
            ])->timeout(30)->retry(2, 500, throw: false)->get($url, ['page' => $page]);
            $response->throw();

            $entries = $response->json();
            if (! is_array($entries) || ! array_is_list($entries)) {
                throw new RuntimeException('ESI returned an unexpected contract page payload.');
            }

            $headerPageCount = (int) $response->header('X-Pages', '1');
            if ($headerPageCount < 1 || ($pageCount !== null && $headerPageCount !== $pageCount)) {
                throw new RuntimeException('ESI contract page count changed during pagination.');
            }
            $pageCount = $headerPageCount;

            $expiresHeader = $response->header('Expires');
            if (is_string($expiresHeader) && $expiresHeader !== '') {
                $pageExpiry = Carbon::parse($expiresHeader);
                if ($expiresAt !== null && abs($expiresAt->diffInSeconds($pageExpiry, false)) > 5) {
                    throw new RuntimeException('ESI contract cache expiry changed during pagination.');
                }
                $expiresAt = $pageExpiry;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry) || ! isset($entry['contract_id'], $entry['issuer_id'], $entry['type'], $entry['status'])) {
                    throw new RuntimeException('ESI returned a malformed contract.');
                }

                // Corporate contracts are synced through the corporation endpoint, never as personal contracts.
                if ((bool) ($entry['for_corporation'] ?? false)) continue;

                $id = (string) $entry['contract_id'];
                $rowsById[$id] = [
                    'contract_id' => $id,
                    'issuer_id' => $entry['issuer_id'] ?? null,
                    'issuer_corporation_id' => $entry['issuer_corporation_id'] ?? null,
                    'assignee_id' => $entry['assignee_id'] ?? null,
                    'acceptor_id' => $entry['acceptor_id'] ?? null,
                    'contract_type' => (string) $entry['type'],
                    'availability' => $entry['availability'] ?? null,
                    'status' => (string) $entry['status'],
                    'title' => $entry['title'] ?? null,
                    'for_corporation' => false,
                    'start_location_id' => $entry['start_location_id'] ?? null,
                    'end_location_id' => $entry['end_location_id'] ?? null,
                    'date_issued' => isset($entry['date_issued']) ? Carbon::parse($entry['date_issued'])->toDateTimeString() : null,
                    'date_expired' => isset($entry['date_expired']) ? Carbon::parse($entry['date_expired'])->toDateTimeString() : null,
                    'date_accepted' => isset($entry['date_accepted']) ? Carbon::parse($entry['date_accepted'])->toDateTimeString() : null,
                    'date_completed' => isset($entry['date_completed']) ? Carbon::parse($entry['date_completed'])->toDateTimeString() : null,
                    'days_to_complete' => isset($entry['days_to_complete']) ? (int) $entry['days_to_complete'] : null,
                    'price' => isset($entry['price']) ? number_format((float) $entry['price'], 2, '.', '') : null,
                    'reward' => isset($entry['reward']) ? number_format((float) $entry['reward'], 2, '.', '') : null,
                    'collateral' => isset($entry['collateral']) ? number_format((float) $entry['collateral'], 2, '.', '') : null,
                    'buyout' => isset($entry['buyout']) ? number_format((float) $entry['buyout'], 2, '.', '') : null,
                    'volume' => isset($entry['volume']) ? number_format((float) $entry['volume'], 2, '.', '') : null,
                ];
            }
        }

        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo(now()->addSeconds(5))) {
            throw new RuntimeException('ESI contract cache expired during pagination; retry after cache refresh.');
        }

        $characterName = DB::table('character_infos')->where('character_id', $characterId)->value('name');
        foreach ($rowsById as &$row) {
            $row['character_id'] = $characterId;
            $row['character_name'] = $characterName;
            $row['corporation_id'] = null;
            $row['corporation_name'] = null;
        }
        unset($row);

        return new ContractSnapshot(
            characterId: $characterId,
            rows: array_values($rowsById),
            pagesReceived: $pageCount ?? 1,
            fetchedAt: now()->toIso8601String(),
            sourceExpiresAt: $expiresAt?->toIso8601String(),
            allPagesFetched: true,
        );
    }
}
