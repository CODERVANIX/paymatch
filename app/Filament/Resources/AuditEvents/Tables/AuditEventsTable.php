<?php

namespace App\Filament\Resources\AuditEvents\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Time')->dateTime()->sortable(),
                TextColumn::make('actor_id')->label('Actor ID')->placeholder('System'),
                TextColumn::make('action')->searchable(),
                TextColumn::make('entity_type')->label('Record'),
                TextColumn::make('entity_id')->label('ID'),
                TextColumn::make('detail')->wrap(),
            ])
            ->filters([
                //
            ])
            ->defaultSort('id', 'desc');
    }
}
