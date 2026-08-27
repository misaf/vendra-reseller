<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use LogicException;
use Misaf\VendraReseller\Actions\ReplaceResellerOwnerAction;
use Misaf\VendraReseller\Actions\SetResellerOwnerAccountEnabledAction;
use Misaf\VendraReseller\Actions\UpdateResellerOwnerEmailAction;
use Misaf\VendraReseller\Actions\UpdateResellerOwnerPasswordAction;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Models\ResellerUser;

it('updates a reseller owner password and invalidates remember tokens', function (): void {
    $reseller = Reseller::factory()->create();
    $owner = ResellerUser::factory()->forReseller($reseller)->create();
    $rememberToken = $owner->getRememberToken();

    app(UpdateResellerOwnerPasswordAction::class)->execute($owner, 'NewSecure123');

    $owner->refresh();

    expect(Hash::check('NewSecure123', $owner->password))->toBeTrue()
        ->and($owner->getRememberToken())->not->toBe($rememberToken);
});

it('updates and verifies the owner email together with the reseller contact', function (): void {
    $reseller = Reseller::factory()->create();
    $owner = ResellerUser::factory()->forReseller($reseller)->create();

    app(UpdateResellerOwnerEmailAction::class)->execute($owner, 'NEW@RESELLER.TEST');

    expect($owner->refresh()->email)->toBe('new@reseller.test')
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and($reseller->refresh()->email)->toBe('new@reseller.test');
});

it('disables and re-enables a reseller owner account reversibly', function (): void {
    $reseller = Reseller::factory()->create();
    $owner = ResellerUser::factory()->forReseller($reseller)->create();

    app(SetResellerOwnerAccountEnabledAction::class)->execute($owner, false);

    expect($owner->newQuery()->find($owner->getKey()))->toBeNull()
        ->and(ResellerUser::query()->withTrashed()->findOrFail($owner->getKey())->trashed())->toBeTrue();

    app(SetResellerOwnerAccountEnabledAction::class)->execute($owner, true);

    expect($owner->newQuery()->find($owner->getKey()))->not->toBeNull();
});

it('replaces the active reseller owner while preserving the former account as history', function (): void {
    $reseller = Reseller::factory()->create();
    $formerOwner = ResellerUser::factory()->forReseller($reseller)->create();

    $replacement = app(ReplaceResellerOwnerAction::class)->execute(
        $reseller,
        'replacement',
        'replacement@reseller.test',
        'SecurePassword123',
    );

    expect($formerOwner->newQuery()->find($formerOwner->getKey()))->toBeNull()
        ->and(ResellerUser::query()->withTrashed()->findOrFail($formerOwner->getKey())->trashed())->toBeTrue()
        ->and($reseller->ownerUser()->sole()->is($replacement))->toBeTrue()
        ->and(Hash::check('SecurePassword123', $replacement->password))->toBeTrue();

    expect(fn() => app(SetResellerOwnerAccountEnabledAction::class)->execute($formerOwner, true))
        ->toThrow(LogicException::class);
});
