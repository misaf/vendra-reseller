<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Misaf\VendraReseller\Actions\ReplaceResellerUserAction;
use Misaf\VendraReseller\Actions\UpdateResellerUserEmailAction;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Actions\UpdateUserPasswordAction;
use Misaf\VendraUser\Models\User;

function resellerUserFor(Reseller $reseller, array $attributes = []): User
{
    $user = User::factory()->create([
        'tenant_id' => null,
        ...$attributes,
    ]);

    $reseller->user()->associate($user)->save();

    return $user;
}

it('updates a reseller user password and invalidates remember tokens', function (): void {
    $reseller = Reseller::factory()->create();
    $user = resellerUserFor($reseller);
    $rememberToken = $user->getRememberToken();

    resolve(UpdateUserPasswordAction::class)->execute($user, 'NewSecure123');

    $user->refresh();

    expect(Hash::check('NewSecure123', $user->password))->toBeTrue()
        ->and($user->getRememberToken())->not->toBe($rememberToken);
});

it('updates and verifies the reseller user email', function (): void {
    $reseller = Reseller::factory()->create();
    $user = resellerUserFor($reseller);

    resolve(UpdateResellerUserEmailAction::class)->execute($reseller, 'NEW@RESELLER.TEST');

    expect($user->refresh()->email)->toBe('new@reseller.test')
        ->and($user->email_verified_at)->not->toBeNull();
});

it('replaces the main account while preserving the former identity', function (): void {
    $reseller = Reseller::factory()->create();
    $formerUser = resellerUserFor($reseller);

    $replacement = resolve(ReplaceResellerUserAction::class)->execute(
        $reseller,
        'replacement',
        'replacement@reseller.test',
        'SecurePassword123',
    );

    $reseller->refresh();

    expect($reseller->user->is($replacement))->toBeTrue()
        ->and(User::query()->find($formerUser->getKey()))->not->toBeNull()
        ->and(Reseller::forUser($formerUser))->toBeNull()
        ->and($formerUser->canAccessPanel(Filament::getPanel('reseller')))->toBeFalse()
        ->and($replacement->tenant_id)->toBeNull()
        ->and(Hash::check('SecurePassword123', $replacement->password))->toBeTrue();
});
