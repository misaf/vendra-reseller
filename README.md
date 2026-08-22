# Vendra Reseller

The reseller domain and the reseller self-service panel for Laravel. A reseller
is the billing owner of one or more stores: it holds the subscription, the
plan limits are enforced against it, and its owner manages its stores from
its own Filament panel.

A reseller spans several tenants, so **the panel runs outside the tenant
middleware stack**. There is no current tenant here; everything is scoped by
reseller.

## Requirements

- PHP 8.4+
- Laravel 13
- Filament 5
- `misaf/vendra-store`, `misaf/vendra-subscription`, `misaf/vendra-transaction`,
  `misaf/vendra-tenant`, `misaf/vendra-localization` and `misaf/vendra-support`

## Installation

```bash
composer require misaf/vendra-reseller
php artisan migrate
```

The `resellers` and `reseller_users` tables and the `reseller` guard,
`reseller_users` provider, and `reseller_password_reset_tokens` broker are
configured by the host application's `config/auth.php` and migrations.

The panel is served on `reseller.<app host>`, derived from `app.url` — nothing
here hard-codes a host.

## Usage

### Creating a reseller

```php
use Misaf\VendraReseller\Actions\CreateResellerAction;

$reseller = app(CreateResellerAction::class)->execute(
    plan: $plan,
    username: 'acme',
    email: 'owner@acme.test',
    password: $password,
);
```

This creates the reseller, its first panel user (`CreateResellerOwnerAction`),
and the subscription to the given plan.

### Offboarding

```php
use Misaf\VendraReseller\Actions\OffboardResellerAction;

app(OffboardResellerAction::class)->execute($reseller, reason: 'Contract ended');
```

`OffboardResellerAction` is the **only** supported removal path. `Reseller`'s
`deleting` hook throws for a reseller that was never offboarded, and
`Events\ResellerOffboarded` is the extension point for downstream work.

### The subscriber

`Models\Reseller` implements `SubscriptionSubscriber`, so plan limits are
answered by `misaf/vendra-subscription` and store quotas by
`Misaf\VendraStore\Support\StoreQuota` — no limit arithmetic is duplicated
here.

```php
$reseller->isSubscriptionActive();
$reseller->activeSubscription();
$reseller->subscribedUnitCount();
$reseller->suspendActiveUnits();
$reseller->reactivateSuspendedUnits();
$reseller->allows('feature-key');
```

`Support\TransactionSubscriptionCharger` implements the `SubscriptionCharger`
contract by posting an internal withdrawal against the payer's wallet through
`misaf/vendra-transaction`.

### Subscription reactions

The subscription engine raises only generic lifecycle events. This package turns
them into reseller behaviour, wired in `Providers\ResellerServiceProvider`:

| Event | Listener |
| --- | --- |
| `SubscriptionActivated` | `NotifyActivatedSubscriber` |
| `SubscriptionExpiringSoon` | `RemindExpiringSubscriber` |
| `SubscriptionGraceExpired` | `SuspendSubscriberStores` |

Add a new reaction as a listener here rather than pushing reseller knowledge
into the subscription engine, and do not register these listeners again in the
host application.

## Commands

```bash
php artisan vendra-subscription:provision {name} {domain} {username} {email} \
    [--reseller=] [--plan=] [--password=] [--if-missing] [--seed]
```

Provisions a store with its domain, owner user, and role assignment. It calls
`Misaf\VendraStore\Actions\ProvisionStoreAction` — the reseller-specific
part is only which reseller is attached (`--reseller`), or created and
subscribed (`--plan`).

## Panel

`Providers\ResellerPanelServiceProvider` registers the panel (guard, broker,
domain, login and registration pages, widgets). `Providers\ResellerServiceProvider`
registers the console command and the event listeners. The split is deliberate.

Store screens are reused, not copied: the panel's resources extend
`misaf/vendra-store`'s `CreateStorePage`, `StorefrontConfigurationFields`
and `ReplaceDomainAction`, supplying the authenticated owner as the reseller.
Resolve the acting reseller with `Filament\Concerns\InteractsWithCurrentReseller`;
`Http\Middleware\AddResellerToRequestJobContext` carries it into queued work.

## Testing

Build resellers, owners, and subscriptions from the package factories and assert
quota and suspension behaviour through the actions. Panel tests must not assume
a current tenant.

```bash
php artisan test --compact --testsuite=vendra-reseller
```

## License

MIT. See [LICENSE](LICENSE).
