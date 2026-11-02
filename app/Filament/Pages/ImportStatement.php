<?php

namespace App\Filament\Pages;

use App\Services\PaymentWorkflow;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Livewire\WithFileUploads;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Filament\Support\Icons\Heroicon;
use BackedEnum;

class ImportStatement extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.import-statement';

    protected static ?string $title = 'Import bank CSV';
    protected static ?string $navigationLabel = 'Import statement';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    public string $accountLabel = '';
    public ?TemporaryUploadedFile $statement = null;

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['admin', 'operator'], true);
    }

    public function import(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate([
            'accountLabel' => ['required', 'string', 'max:80'],
            'statement' => ['required', 'file', 'max:2048'],
        ]);
        $result = app(PaymentWorkflow::class)->importCsv(
            file_get_contents($this->statement->getRealPath()),
            $this->statement->getClientOriginalName(),
            $this->accountLabel,
            auth()->user(),
        );
        $this->reset('statement');
        Notification::make()->title("Imported {$result['credits']} credits; {$result['duplicates']} duplicates flagged")
            ->success()->send();
    }
}
