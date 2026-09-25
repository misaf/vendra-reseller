<?php

declare(strict_types=1);

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Misaf\VendraReseller\Filament\Pages\Auth\Login;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create([
        'tenant_id' => null,
        'password' => Hash::make('reseller-password'),
    ]);

    Reseller::factory()->active()->for($this->user)->create();

    Filament::setCurrentPanel(Filament::getPanel('reseller'));
});

it('leaves two-factor optional for resellers', function (): void {
    actingAs($this->user, 'reseller');

    $this->get('https://reseller.vendra.test')->assertOk();
});

it('challenges a reseller with an authenticator app and accepts each recovery code once', function (): void {
    $app = AppAuthentication::make();
    $app->saveSecret($this->user, $app->generateSecret());
    $app->saveRecoveryCodes($this->user, ['first-recovery-code', 'second-recovery-code']);

    $signIn = fn () => livewire(Login::class)
        ->fillForm(['email' => $this->user->email, 'password' => 'reseller-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $login = $signIn();

    expect(auth('reseller')->check())->toBeFalse();

    $login
        ->fillForm(['app' => ['useRecoveryCode' => true, 'recoveryCode' => 'first-recovery-code']], 'multiFactorChallengeForm')
        ->call('authenticate')
        ->assertHasNoFormErrors(form: 'multiFactorChallengeForm');

    expect(auth('reseller')->id())->toBe($this->user->getKey());

    auth('reseller')->logout();

    $signIn()
        ->fillForm(['app' => ['useRecoveryCode' => true, 'recoveryCode' => 'first-recovery-code']], 'multiFactorChallengeForm')
        ->call('authenticate')
        ->assertHasFormErrors(['app.recoveryCode'], 'multiFactorChallengeForm');

    expect(auth('reseller')->check())->toBeFalse()
        ->and($this->user->refresh()->app_authentication_recovery_codes)->toHaveCount(1);
});
