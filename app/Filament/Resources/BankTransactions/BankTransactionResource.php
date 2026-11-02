<?php

namespace App\Filament\Resources\BankTransactions;

use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Filament\Resources\BankTransactions\Schemas\BankTransactionForm;
use App\Filament\Resources\BankTransactions\Tables\BankTransactionsTable;
use App\Models\BankTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BankTransactionResource extends Resource
{
    protected static ?string $model = BankTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static ?string $navigationLabel = 'Transactions';

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return BankTransactionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankTransactionsTable::configure($table);
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
            'index' => ListBankTransactions::route('/'),
        ];
    }
}
