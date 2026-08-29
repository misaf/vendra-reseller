<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Config;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StorefrontDeployment;

final class StoreInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('console.store_identity'))
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('name')->label(__('console.name')),
                        TextEntry::make('slug')->label(__('console.slug'))->copyable(),
                        TextEntry::make('active_domain')->label(__('console.domain'))
                            ->state(fn(Store $record): ?string => $record->domains->first()?->name)->placeholder('—'),
                        TextEntry::make('admin_url')->label(__('console.admin_url'))
                            ->state(fn(Store $record): string => 'https://' . $record->slug . '.' . Config::string('vendra-tenant.central_host'))
                            ->url(fn(Store $record): string => 'https://' . $record->slug . '.' . Config::string('vendra-tenant.central_host'))
                            ->openUrlInNewTab()->copyable(),
                        IconEntry::make('active')->label(__('console.active'))->boolean(),
                    ]),
                    TextEntry::make('description')->label(__('console.description'))->placeholder('—')->columnSpanFull(),
                ])->columnSpanFull(),
            Section::make(__('console.storefront_configuration'))
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('store_status')->label(__('console.operational_status'))
                            ->badge()->state(fn(Store $record): string => $record->status()->value)
                            ->formatStateUsing(fn(string $state): string => __("console.store_status_{$state}")),
                        TextEntry::make('deployment_status')->label(__('console.storefront_status'))
                            ->badge()->state(fn(Store $record): ?string => self::deployment($record)?->status->value)
                            ->formatStateUsing(fn(string $state): string => __("console.deployment_status_{$state}"))
                            ->placeholder(__('console.storefront_not_requested')),
                    ]),
                    TextEntry::make('provisioning_error')->label(__('console.provisioning_error'))
                        ->visible(fn(Store $record): bool => filled($record->provisioning_error))
                        ->color('danger')->columnSpanFull(),
                ])->columnSpanFull(),
        ]);
    }

    private static function deployment(Store $store): ?StorefrontDeployment
    {
        $deployment = $store->storefrontDeployments->first();

        return $deployment instanceof StorefrontDeployment ? $deployment : null;
    }
}
