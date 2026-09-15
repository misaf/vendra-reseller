<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Lang;
use Misaf\VendraReseller\Console\Commands\ProvisionStoreCommand;
use Misaf\VendraReseller\Support\EloquentStoreResellerResolver;
use Misaf\VendraStore\Contracts\StoreResellerResolver;

it('registers the reseller command, store resolver, and translations through the package provider', function (): void {
    expect(collect(Artisan::all())->contains(fn (mixed $command): bool => $command instanceof ProvisionStoreCommand))->toBeTrue()
        ->and(resolve(StoreResellerResolver::class))->toBeInstanceOf(EloquentStoreResellerResolver::class)
        ->and(Lang::has('vendra-reseller::navigation.stores', 'en', false))->toBeTrue();
});
