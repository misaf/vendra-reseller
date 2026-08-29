<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Livewire\Component as Livewire;
use Misaf\LaravelEmailVerification\Rules\EmailValidation;
use Misaf\VendraStore\Filament\Schemas\StorefrontConfigurationFields;
use Misaf\VendraStore\Models\StoreDomain;

final class StoreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('console.name'))
                    ->required()
                    ->maxLength(255)
                    ->visibleOn('edit'),

                Textarea::make('description')
                    ->label(__('console.description'))
                    ->rows(4)
                    ->maxLength(2000)
                    ->visibleOn('edit')
                    ->columnSpanFull(),

                TextInput::make('domain')
                    ->afterStateUpdated(fn(Livewire $livewire) => $livewire->validateOnly('data.domain'))
                    ->helperText(__('console.domain_helper_text'))
                    ->label(__('console.domain'))
                    ->live(onBlur: true)
                    ->maxLength(255)
                    ->required()
                    ->rules(StoreDomain::activeDomainRules())
                    ->dehydrateStateUsing(fn(?string $state): ?string => null === $state
                        ? null
                        : StoreDomain::normalizeDomain($state))
                    ->visibleOn('create'),

                TextInput::make('email')
                    ->afterStateUpdated(fn(Livewire $livewire) => $livewire->validateOnly('data.email'))
                    ->label(__('console.email'))
                    ->email()
                    ->extraAttributes(['dir' => 'ltr'])
                    ->live(onBlur: true)
                    ->maxLength(255)
                    ->required()
                    ->rules([
                        'bail',
                        'email:rfc,strict,spoof,filter,filter_unicode',
                        new EmailValidation(),
                    ])
                    ->visibleOn('create'),

                ...StorefrontConfigurationFields::make(optional: false),
            ])
            ->columns(2);
    }
}
