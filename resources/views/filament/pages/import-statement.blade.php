<x-filament-panels::page>
    <style>
        .pm-import-form { display: grid; gap: 1.25rem; }
        .pm-import-field { display: grid; gap: .5rem; }
        .pm-import-field label { display: block; font-size: .875rem; font-weight: 600; }
        .pm-import-field input[type="text"] { width: 100%; min-height: 2.75rem; padding: .625rem .75rem; border: 1px solid #9ca3af; border-radius: .625rem; background: transparent; color: inherit; font: inherit; }
        .pm-import-field input[type="file"] { display: block; width: 100%; font: inherit; font-size: .875rem; }
        .pm-import-field input:focus-visible { outline: 2px solid #0284c7; outline-offset: 2px; }
        .pm-import-help { max-width: 65ch; font-size: .875rem; line-height: 1.55; color: #6b7280; }
        .pm-import-error { color: #dc2626; font-size: .875rem; }
        .dark .pm-import-field input[type="text"] { border-color: #6b7280; }
        .dark .pm-import-help { color: #a1a1aa; }
    </style>
    <x-filament::section>
        <x-slot name="heading">Bank record import</x-slot>
        <x-slot name="description">Import a CSV obtained from your bank’s official channel. A candidate match never confirms receipt or releases dispatch.</x-slot>
        <form wire:submit="import" class="pm-import-form">
            <div class="pm-import-field">
                <label for="accountLabel">Bank account label</label>
                <input id="accountLabel" type="text" wire:model="accountLabel" maxlength="80" required placeholder="Business account 01">
                @error('accountLabel') <p class="pm-import-error">{{ $message }}</p> @enderror
            </div>
            <div class="pm-import-field">
                <label for="statement">CSV statement</label>
                <input id="statement" type="file" wire:model="statement" accept=".csv,text/csv" required>
                @error('statement') <p class="pm-import-error">{{ $message }}</p> @enderror
            </div>
            <p class="pm-import-help">Columns: date, transaction_id, reference, amount, type. Only credit rows are imported. Maximum 2 MB and 5,000 rows.</p>
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="statement,import">Import transactions</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
