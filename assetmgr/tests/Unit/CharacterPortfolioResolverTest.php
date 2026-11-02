<?php

namespace Triglavian\AssetManager\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use PHPUnit\Framework\TestCase;
use Triglavian\AssetManager\Services\Assets\CharacterPortfolioResolver;

class CharacterPortfolioResolverTest extends TestCase
{
    public function test_it_returns_unique_positive_linked_character_ids(): void
    {
        $user = new class implements Authenticatable {
            public function associatedCharacterIds(): array { return [123, 456, 123, 0, -5, '789']; }
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 1; }
            public function getAuthPassword(): string { return ''; }
            public function getRememberToken(): ?string { return null; }
            public function setRememberToken($value): void {}
            public function getRememberTokenName(): string { return 'remember_token'; }
        };

        self::assertSame([123, 456, 789], (new CharacterPortfolioResolver())->characterIdsFor($user));
    }

    public function test_it_fails_closed_when_seat_relationship_is_unavailable(): void
    {
        $user = new class implements Authenticatable {
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 1; }
            public function getAuthPassword(): string { return ''; }
            public function getRememberToken(): ?string { return null; }
            public function setRememberToken($value): void {}
            public function getRememberTokenName(): string { return 'remember_token'; }
        };

        $this->expectException(\RuntimeException::class);
        (new CharacterPortfolioResolver())->characterIdsFor($user);
    }
}
