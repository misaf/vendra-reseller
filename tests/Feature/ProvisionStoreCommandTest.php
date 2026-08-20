<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Jobs\CompleteStoreProvisioningJob;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StoreDomain;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSupport\Tenancy\Events\TenantProvisioned;

it('skips provisioning an existing tenant domain when requested', function (): void {
    $tenant = Store::factory()->create();
    StoreDomain::factory()->for($tenant)->create(['name' => 'existing.test']);

    $this->artisan('vendra-subscription:provision', [
        'name'         => 'Existing Tenant',
        'domain'       => 'existing.test',
        'username'     => 'admin',
        'email'        => 'admin@example.com',
        '--if-missing' => true,
    ])
        ->expectsOutput('Tenant domain [existing.test] already exists; provisioning skipped.')
        ->assertSuccessful();

    expect(Store::query()->count())->toBe(1)
        ->and(StoreDomain::query()->count())->toBe(1)
        ->and(testUserModel()::query()->count())->toBe(0);
});

it('provisions a tenant with a provided password without printing it', function (): void {
    Event::fake([TenantProvisioned::class]);
    Queue::fake();

    $this->artisan('vendra-subscription:provision', [
        'name'       => 'Acme',
        'domain'     => 'acme.test',
        'username'   => 'admin_acme',
        'email'      => 'admin@acme.test',
        '--password' => 'secret-password',
    ])
        ->expectsConfirmation('Run default tenant seeders?', 'no')
        ->expectsOutputToContain('[provided]')
        ->doesntExpectOutputToContain('secret-password')
        ->assertSuccessful();

    Queue::assertPushed(CompleteStoreProvisioningJob::class);
});

it('provisions a property under a new reseller subscribed to the given plan', function (): void {
    Event::fake([TenantProvisioned::class]);
    Queue::fake();

    $plan = Plan::factory()->maxUnits(3)->create();

    $this->artisan('vendra-subscription:provision', [
        'name'       => 'Acme',
        'domain'     => 'acme.test',
        'username'   => 'admin_acme',
        'email'      => 'admin@acme.test',
        '--password' => 'secret-password',
        '--plan'     => (string) $plan->getKey(),
    ])
        ->expectsConfirmation('Run default tenant seeders?', 'no')
        ->assertSuccessful();

    $reseller = Reseller::query()->first();

    expect($reseller)->not->toBeNull()
        ->and($reseller->stores()->count())->toBe(1)
        ->and($reseller->activeSubscription()?->plan_id)->toBe($plan->getKey());

    Queue::assertPushed(CompleteStoreProvisioningJob::class);
});

it('fails when the provided plan cannot be resolved', function (): void {
    $this->artisan('vendra-subscription:provision', [
        'name'       => 'Acme',
        'domain'     => 'acme.test',
        'username'   => 'admin_acme',
        'email'      => 'admin@acme.test',
        '--password' => 'secret-password',
        '--plan'     => 'nonexistent',
    ])
        ->expectsConfirmation('Run default tenant seeders?', 'no')
        ->assertFailed();

    expect(Store::query()->count())->toBe(0)
        ->and(Reseller::query()->count())->toBe(0);
});

it('rejects a provided password shorter than eight characters', function (): void {
    $this->artisan('vendra-subscription:provision', [
        'name'       => 'Acme',
        'domain'     => 'acme.test',
        'username'   => 'admin_acme',
        'email'      => 'admin@acme.test',
        '--password' => 'short',
    ])
        ->expectsConfirmation('Run default tenant seeders?', 'no')
        ->assertFailed();

    expect(Store::query()->count())->toBe(0)
        ->and(testUserModel()::query()->count())->toBe(0);
});

it('rejects an existing tenant domain without the option', function (): void {
    $tenant = Store::factory()->create();
    StoreDomain::factory()->for($tenant)->create(['name' => 'existing.test']);

    $this->artisan('vendra-subscription:provision', [
        'name'     => 'Duplicate Tenant',
        'domain'   => 'existing.test',
        'username' => 'admin',
        'email'    => 'admin@example.com',
    ])
        ->assertFailed();

    expect(Store::query()->count())->toBe(1)
        ->and(StoreDomain::query()->count())->toBe(1)
        ->and(testUserModel()::query()->count())->toBe(0);
});
