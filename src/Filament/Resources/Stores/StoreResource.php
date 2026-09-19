<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraReseller\Filament\Resources\Stores\Pages\CreateStore;
use Misaf\VendraReseller\Filament\Resources\Stores\Pages\EditStore;
use Misaf\VendraReseller\Filament\Resources\Stores\Pages\ListStores;
use Misaf\VendraReseller\Filament\Resources\Stores\Pages\ViewStore;
use Misaf\VendraReseller\Filament\Resources\Stores\Schemas\StoreForm;
use Misaf\VendraReseller\Filament\Resources\Stores\Schemas\StoreInfolist;
use Misaf\VendraReseller\Filament\Resources\Stores\Tables\StoreTable;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Settings\StoreCreationSettings;

final class StoreResource extends Resource
{
    use InteractsWithCurrentReseller {
        currentReseller as public;
    }

    protected static ?string $model = Store::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'stores';

    public static function getModelLabel(): string
    {
        return __('vendra-reseller::navigation.store');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vendra-reseller::navigation.stores');
    }

    public static function getNavigationLabel(): string
    {
        return __('vendra-reseller::navigation.stores');
    }

    public static function currentResellerId(): ?int
    {
        return self::currentReseller()?->id;
    }

    /**
     * Scope every store query in the panel to the user's reseller.
     *
     * Without a reseller the query matches nothing, since a null `reseller_id`
     * would match every platform store.
     *
     * @return Builder<Store>
     */
    public static function getEloquentQuery(): Builder
    {
        $resellerId = self::currentResellerId();

        if ($resellerId === null) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where('reseller_id', $resellerId)
            ->with([
                'storefrontDeployment',
                'domains' => fn (Relation $relation): Relation => $relation->where('active', true),
            ]);
    }

    /**
     * Determine if the platform accepts new stores and the user is an active reseller.
     */
    public static function canCreate(): bool
    {
        if (! resolve(StoreCreationSettings::class)->open) {
            return false;
        }

        $reseller = self::currentReseller();

        return $reseller !== null && $reseller->active;
    }

    /**
     * Determine if the user may manage existing stores, regardless of the creation freeze.
     */
    public static function canManageStores(): bool
    {
        return self::currentReseller()?->active === true;
    }

    public static function form(Schema $schema): Schema
    {
        return StoreForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StoreInfolist::configure($schema);
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([ViewStore::class, EditStore::class]);
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
                'domains' => fn (Relation $relation): Relation => $relation->where('active', true),
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
            __('vendra-reseller::attributes.domain') => is_string($domainName) ? $domainName : '—',
        ];
    }

    /**
     * @return array<Action>
     */
    public static function getGlobalSearchResultActions(Model $record): array
    {
        $store = self::store($record);

        return [
            Action::make('openAdmin')
                ->label(__('vendra-reseller::attributes.admin_url'))
                ->url(
                    $store->adminUrl(),
                    shouldOpenInNewTab: true,
                ),
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        $store = self::store($record);

        return self::getUrl('view', ['record' => $store]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStores::route('/'),
            'create' => CreateStore::route('/create'),
            'view' => ViewStore::route('/{record}'),
            'edit' => EditStore::route('/{record}/edit'),
        ];
    }

    private static function store(Model $record): Store
    {
        throw_unless($record instanceof Store, InvalidArgumentException::class, 'Store resources require a Store record.');

        return $record;
    }
}
