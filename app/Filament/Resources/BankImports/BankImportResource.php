<?php

namespace App\Filament\Resources\BankImports;

use App\Filament\Resources\BankImports\Pages\ListBankImports;
use App\Filament\Resources\BankImports\Schemas\BankImportForm;
use App\Filament\Resources\BankImports\Tables\BankImportsTable;
use App\Models\BankImport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BankImportResource extends Resource
{
    protected static ?string $model = BankImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static ?string $navigationLabel = 'Import history';

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return BankImportForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankImportsTable::configure($table);
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
            'index' => ListBankImports::route('/'),
        ];
    }
}
