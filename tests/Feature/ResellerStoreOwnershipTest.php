<?php

declare(strict_types=1);

use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Support\ResellerStoreOwnerResolver;
use Misaf\VendraStore\Contracts\StoreOwnerResolver;
use Misaf\VendraStore\Models\Store;

/*
 | Reseller hasMany Stores, and the arrow points reseller → store: the store
 | package holds only `reseller_id` and asks through StoreOwnerResolver, which
 | this package binds. Both halves of the business API are asserted here because
 | both are registered here.
 */
it('binds the store ownership port to the reseller domain', function (): void {
    expect(app(StoreOwnerResolver::class))->toBeInstanceOf(ResellerStoreOwnerResolver::class);

    $reseller = Reseller::factory()->create();

    expect(app(StoreOwnerResolver::class)->find($reseller->getKey())?->getKey())->toBe($reseller->getKey())
        ->and(app(StoreOwnerResolver::class)->find(999999))->toBeNull();
});

it('gives each reseller only its own stores', function (): void {
    $resellerA = Reseller::factory()->create();
    $resellerB = Reseller::factory()->create();

    $storeA = Store::factory()->active()->create(['reseller_id' => $resellerA->getKey()]);
    $storeB = Store::factory()->active()->create(['reseller_id' => $resellerA->getKey()]);
    $storeC = Store::factory()->active()->create(['reseller_id' => $resellerB->getKey()]);

    expect($resellerA->stores()->pluck('id')->all())->toEqualCanonicalizing([$storeA->getKey(), $storeB->getKey()])
        ->and($resellerB->stores()->pluck('id')->all())->toBe([$storeC->getKey()])
        ->and($resellerA->subscribedUnitCount())->toBe(2)
        ->and($resellerB->subscribedUnitCount())->toBe(1);
});

it('exposes the owning reseller from the store side', function (): void {
    $reseller = Reseller::factory()->create();
    $owned = Store::factory()->active()->create(['reseller_id' => $reseller->getKey()]);
    $direct = Store::factory()->active()->create();

    expect($owned->reseller?->getKey())->toBe($reseller->getKey())
        ->and($direct->reseller)->toBeNull()
        ->and($direct->reseller_id)->toBeNull();
});
