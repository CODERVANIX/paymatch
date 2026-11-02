<?php

namespace App\Filament\Widgets;

use App\Models\BankTransaction;
use App\Models\PaymentOrder;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PaymentOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Payment operations';
    protected ?string $description = 'Check each match in the bank record before dispatch.';

    protected function getStats(): array
    {
        return [
            Stat::make('Awaiting transfer', PaymentOrder::where('status', 'awaiting_transfer')->count()),
            Stat::make('Needs review', PaymentOrder::where('status', 'candidate')->count())->color('warning'),
            Stat::make('Released', PaymentOrder::where('status', 'released')->count())->color('success'),
            Stat::make('Duplicate bank IDs', BankTransaction::whereNotNull('duplicate_of_id')->count())->color('danger'),
        ];
    }
}
