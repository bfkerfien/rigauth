<?php

namespace Triglavian\AssetManager\Services\Assets;

use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;

class CharacterPortfolioResolver
{
    /**
     * Resolve linked characters through SeAT's native relationship.
     *
     * SeAT 5's User model exposes associatedCharacterIds(), which is backed by
     * the user's linked refresh-token records. Never infer ownership by name.
     *
     * @return list<int>
     */
    public function characterIdsFor(Authenticatable $user): array
    {
        if (! method_exists($user, 'associatedCharacterIds')) {
            throw new RuntimeException('The authenticated model does not expose SeAT character ownership.');
        }

        $ids = $user->associatedCharacterIds();
        if (! is_array($ids)) {
            throw new RuntimeException('SeAT returned an invalid character ownership list.');
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
    }
}
