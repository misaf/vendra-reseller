# Vendra Reseller

The reseller domain and the reseller self-service panel for Laravel. A reseller
is billed for one or more stores: it holds the subscription, the
plan limits are enforced against it, and its user manages its stores from
its own Filament panel.

A reseller spans several tenants, so **the panel runs outside the tenant
middleware stack**. There is no current tenant here; everything is scoped by
reseller.

That scoping lives in one place — `StoreResource::getEloquentQuery()` — because
the table, the record actions, and global search all build on it. A user whose
reseller cannot be resolved sees nothing at all. Panel access already requires
an active, non-offboarded reseller, but the guard stays explicit because
`where('reseller_id', null)` means `whereNull` to Eloquent, which is every store
the platform owns directly.

Each reseller has exactly one main account: `resellers.user_id` (required,
unique) points at a canonical tenantless user (`misaf/vendra-user`,
`tenant_id` null). Identity columns live on `users`; replacing the account
repoints `user_id` while the former identity — and any tenant access it holds —
stays intact.

## Requirements

- PHP 8.4+
- Laravel 13
- Filament 5
- `misaf/vendra-store`, `misaf/vendra-subscription`, `misaf/vendra-transaction`,
  `misaf/vendra-tenant`, `misaf/vendra-localization`, `misaf/vendra-user`
  and `misaf/vendra-support`

Registration and provisioning validate credentials through `vendra-user`’s
`Support\UserRules::username()` and `password()`. Username rules require 3–12
letters, numbers, dashes, or underscores; registration additionally requires ASCII.
Password strength follows the application default policy, for a supplied
`--password` and for the generated one alike.

## Installation

```bash
composer require misaf/vendra-reseller
php artisan vendor:publish --tag=vendra-reseller-migrations
php artisan migrate
```

The published migration creates the `resellers` table; the host application's `config/auth.php` points the `reseller`
guard at the tenantless `reseller` provider and the `reseller`
password broker, which stores its reset tokens in
`reseller_password_reset_tokens` so neither a tenant user sharing the email nor
the console panel can consume them. A user may
enter the panel only while it is the main account of an active reseller:
deactivating the reseller (`SetResellerActiveAction`) is how its account is
locked out, and an offboarded reseller grants nothing.

Two-factor authentication is optional: a reseller turns on an authenticator app,
with recovery codes, from the profile page, and from then on the login asks for
a code. A reseller who lost both asks the platform; console staff remove it from
the reseller's row.

The panel is served on the `reseller.` subdomain of `vendra-tenant.central_host`,
the host in `APP_URL`. Because that value is resolved when config loads rather than
per call, changing `app.url` at runtime does not move the panel.

## Usage

### Creating a reseller

```php
use Misaf\VendraReseller\Actions\CreateResellerAction;

$reseller = app(CreateResellerAction::class)->execute(
    plan: $plan,
    username: 'acme',
    email: 'user@acme.test',
    password: $password,
);
```

This creates the main account (through `vendra-user`'s `CreateUserAction`),
the reseller pointing at it, and the subscription to the given plan.

### User accounts

Password changes go through `vendra-user`'s `UpdateUserPasswordAction`, which
handles tenant-less users. Email changes go through
`UpdateResellerUserEmailAction`, and `ReplaceResellerUserAction` creates a new
main account and repoints the reseller to it — the former identity is never
deleted. There is no separate account disable: deactivate the reseller.

A reseller stores no name, description, slug, or contact email of its own: it
is identified by its main account's username (`Reseller::displayName()`, or
`Reseller::displayNames()` for option lists), and subscription notifications
go to that account.

### Offboarding

```php
use Misaf\VendraReseller\Actions\OffboardResellerAction;

app(OffboardResellerAction::class)->execute($reseller, reason: 'Contract ended');
```

`OffboardResellerAction` is the **only** supported removal path. `Reseller`'s
`deleting` hook throws for a reseller that was never offboarded, and
`Events\ResellerOffboarded` is the extension point for downstream work.

### Activation

```php
use Misaf\VendraReseller\Actions\SetResellerActiveAction;

app(SetResellerActiveAction::class)->execute($reseller, active: false);
```

`SetResellerActiveAction` is the supported way to flip `active`. An inactive
reseller cannot create stores; an offboarded reseller cannot be reactivated.

### The subscriber

`Models\Reseller` implements `SubscriptionSubscriber`, so plan limits are
answered by `misaf/vendra-subscription` and store quotas by
`Misaf\VendraStore\Support\StoreQuota` — no limit arithmetic is duplicated
here. `Support\ResellerPlanUsageGuard` refuses a plan change or renewal onto a
plan whose per-store limits any of the reseller's stores already exceeds, or that
drops `custom_domain` while a store uses a custom domain. The billing page's
plan picker disables and labels plans the stores have outgrown and lists what
the chosen plan includes (stores, per-store limits, features), and the store
table shows each store's usage against every per-store limit.

```php
$reseller->canHoldUnits();
$reseller->activeSubscription();
$reseller->subscribedUnitCount();
$reseller->allows('feature-key');
```

Filter resellers by their subscriptions with the `withActiveSubscription()`,
`withoutActiveSubscription()`, `withSubscriptionEndingWithin($days)` and
`withPastDueSubscription()` scopes.

`Support\ResellerStoreSuspender` implements the subscription package's
`SubscriptionUnitSuspender` contract: it suspends or reactivates a reseller's
stores through `SuspendStoreForBillingAction` or
`ReactivateStoreForBillingAction`, so each storefront stops or starts with it.

`Support\TransactionSubscriptionCharger` implements the `SubscriptionCharger`
contract by posting an internal withdrawal against the payer's wallet through
`misaf/vendra-transaction`.

### Subscription reactions

The subscription engine raises only generic lifecycle events. This package turns
them into reseller behaviour, wired in `Providers\ResellerServiceProvider`:

| Event | Listener |
| --- | --- |
| `PlanEntitlementsChanged` | `WarnResellersOfOutgrownPlan` |
| `ScheduledPlanChangeDropped` | `NotifyDroppedPlanChange` |
| `StoreLimitApproached` (vendra-store) | `WarnResellerOfStoreLimit` |
| `SubscriptionActivated` | `NotifyActivatedSubscriber` |
| `SubscriptionCancelled` | `SuspendSubscriberStores` |
| `SubscriptionExpiringSoon` | `RemindExpiringSubscriber` |
| `SubscriptionGraceExpired` | `SuspendSubscriberStores` |
| `SubscriptionInvoiceIssued` | `NotifyInvoiceIssued` |
| `TransactionApproved` (deposit) | `RenewAfterWalletDeposit` |

`WarnResellerOfStoreLimit` emails the reseller once a store crosses 80% and
again at 100% of a plan limit, at most once per threshold each subscription
period. `RemindExpiringSubscriber`'s reminder also warns when the stores have
outgrown the scheduled downgrade, before the renewal falls back to the current
plan.

A reseller whose stores have outgrown its current plan cannot renew on it and
has to change to a plan that fits. `WarnResellersOfOutgrownPlan` emails each
affected reseller once per period when console staff change a plan's limits,
the expiry reminder asks it to change plan instead of renewing, and the Billing
page and `PlanSummary` flag it while the renew action is disabled.

`Support\ResellersOverPlan` lists resellers whose stores no longer fit their
active plan, which happens when console staff lower a plan's limits.

`RenewAfterWalletDeposit` retries an auto-renewing plan that expired or went
past due for lack of funds as soon as the reseller's wallet is credited.

Add a new reaction as a listener here rather than pushing reseller knowledge
into the subscription engine, and do not register these listeners again in the
host application.

### Wallet and billing

Plans are charged from the reseller user's platform wallet, a tenantless wallet
in `misaf/vendra-transaction` (`Reseller::wallets()`), through the platform's
internal gateway (`PlatformGatewaySeeder`). Money reaches it only as a console
credit: `Actions\CreditResellerWalletAction` records a payment made outside
the platform as a settled deposit with a note.

Every paid charge is invoiced by the subscription engine. `Reseller::billingDetails()`
supplies the buyer: the optional `billing_name` (falling back to the username),
`billing_address` and `tax_id`, which `Actions\UpdateResellerBillingDetailsAction`
sets; invoices already issued keep the details they were issued with.
`NotifyInvoiceIssued` emails the reseller user a link to its invoices through the
queued `Notifications\InvoiceIssuedNotification`. `Reseller::invoices()` lists them.

## Commands

```bash
php artisan vendra-reseller:provision-store {name} {domain} {username} {email} \
    [--reseller=] [--plan=] [--password=] [--if-missing] [--seed]
```

Provisions a store with its domain, administrator user, and role assignment. It calls
`Misaf\VendraStore\Actions\ProvisionStoreAction` — the reseller-specific
part is only which reseller is attached (`--reseller`, by id or by its user's
username), or created and subscribed (`--plan`).

## Panel

`Providers\ResellerPanelServiceProvider` registers the panel (guard, broker,
domain, login and registration pages, widgets). `Providers\ResellerServiceProvider`
registers the console command and the event listeners. The split is deliberate.

Store screens are reused, not copied: the panel's resources extend
`misaf/vendra-store`'s `CreateStorePage`, `StorefrontConfigurationFields`
and its `DomainsRelationManager` with the alias actions (`AddDomainAliasTableAction`,
`RemoveDomainAliasTableAction`, gated by `StoreResource::canManageStores()`),
supplying the authenticated user's reseller. A store's primary domain, the one it
was created with, never changes; the reseller adds and removes aliases from the
store's domains tab.
Store creation records the managed storefront image and slug and deploys with
sample contact, location, and social details when the runtime is configured.
The store administrator replaces those details in Admin General Settings.
The store list carries `Resources\Stores\Widgets\StoreStatusOverview` (the
reseller's stores per status and failed storefronts, each linking to the
filtered list), and the store view shows `Misaf\VendraStore\Filament\Widgets\StorePlanUsage`
when the plan sets per-store limits.
Resolve the acting reseller with `Filament\Concerns\InteractsWithCurrentReseller`;
`Http\Middleware\AddResellerToRequestJobContext` carries it into queued work.

The reseller dashboard (`Filament\Pages\Dashboard`) lists its widgets in a
fixed order: `GettingStarted` walks a new reseller from subscribing to a live
storefront and disappears once one is live; `PlanSummary` shows the plan, its
renewal or trial end (warning a week ahead), store usage against the allowance,
stores suspended for billing, and each per-store plan limit against the store
that uses the most of it (warning from 80%); `StoresNeedingAttention` lists stores still
provisioning, failed, with a failed storefront, or over a plan limit, with the reason;
`LatestStores` lists the newest stores. Store counts come from
`Misaf\VendraStore\Support\StoreStatusCounts` in one grouped query. Store listings expose derived store and storefront-deployment
statuses and filters, with all queries still rooted in
`StoreResource::getEloquentQuery()`. Runtime administration and container details
remain console concerns and are not exposed here.

The `Filament\Pages\Billing` page shows the acting reseller's plan, auto-renew
state, scheduled change, wallet balance and recent payments. Its header actions
(`Filament\Pages\Billing\Actions\`) change the plan (quoting each option with
`PlanChangeQuote`), renew a plan that is no longer running, turn auto-renew on or
off, cancel a scheduled downgrade, and edit the billing details invoices name.
Anything that charges the wallet is refused up front when the balance cannot
cover it with tax added, and quoted charges include that tax. The
`Filament\Pages\Invoices` page lists the reseller's invoices and downloads each
as a PDF rendered on demand.

## Testing

Build resellers from the package factory, which creates the main account; pass
your own with `Reseller::factory()->for($user)` or repoint one with
`$reseller->user()->associate($user)->save()`, then assert
quota and suspension behaviour through the actions. Panel tests must not assume
a current tenant.

```bash
php artisan test --compact --testsuite=vendra-reseller
```

## License

MIT. See [LICENSE](LICENSE).
