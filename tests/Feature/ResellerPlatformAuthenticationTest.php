<?php

declare(strict_types=1);

use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Uri;
use Misaf\VendraReseller\Filament\Pages\Auth\Login;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

function resellerPlatformUser(Reseller $reseller, array $attributes = []): User
{
    $user = User::factory()->create([
        'tenant_id' => null,
        'password' => Hash::make('platform-password'),
        ...$attributes,
    ]);

    $reseller->users()->attach($user->getKey());

    return $user;
}

it('authenticates the platform user when a tenant row shares the email', function (): void {
    $tenant = createTestTenant();
    $email = 'reseller-shared@example.test';

    User::factory()->forTenant($tenant)->create([
        'username' => 'reseller_tenant',
        'email' => $email,
        'password' => Hash::make('tenant-password'),
    ]);

    $reseller = Reseller::factory()->create();
    $user = resellerPlatformUser($reseller, [
        'username' => 'reseller_owner',
        'email' => $email,
    ]);

    Filament::setCurrentPanel(Filament::getPanel('reseller'));

    livewire(Login::class)
        ->fillForm([
            'email' => $email,
            'password' => 'platform-password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth('reseller')->id())->toBe($user->getKey())
        ->and(auth('web')->check())->toBeFalse()
        ->and(auth('console')->check())->toBeFalse();
});

it('rejects the tenant password and wrong passwords on the reseller panel', function (): void {
    $tenant = createTestTenant();
    $email = 'reseller-wrong@example.test';

    User::factory()->forTenant($tenant)->create([
        'username' => 'reseller_tenant2',
        'email' => $email,
        'password' => Hash::make('tenant-password'),
    ]);

    resellerPlatformUser(Reseller::factory()->create(), [
        'username' => 'reseller_owner2',
        'email' => $email,
    ]);

    Filament::setCurrentPanel(Filament::getPanel('reseller'));

    livewire(Login::class)
        ->fillForm([
            'email' => $email,
            'password' => 'tenant-password',
        ])
        ->call('authenticate')
        ->assertHasFormErrors();

    expect(auth('reseller')->check())->toBeFalse();

    livewire(Login::class)
        ->fillForm([
            'email' => $email,
            'password' => 'definitely-wrong',
        ])
        ->call('authenticate')
        ->assertHasFormErrors();

    expect(auth('reseller')->check())->toBeFalse();
});

it('does not let a tenant-only user into the reseller panel', function (): void {
    $tenant = createTestTenant();
    $email = 'reseller-tenant-only@example.test';

    $tenantUser = User::factory()->forTenant($tenant)->create([
        'username' => 'reseller_only',
        'email' => $email,
        'password' => Hash::make('tenant-password'),
    ]);

    Filament::setCurrentPanel(Filament::getPanel('reseller'));

    livewire(Login::class)
        ->fillForm([
            'email' => $email,
            'password' => 'tenant-password',
        ])
        ->call('authenticate')
        ->assertHasFormErrors();

    expect(auth('reseller')->check())->toBeFalse();

    actingAs($tenantUser, 'web');

    expect(auth('web')->id())->toBe($tenantUser->getKey())
        ->and(auth('reseller')->check())->toBeFalse()
        ->and($tenantUser->canAccessPanel(Filament::getPanel('reseller')))->toBeFalse();
});

it('restores reseller sessions only for the platform user via remember token', function (): void {
    $tenant = createTestTenant();
    $email = 'reseller-remember@example.test';

    $tenantUser = User::factory()->forTenant($tenant)->create([
        'username' => 'reseller_rem_tenant',
        'email' => $email,
        'remember_token' => 'tenant-remember-token',
    ]);

    $user = resellerPlatformUser(Reseller::factory()->create(), [
        'username' => 'reseller_rem_owner',
        'email' => $email,
        'remember_token' => 'platform-remember-token',
    ]);

    $provider = auth('reseller')->getProvider();

    expect($provider->retrieveByToken($user->getKey(), 'platform-remember-token')?->getKey())->toBe($user->getKey())
        ->and($provider->retrieveByToken($tenantUser->getKey(), 'tenant-remember-token'))->toBeNull();
});

it('removes reseller access when the membership is revoked', function (): void {
    $reseller = Reseller::factory()->create();
    $user = resellerPlatformUser($reseller);
    $panel = Filament::getPanel('reseller');

    expect($user->canAccessPanel($panel))->toBeTrue();

    DB::table('reseller_users')
        ->where('reseller_id', $reseller->getKey())
        ->where('user_id', $user->getKey())
        ->update(['deleted_at' => now()]);

    expect($user->canAccessPanel($panel))->toBeFalse()
        ->and(User::query()->find($user->getKey()))->not->toBeNull();
});

it('routes the reseller password reset flow to the platform identity and store', function (): void {
    Notification::fake();

    $tenant = createTestTenant();
    $email = 'reseller-reset@example.test';

    $tenantUser = User::factory()->forTenant($tenant)->create([
        'username' => 'reseller_reset_tenant',
        'email' => $email,
        'password' => Hash::make('tenant-password'),
    ]);

    $user = resellerPlatformUser(Reseller::factory()->create(), [
        'username' => 'reseller_reset_owner',
        'email' => $email,
    ]);

    $panel = Filament::getPanel('reseller');
    Filament::setCurrentPanel($panel);

    livewire(RequestPasswordReset::class)
        ->fillForm(['email' => $email])
        ->call('request')
        ->assertHasNoFormErrors();

    /*
    | The host binds its own subclass of Filament's reset notification, and
    | NotificationFake matches on the exact class, so resolve whatever the
    | container hands the page rather than naming the host class here. The
    | plain token only survives on the signed reset URL.
    */
    $notificationClass = app(ResetPasswordNotification::class)::class;

    $token = null;

    Notification::assertSentTo($user, $notificationClass, function (ResetPasswordNotification $notification) use (&$token): bool {
        $token = Uri::of($notification->url)->query()->get('token');

        return true;
    });

    Notification::assertNotSentTo($tenantUser, $notificationClass);

    expect($panel->getAuthPasswordBroker())->toBe('reseller')
        ->and(DB::table('reseller_password_reset_tokens')->where('email', $email)->count())->toBe(1)
        ->and(DB::table('password_reset_tokens')->where('email', $email)->count())->toBe(0);

    livewire(ResetPassword::class, ['email' => $email, 'token' => $token])
        ->fillForm([
            'password' => 'reseller-rotated-password',
            'passwordConfirmation' => 'reseller-rotated-password',
        ])
        ->call('resetPassword')
        ->assertHasNoFormErrors();

    expect(Hash::check('reseller-rotated-password', $user->fresh()->password))->toBeTrue()
        ->and(Hash::check('tenant-password', $tenantUser->fresh()->password))->toBeTrue()
        ->and(DB::table('reseller_password_reset_tokens')->where('email', $email)->count())->toBe(0);
});
