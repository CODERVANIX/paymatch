<?php

namespace App\Filament\Resources\PaymentOrders\Tables;

use App\Models\PaymentOrder;
use App\Services\PaymentWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->weight('medium'),
                TextColumn::make('customer')->searchable(),
                TextColumn::make('amount_cents')->label('Expected (LKR)')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2))->alignEnd(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => match ($state) {
                    'awaiting_transfer' => 'Awaiting transfer', 'candidate' => 'Needs review', 'released' => 'Released',
                    default => $state,
                })->color(fn ($state) => match ($state) {
                    'candidate' => 'warning', 'released' => 'success', default => 'gray',
                }),
                TextColumn::make('transaction.bank_id')->label('Bank transaction')->placeholder('—'),
                TextColumn::make('approver.email')->label('Released by')->placeholder('—'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('release')->label('Review & release')
                    ->visible(fn (PaymentOrder $record) => $record->status === 'candidate'
                        && in_array(auth()->user()?->role, ['admin', 'approver'], true))
                    ->schema([
                        Checkbox::make('bank_record_checked')->label('I checked this transaction in the bank’s official record')->required()->accepted(),
                        Textarea::make('note')->label('Approval note')->required()->minLength(8)->maxLength(500),
                    ])
                    ->modalDescription('The CSV match is only a candidate. Check the bank record before dispatch.')
                    ->action(function (PaymentOrder $record, array $data): void {
                        app(PaymentWorkflow::class)->release($record->id, $data['note'], (bool) $data['bank_record_checked'], auth()->user());
                        Notification::make()->title('Dispatch released; approver recorded')->success()->send();
                    }),
            ]);
    }
}
