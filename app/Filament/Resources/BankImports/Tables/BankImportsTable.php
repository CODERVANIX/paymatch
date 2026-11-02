<?php

namespace App\Filament\Resources\BankImports\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BankImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Imported')->dateTime()->sortable(),
                TextColumn::make('file_name')->label('File')->searchable(),
                TextColumn::make('account_label')->searchable(),
                TextColumn::make('row_count')->label('Credits')->sortable(),
                TextColumn::make('imported_by')->label('Importer ID'),
            ])
            ->filters([
                //
            ])
            ->defaultSort('created_at', 'desc');
    }
}
