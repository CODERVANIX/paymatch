<?php

namespace App\Filament\Resources\BankTransactions\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BankTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booked_on')->label('Bank date')->date()->sortable(),
                TextColumn::make('bank_id')->label('Bank ID')->searchable(),
                TextColumn::make('reference')->searchable()->wrap(),
                TextColumn::make('amount_cents')->label('Credit (LKR)')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2))->alignEnd(),
                TextColumn::make('account_label')->label('Account'),
                TextColumn::make('matched_order_id')->label('Order ID')->placeholder('—'),
                TextColumn::make('duplicate_of_id')->label('Duplicate of')->placeholder('—'),
            ])
            ->filters([
                //
            ])
            ->defaultSort('id', 'desc');
    }
}
