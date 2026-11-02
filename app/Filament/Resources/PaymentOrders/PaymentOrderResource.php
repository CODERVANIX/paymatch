<?php

namespace App\Filament\Resources\PaymentOrders;

use App\Filament\Resources\PaymentOrders\Pages\CreatePaymentOrder;
use App\Filament\Resources\PaymentOrders\Pages\ListPaymentOrders;
use App\Filament\Resources\PaymentOrders\Schemas\PaymentOrderForm;
use App\Filament\Resources\PaymentOrders\Tables\PaymentOrdersTable;
use App\Models\PaymentOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PaymentOrderResource extends Resource
{
    protected static ?string $model = PaymentOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static ?string $navigationLabel = 'Orders';
    protected static ?string $modelLabel = 'order';

    public static function canCreate(): bool
    {
        return in_array(auth()->user()?->role, ['admin', 'operator'], true);
    }

    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return PaymentOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentOrders::route('/'),
            'create' => CreatePaymentOrder::route('/create'),
        ];
    }
}
