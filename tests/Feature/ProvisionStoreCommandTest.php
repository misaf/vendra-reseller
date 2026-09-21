<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StoreDomain;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSupport\Contracts\SubscriptionCharger;

it('rejects administrator credentials that fail the shared user rules', function (string $username, string $password): void {
    $this->artisan('vendra-reseller:provision-store', [
        'name' => 'Acme',
        'domain' => 'acme.test',
        'username' => $username,
        'email' => 'admin@acme.test',
        '--password' => $password,
        '--no-interaction' => true,
    ])->assertFailed();

    expect(Store::query()->count())->toBe(0);
})->with([
    'invalid username' => ['bad.name', 'secret-password'],
    'weak password' => ['admin_acme', 'short'],
]);

it('generates an administrator password that satisfies the application password rules', function (): void {
    Queue::fake();
    $passwordDefaults = Password::$defaultCallback;
    Password::defaults(fn (): Password => Password::min(40));

    try {
        expect(Artisan::call('vendra-reseller:provision-store', [
            'name' => 'Acme',
            'domain' => 'acme.test',
            'username' => 'admin_acme',
            'email' => 'admin@acme.test',
            '--no-interaction' => true,
        ]))->toBe(0);

        $password = Str::of(Artisan::output())->match('/\|\s*Password\s*\|\s*(\S+)\s*\|/')->toString();

        expect($password)->toHaveLength(40);
    } finally {
        Password::$defaultCallback = $passwordDefaults;
    }
});

it('refuses to provision a store whose domain is not a valid domain', function (): void {
    $this->artisan('vendra-reseller:provision-store', [
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

    $this->artisan('vendra-reseller:provision-store', [
        'name' => 'Acme',
        'domain' => ' ACME.test ',
        'username' => 'admin_acme',
        'email' => 'admin@acme.test',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(Store::query()->count())->toBe(1);
});

it('leaves no reseller behind when a paid plan cannot hold the store yet', function (): void {
    Queue::fake();
    $charger = Mockery::mock(SubscriptionCharger::class);
    $charger->allows(['available' => true, 'provider' => 'testing']);
    app()->instance(SubscriptionCharger::class, $charger);
    $plan = Plan::factory()->priced(5_000)->create(['active' => true]);

    $this->artisan('vendra-reseller:provision-store', [
        'name' => 'Acme',
        'domain' => 'acme.test',
        'username' => 'admin_acme',
        'email' => 'admin@acme.test',
        '--plan' => $plan->slug,
        '--no-interaction' => true,
    ])->assertFailed();

    expect(Store::query()->count())->toBe(0)
        ->and(Reseller::query()->withTrashed()->count())->toBe(0);
});
