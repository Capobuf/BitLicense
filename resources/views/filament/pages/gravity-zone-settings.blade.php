<x-filament-panels::page>
    <form wire:submit="save" style="display: grid; gap: 1.5rem">
        {{ $this->form }}

        <p role="status">
            {{ $this->hasApiKey() ? 'Chiave configurata e protetta.' : 'Nessuna chiave configurata.' }}
        </p>

        <x-filament::button type="submit">Salva configurazione</x-filament::button>
    </form>
</x-filament-panels::page>
