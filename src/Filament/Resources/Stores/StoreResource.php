<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Misaf\VendraReseller\Filament\Resources\Stores\Pages\CreateStore;
use Misaf\VendraReseller\Filament\Resources\Stores\Pages\ListStores;
use Misaf\VendraReseller\Filament\Resources\Stores\Schemas\StoreForm;
use Misaf\VendraReseller\Filament\Resources\Stores\Tables\StoreTable;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Models\ResellerUser;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Support\StoreCreationPolicy;

final class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'stores';

    public static function getModelLabel(): string
    {
        return __('console.store');
    }

    public static function getPluralModelLabel(): string
    {
        return __('console.stores');
    }

    public static function getNavigationLabel(): string
    {
        return __('console.stores');
    }

    /**
     * The billing reseller of the currently authenticated owner.
     */
    public static function currentResellerId(): ?int
    {
        return self::currentReseller()?->id;
    }

    public static function currentReseller(): ?Reseller
    {
        $user = Filament::auth()->user();

        if ( ! $user instanceof ResellerUser) {
            return null;
        }

        return Reseller::query()->find($user->reseller_id);
    }

    /**
     * Every read of a store in this panel, scoped to the owner's own reseller.
     *
     * The single chokepoint on purpose: the table, the record actions resolve
     * through, and global search all build on this, so scoping the table alone
     * would leave the others open.
     *
     * An owner with no resolvable reseller sees nothing. That is not a
     * hypothetical: offboarding soft-deletes the `Reseller` and leaves the
     * `ResellerUser` able to sign in, and `where('reseller_id', null)` is
     * `whereNull` to Eloquent — which is every store the platform owns
     * directly.
     *
     * @return Builder<Store>
     */
    public static function getEloquentQuery(): Builder
    {
        $resellerId = self::currentResellerId();

        if (null === $resellerId) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()->where('reseller_id', $resellerId);
    }

    /**
     * Two halves of the same gate: the platform must be open for new stores at
     * all — the shared rule `vendra-store` owns and the console edits — and the
     * signed-in owner must still be an active reseller.
     */
    public static function canCreate(): bool
    {
        if ( ! app(StoreCreationPolicy::class)->isOpen()) {
            return false;
        }

        $reseller = self::currentReseller();

        return null !== $reseller && $reseller->active;
    }

    public static function form(Schema $schema): Schema
    {
        return StoreForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StoreTable::configure($table);
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug', 'domains.name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->with([
                'domains' => fn(Relation $relation): Relation => $relation->where('active', true),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $store = self::store($record);
        $domainName = $store->domains->pluck('name')->first();

        return [
            __('console.domain') => is_string($domainName) ? $domainName : '—',
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        $store = self::store($record);

        return static::getUrl('index', ['search' => $store->name]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListStores::route('/'),
            'create' => CreateStore::route('/create'),
        ];
    }

    private static function store(Model $record): Store
    {
        if ( ! $record instanceof Store) {
            throw new InvalidArgumentException('Store resources require a Store record.');
        }

        return $record;
    }
}
