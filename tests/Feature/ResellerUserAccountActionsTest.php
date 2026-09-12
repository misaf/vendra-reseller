<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Misaf\VendraReseller\Actions\ReplaceResellerUserAction;
use Misaf\VendraReseller\Actions\SetResellerUserAccountEnabledAction;
use Misaf\VendraReseller\Actions\UpdateResellerUserEmailAction;
use Misaf\VendraReseller\Actions\UpdateResellerUserPasswordAction;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Models\User;

function resellerUserFor(Reseller $reseller, array $attributes = []): User
{
    $user = User::factory()->create([
        'tenant_id' => null,
        ...$attributes,
    ]);

    $reseller->users()->attach($user->getKey());

    return $user;
}

function membershipTrashed(Reseller $reseller, User $user): bool
{
    return ! DB::table('reseller_users')
        ->where('reseller_id', $reseller->getKey())
        ->where('user_id', $user->getKey())
        ->whereNull('deleted_at')
        ->exists();
}

it('updates a reseller user password and invalidates remember tokens', function (): void {
    $reseller = Reseller::factory()->create();
    $user = resellerUserFor($reseller);
    $rememberToken = $user->getRememberToken();

    resolve(UpdateResellerUserPasswordAction::class)->execute($user, 'NewSecure123');

    $user->refresh();

    expect(Hash::check('NewSecure123', $user->password))->toBeTrue()
        ->and($user->getRememberToken())->not->toBe($rememberToken);
});

it('updates and verifies the user email together with the reseller contact', function (): void {
    $reseller = Reseller::factory()->create();
    $user = resellerUserFor($reseller);

    resolve(UpdateResellerUserEmailAction::class)->execute($reseller, $user, 'NEW@RESELLER.TEST');

    expect($user->refresh()->email)->toBe('new@reseller.test')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($reseller->refresh()->email)->toBe('new@reseller.test');
});

it('disables and re-enables a reseller user account reversibly', function (): void {
    $reseller = Reseller::factory()->create();
    $user = resellerUserFor($reseller);

    resolve(SetResellerUserAccountEnabledAction::class)->execute($reseller, $user, false);

    expect($reseller->user())->toBeNull()
        ->and(membershipTrashed($reseller, $user))->toBeTrue()
        ->and(User::query()->find($user->getKey()))->not->toBeNull();

    resolve(SetResellerUserAccountEnabledAction::class)->execute($reseller, $user, true);

    expect($reseller->user()?->is($user))->toBeTrue();
});

it('replaces the active reseller user while preserving the former account as history', function (): void {
    $reseller = Reseller::factory()->create();
    $formerUser = resellerUserFor($reseller);

    $replacement = resolve(ReplaceResellerUserAction::class)->execute(
        $reseller,
        'replacement',
        'replacement@reseller.test',
        'SecurePassword123',
    );

    expect(membershipTrashed($reseller, $formerUser))->toBeTrue()
        ->and(User::query()->find($formerUser->getKey()))->not->toBeNull()
        ->and($reseller->user()?->is($replacement))->toBeTrue()
        ->and(Hash::check('SecurePassword123', $replacement->password))->toBeTrue()
        ->and(fn () => resolve(SetResellerUserAccountEnabledAction::class)->execute($reseller, $formerUser, true))->toThrow(LogicException::class);
});
