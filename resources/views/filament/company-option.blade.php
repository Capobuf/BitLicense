<span class="bl-company-option">
    <x-filament::icon :icon="$company ? 'heroicon-o-building-office' : 'heroicon-o-building-office-2'" class="bl-company-icon" />
    <span>
        <span>{{ $company?->name ?? 'Tutte le Aziende' }}</span>
        @if ($company)
            <span class="bl-company-option-meta">
                — {{ match ($company->is_suspended) { true => 'Sospesa', false => 'Attiva', default => 'Stato non disponibile' } }}@if ($company->is_suspended !== null && (filled($company->sync_error) || ! $company->is_present)) (ultimo stato rilevato)@endif
                — Scadenza tecnica: {{ $company->technical_expires_on?->format('d/m/Y') ?? 'Non disponibile' }}
            </span>
        @endif
    </span>
</span>
