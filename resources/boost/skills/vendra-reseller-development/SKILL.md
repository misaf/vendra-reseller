---
name: vendra-reseller-development
description: "Create, modify, review, or test the Vendra Reseller module in packages/vendra-reseller, changing the reseller domain and the reseller self-service panel. Use for Reseller, its main account (resellers.user_id), CreateResellerAction, ReplaceResellerUserAction, UpdateResellerUserEmailAction, OffboardResellerAction, ResellerOffboarded, InteractsWithCurrentReseller, ResellerPanelServiceProvider, ResellerServiceProvider, ProvisionStoreCommand, AddResellerToRequestJobContext, TransactionSubscriptionCharger, CreditResellerWalletAction, RenewAfterWalletDeposit, UpdateResellerBillingDetailsAction, the Billing and Invoices pages and their actions, NotifyInvoiceIssued, InvoiceIssuedNotification, NotifyActivatedSubscriber, RemindExpiringSubscriber, SuspendSubscriberStores, SubscriptionActivatedNotification, SubscriptionExpiringNotification, StoresSuspendedNotification, the reseller Dashboard page (GettingStarted, PlanSummary, StoresNeedingAttention, LatestStores), and the panel's store resource."
---

# Vendra Reseller

## Workflow

- Inspect `composer.json`, sibling files, and existing tests before changing the package.
- Use Laravel Boost `application-info` and `search-docs` before code changes.
- Apply `laravel-best-practices` to Laravel PHP and `pest-testing` whenever tests change.
- Keep changes inside this package's boundary and preserve its public contracts.
- Add or update focused Pest coverage, then run `php artisan test --compact --testsuite=vendra-reseller` from the project root.

## Translatable Persistence

- Making a persisted model field translatable is an explicit domain choice unless this package already requires it.
- Every field listed in a model's `$translatable` array must definitely use a JSON database column. Keep its model traits/casts, factories, validation, Filament locale UI, API serialization, and tests translation-aware.
- A field not listed in `$translatable` must use the appropriate scalar database type and must not use Spatie Translatable, translatable slug traits, locale switchers, translated callbacks, or translation-shaped array data.

## Vendra Transitive API Policy

- Treat a Vendra dependency intentionally exposed through the public API of a directly required Vendra platform package as part of the supported public contract of that package.
- Do not add a redundant direct Composer requirement solely because source code imports a type from that exposed dependency.
- Apply this only to Vendra platform packages listed under `require`; never extend it to `require-dev`, `suggest`, incidental implementation dependencies, or third-party packages. Removing or replacing an exposed dependency is a breaking change; keep `self.version` alignment across the Vendra package graph.

## Module Boundary

- A reseller owns stores across several tenants, so **the reseller panel runs outside the tenant middleware stack**. There is no current tenant; scope everything by reseller.
- Store behaviour is reused from `misaf/vendra-store`, never copied. Subclass its page and action bases and supply the reseller; if a change would fit every caller, it belongs in `vendra-store`.
- Plans, subscriptions, and charges belong to `misaf/vendra-subscription` and `misaf/vendra-transaction`. This package supplies the subscriber and the reactions, not the billing engine.

## Reseller Lifecycle

- Each reseller has exactly one main account, `resellers.user_id` (required, unique, a canonical `User` with `tenant_id` null). `Actions\CreateResellerAction` creates the account through `vendra-user`'s `CreateUserAction`, then the reseller and its subscription.
- Password changes use `vendra-user`'s `UpdateUserPasswordAction`; email changes use `UpdateResellerUserEmailAction`; `ReplaceResellerUserAction` creates a new account and repoints `user_id`, leaving the former identity intact. There is no account disable — deactivating the reseller locks its account out of the panel.
- Two-factor authentication is optional on the reseller panel: it registers Filament's `AppAuthentication` provider with recovery codes, the reseller turns it on from the profile page, and the login challenges only a user who did. Console staff remove a lost authenticator from the reseller row; there is no reseller-side reset.
- `Actions\OffboardResellerAction` is the only supported removal path. `Reseller::deleting` throws for a reseller that was not offboarded first, and `Events\ResellerOffboarded` is the extension point.
- `Actions\SetResellerActiveAction` is the only supported way to change `active` after creation. It locks the row and throws for an offboarded reseller; an inactive reseller keeps its stores but its account cannot enter the panel.
- `Models\Reseller` implements `SubscriptionSubscriber` and `ShouldLogActivity`. Read quota state through `Misaf\VendraStore\Support\StoreQuota`; do not recompute plan limits inline. Suspend or reactivate a reseller's stores for billing through `Support\ResellerStoreSuspender` (the `SubscriptionUnitSuspender` binding), never from the model. `Support\ResellerPlanUsageGuard` is the `PlanUsageGuard` binding: it refuses a plan any store outgrows in its per-store limits or custom-domain use. The billing plan picker disables plans `PlanCoverage::covers()` rejects and lists the chosen plan's store allowance, per-store limits and features, and the store table adds a toggleable usage column per `PlanLimit`.

## Subscription Reactions

- `Providers\ResellerServiceProvider` maps generic subscription events to reseller behaviour: `SubscriptionActivated` → `NotifyActivatedSubscriber`, `SubscriptionExpiringSoon` → `RemindExpiringSubscriber`, both `SubscriptionCancelled` / `SubscriptionGraceExpired` → `SuspendSubscriberStores`, `ScheduledPlanChangeDropped` → `NotifyDroppedPlanChange`, `PlanEntitlementsChanged` → `WarnResellersOfOutgrownPlan` (once per reseller and period), and the store package's `StoreLimitApproached` → `WarnResellerOfStoreLimit`, which warns once per store, limit and threshold each subscription period. `RemindExpiringSubscriber` adds a warning when the scheduled downgrade no longer fits and asks the reseller to change plan when the current one no longer fits (a renewal onto it is refused, never waived), and `Support\ResellersOverPlan` finds resellers whose stores no longer fit their active plan. The Billing page and renew action take the next plan from `PlanCoverage::renewalPlan()` and flag an outgrown scheduled downgrade.
- Add new reactions as listeners here. Do not push reseller knowledge into the subscription engine, and do not register the same listeners again in the host app.

## Panel

- `Providers\ResellerPanelServiceProvider` registers the panel; `Providers\ResellerServiceProvider` registers the command and listeners. Keep the split.
- `Filament\Pages\Billing` is the reseller's self-service billing: plan changes, renewal and auto-renew go through the subscription engine's actions, options are labelled from `PlanChangeQuote`, and a charge the wallet cannot cover, with tax added through `TaxedAmount`, is refused before it starts. Top-ups are console credits (`CreditResellerWalletAction`); there is no online gateway. Invoices are issued by the subscription engine; this package supplies the buyer through `Reseller::billingDetails()`, emails them (`NotifyInvoiceIssued`), and lists them on `Filament\Pages\Invoices` with an on-demand PDF download.
- Resolve the acting reseller with `Filament\Concerns\InteractsWithCurrentReseller`; `Http\Middleware\AddResellerToRequestJobContext` carries it into queued work.
- Dashboard usage and operational counts reuse `StoreQuota`, subscriber methods, `Store::status()`, and `StoreStatusCounts` for per-status counts; `PlanSummary` reads per-store limit usage from the support `TenantUsageRegistry` and shows the busiest store per limit. `StoresNeedingAttention` also lists stores over a plan limit (`TenantLimitOverages`). Store and deployment-status filters must remain rooted in `StoreResource::getEloquentQuery()` so they cannot cross reseller boundaries.
- The reseller Store Create form collects only managed image and slug and deploys with sample storefront details. The tenant administrator replaces contact and business details in General Settings; they remain outside Store Edit.
- Do not expose container-runtime administration, logs, or platform recovery actions in the reseller panel.

## Testing

- Build resellers and subscriptions from the package factories; the reseller factory creates the main account (use `->for($user)` to supply one); assert quota and suspension behaviour through the actions rather than the panel where possible.
- Panel tests must not assume a current tenant.

## Filament

- Resources with a cluster live in `src/Filament/Clusters/Resources/`; resources without one live in `src/Filament/Resources/`.

- Registration and provisioning use `vendra-user`'s `Support\UserRules::username()` and `password()`; registration adds `ascii`. An omitted `--password` is generated by `PasswordGenerator::generate()`, never a local `Str::password()`. Keep required fields, confirmation, and scoped uniqueness at the caller.
