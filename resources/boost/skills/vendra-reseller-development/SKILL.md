---
name: vendra-reseller-development
description: "Create, modify, review, or test the Vendra Reseller module in packages/vendra-reseller, changing the reseller domain and the reseller self-service panel. Use for Reseller, ResellerUser, CreateResellerAction, CreateResellerOwnerAction, OffboardResellerAction, OffboardResellerBulkAction, ResellerOffboarded, InteractsWithCurrentReseller, ResellerPanelServiceProvider, ResellerServiceProvider, ProvisionStoreCommand, AddResellerToRequestJobContext, TransactionSubscriptionCharger, NotifyActivatedSubscriber, RemindExpiringSubscriber, SuspendSubscriberStores, SubscriptionActivatedNotification, SubscriptionExpiringNotification, StoresSuspendedNotification, ResellerOverview, LatestStores, SubscriptionDetail, and the panel's store resource."
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

- `Actions\CreateResellerAction` and `Actions\CreateResellerOwnerAction` create the reseller and its first panel user.
- Owner changes use `UpdateResellerOwnerPasswordAction`, `UpdateResellerOwnerEmailAction`, `SetResellerOwnerAccountEnabledAction`, and `ReplaceResellerOwnerAction`; preserve replaced owners as soft-deleted account history.
- `Actions\OffboardResellerAction` is the only supported removal path. `Reseller::deleting` throws for a reseller that was not offboarded first, and `Events\ResellerOffboarded` is the extension point.
- `Models\Reseller` implements `SubscriptionSubscriber` and `ShouldLogActivity`. Read quota state through `Misaf\VendraStore\Support\StoreQuota`; do not recompute plan limits inline.

## Subscription Reactions

- `Providers\ResellerServiceProvider` maps generic subscription events to reseller behaviour: `SubscriptionActivated` → `NotifyActivatedSubscriber`, `SubscriptionExpiringSoon` → `RemindExpiringSubscriber`, and both `SubscriptionCancelled` / `SubscriptionGraceExpired` → `SuspendSubscriberStores`.
- Add new reactions as listeners here. Do not push reseller knowledge into the subscription engine, and do not register the same listeners again in the host app.

## Panel

- `Providers\ResellerPanelServiceProvider` registers the panel; `Providers\ResellerServiceProvider` registers the command and listeners. Keep the split.
- Resolve the acting reseller with `Filament\Concerns\InteractsWithCurrentReseller`; `Http\Middleware\AddResellerToRequestJobContext` carries it into queued work.
- Dashboard usage and operational counts reuse `StoreQuota`, subscriber methods, and `Store::status()`. Store and deployment-status filters must remain rooted in `StoreResource::getEloquentQuery()` so they cannot cross reseller boundaries.
- Do not expose container-runtime administration, logs, or platform recovery actions in the reseller panel.

## Testing

- Build resellers, owners, and subscriptions from the package factories, and assert quota and suspension behaviour through the actions rather than the panel where possible.
- Panel tests must not assume a current tenant.

## Filament

- Resources with a cluster live in `src/Filament/Clusters/Resources/`; resources without one live in `src/Filament/Resources/`.
