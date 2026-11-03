<div class="fi-ta-header" style="display: block">
    @if ($company)
        <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between">
            <div>
                <h2 class="fi-ta-header-heading">{{ $company->name }}</h2>
                <p class="fi-ta-header-description">GravityZone · licenza tecnica aziendale · {{ $company->technical_license_type ?? 'Tipo non disponibile' }}</p>
            </div>
            <x-filament::badge :color="$company->technical_expires_on?->lt(today('Europe/Rome')) ? 'danger' : ($company->technical_expires_on?->lte(today('Europe/Rome')->addDays(30)) ? 'warning' : 'info')" size="lg">
                Scadenza tecnica: {{ $company->technical_expires_on?->format('d/m/Y') ?? 'Non disponibile' }}
                @if ($company->technical_expires_on?->lt(today('Europe/Rome'))) · Scaduta
                @elseif ($company->technical_expires_on?->lte(today('Europe/Rome')->addDays(30))) · In scadenza
                @endif
            </x-filament::badge>
        </div>
        <p class="fi-ta-header-description">La data tecnica è in sola lettura. Ogni computer ha la propria scadenza commerciale nella tabella.</p>
        @if (! $company->is_present)
            <p role="status">Azienda non più rilevata in GravityZone. Dati locali conservati.</p>
        @endif
        @foreach (array_filter([$company->sync_error, $company->endpoints_sync_error]) as $error)
            <p role="status">{{ $error }} I dati mostrati sono gli ultimi disponibili.</p>
        @endforeach
    @else
        <h2 class="fi-ta-header-heading">I computer della tua azienda</h2>
    @endif
    @if (! $settings)
        <p class="fi-ta-header-description">GravityZone non configurato. Un amministratore può configurarlo da Impostazioni → API.</p>
    @else
        <p class="fi-ta-header-description">Ultima sincronizzazione completa dei computer: {{ $settings->endpoints_synced_at?->format('d/m/Y H:i') ?? 'mai' }}.</p>
        @foreach (array_filter([$settings->companies_sync_error, $settings->endpoints_sync_error]) as $error)
            <p role="status">{{ $error }}</p>
        @endforeach
    @endif
</div>
