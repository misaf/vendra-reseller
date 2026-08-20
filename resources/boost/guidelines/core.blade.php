## Vendra Reseller

The `misaf/vendra-reseller` package owns the **reseller domain and the reseller self-service panel**. A reseller is the billing owner of one or more stores, so this package sits above `misaf/vendra-store` and below `misaf/vendra-console`.

### Translatable Persistence

- Making a persisted model field translatable is an explicit domain choice unless this package already requires it.
- Every field listed in a model's `$translatable` array must definitely use a JSON database column. Keep its model traits/casts, factories, validation, Filament locale UI, API serialization, and tests translation-aware.
- A field not listed in `$translatable` must use the appropriate scalar database type and must not use Spatie Translatable, translatable slug traits, locale switchers, translated callbacks, or translation-shaped array data.

### Vendra Transitive API Policy

- Treat a Vendra dependency intentionally exposed through the public API of a directly required Vendra platform package as part of the supported public contract of that package.
- Do not add a redundant direct Composer requirement solely because source code imports a type from that exposed dependency.
- Apply this only to Vendra platform packages listed under `require`; never extend it to `require-dev`, `suggest`, incidental implementation dependencies, or third-party packages. Removing or replacing an exposed dependency is a breaking change; keep `self.version` alignment across the Vendra package graph.

- Keep reseller code inside `packages/vendra-reseller` using the `Misaf\VendraReseller` namespace.
- **A reseller spans several tenants, so the reseller panel runs outside the tenant middleware stack.** Never assume a current tenant here; scope queries by reseller, and resolve the acting reseller through `Filament\Concerns\InteractsWithCurrentReseller` rather than re-deriving it per page.
- `Models\Reseller` is the platform's `SubscriptionSubscriber`. Plan limits and store quotas are answered by `misaf/vendra-subscription` and `Misaf\VendraStore\Support\StoreQuota`; do not reimplement limit arithmetic in a page, widget, or action.
- Deleting a reseller is not an `->delete()`. `Actions\OffboardResellerAction` is the only supported path — the model's `deleting` hook throws for a reseller that was never offboarded, and `Events\ResellerOffboarded` is what downstream work listens to.
- Subscription reactions are wired in `Providers\ResellerServiceProvider`: the subscription engine raises only generic lifecycle events, and this package turns them into reseller behaviour (`NotifyActivatedSubscriber`, `RemindExpiringSubscriber`, `SuspendSubscriberStores`). Add a new reaction as a listener here, not as domain logic inside the subscription engine, and do not also register these listeners in the host app.
- Store work is delegated, not duplicated: the panel's resources extend `misaf/vendra-store`'s `CreateStorePage`, `StorefrontConfigurationFields`, and `ReplaceDomainAction`. The only reseller-specific part is which reseller is resolved as the owner. `Console\Commands\ProvisionStoreCommand` calls `ProvisionStoreAction` for the same reason.
- Filament resources with a cluster live in `src/Filament/Clusters/Resources/`; resources without one live in `src/Filament/Resources/`. The reseller panel's resources are uncluttered and live in `src/Filament/Resources/`.
- Panel registration lives in `Providers\ResellerPanelServiceProvider`; domain wiring (the console command and the event listeners) lives in `Providers\ResellerServiceProvider`. Keep the two apart.
- Follow Laravel comment style: document with PHPDoc (array shapes, generics, `@see`) and reserve inline comments for genuinely complex logic.
