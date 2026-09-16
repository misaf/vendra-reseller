<?php

declare(strict_types=1);

use Misaf\VendraReseller\Actions\OffboardResellerAction;
use Misaf\VendraReseller\Actions\SetResellerActiveAction;
use Misaf\VendraReseller\Models\Reseller;

it('deactivates and reactivates a reseller', function (): void {
    $reseller = Reseller::factory()->create(['active' => true]);

    expect(resolve(SetResellerActiveAction::class)->execute($reseller, false)->active)->toBeFalse()
        ->and($reseller->refresh()->active)->toBeFalse()
        ->and(resolve(SetResellerActiveAction::class)->execute($reseller, true)->active)->toBeTrue()
        ->and($reseller->refresh()->active)->toBeTrue();
});

it('refuses to reactivate an offboarded reseller', function (): void {
    $reseller = Reseller::factory()->create(['active' => true]);
    resolve(OffboardResellerAction::class)->execute($reseller, 'Contract ended.');

    expect(fn (): Reseller => resolve(SetResellerActiveAction::class)->execute($reseller, true))
        ->toThrow(LogicException::class)
        ->and(Reseller::query()->withTrashed()->findOrFail($reseller->getKey())->active)->toBeFalse();
});
