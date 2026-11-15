<?php

namespace Triglavian\AssetManager\Services\Contracts;

interface ContractSource
{
    public function fetchCharacterContracts(int $characterId): ContractSnapshot;
}
