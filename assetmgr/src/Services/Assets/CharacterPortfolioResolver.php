<?php

namespace Triglavian\AssetManager\Services\Assets;

use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;

class CharacterPortfolioResolver
{
    /**
     * Resolve characters through SeAT's actual user/character relation.
     *
     * This is intentionally an integration seam, not a guessed implementation:
     * SeAT model and relationship names vary by version and must be verified
     * against the deployed source before this service can return any IDs.
     *
     * @return list<int>
     */
    public function characterIdsFor(Authenticatable $user): array
    {
        throw new RuntimeException(
            'CharacterPortfolioResolver must be wired to the installed SeAT user/character relationship before enabling asset access.'
        );
    }
}
