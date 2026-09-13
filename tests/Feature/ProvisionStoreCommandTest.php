<?php

declare(strict_types=1);

use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StoreDomain;

it('refuses to provision a store whose domain is not a valid domain', function (): void {
    $this->artisan('vendra-subscription:provision', [
        'name' => 'Acme',
        'domain' => 'not a domain',
        'username' => 'admin_acme',
        'email' => 'admin@acme.test',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(Store::query()->count())->toBe(0);
});

it('refuses to provision a store on a domain another store already uses', function (): void {
    StoreDomain::factory()->for(Store::factory()->create())->create(['name' => 'acme.test', 'active' => true]);

    $this->artisan('vendra-subscription:provision', [
        'name' => 'Acme',
        'domain' => ' ACME.test ',
        'username' => 'admin_acme',
        'email' => 'admin@acme.test',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(Store::query()->count())->toBe(1);
});
