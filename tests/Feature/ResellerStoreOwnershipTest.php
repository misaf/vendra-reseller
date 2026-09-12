<?php

declare(strict_types=1);

use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Support\EloquentStoreResellerResolver;
use Misaf\VendraStore\Contracts\StoreResellerResolver;
use Misaf\VendraStore\Models\Store;

/*
 | Reseller hasMany Stores, and the arrow points reseller → store: the store
 | package holds only `reseller_id` and asks through StoreResellerResolver, which
 | this package binds. Both halves of the business API are asserted here because
 | both are registered here.
 */
it('binds the store billing-reseller port to the reseller domain', function (): void {
    expect(resolve(StoreResellerResolver::class))->toBeInstanceOf(EloquentStoreResellerResolver::class);

    $reseller = Reseller::factory()->create();

    expect(resolve(StoreResellerResolver::class)->find($reseller->getKey())?->getKey())->toBe($reseller->getKey())
        ->and(resolve(StoreResellerResolver::class)->find(999999))->toBeNull();
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
