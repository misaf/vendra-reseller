<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Models\SubscriptionInvoice;
use Misaf\VendraSubscription\Support\SubscriptionInvoicePdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class Invoices extends Page implements HasTable
{
    use InteractsWithCurrentReseller;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('vendra-reseller::navigation.invoices');
    }

    public function getTitle(): string
    {
        return __('vendra-reseller::navigation.invoices');
    }

    public static function canAccess(): bool
    {
        return self::currentReseller() instanceof Reseller;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => SubscriptionInvoice::query()->whereMorphedTo('subscriber', self::currentReseller()))
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label(__('vendra-reseller::attributes.invoice_number'))
                    ->searchable(),
                TextColumn::make('issued_at')
                    ->label(__('vendra-reseller::attributes.issued_at'))
                    ->date()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label(__('vendra-reseller::attributes.invoice_total'))
                    ->state(fn (SubscriptionInvoice $record): string => $record->formattedTotal()),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('vendra-reseller::attributes.download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (SubscriptionInvoice $record): StreamedResponse => response()->streamDownload(
                        function () use ($record): void {
                            echo SubscriptionInvoicePdf::render($record);
                        },
                        $record->downloadName(),
                        ['Content-Type' => 'application/pdf'],
                    )),
            ])
            ->emptyStateHeading(__('vendra-reseller::attributes.no_invoices'));
    }
}
