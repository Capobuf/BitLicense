@if ($company || ! $settings || filled($settings->companies_sync_error) || filled($settings->endpoints_sync_error))
    <div @class([
        'fi-ta-header bl-company-hero',
        'bl-company-hero-suspended' => $company?->is_suspended === true,
        'bl-company-hero-expiring' => $company?->is_suspended !== true
            && $company?->technical_expires_on?->betweenIncluded(today('Europe/Rome'), today('Europe/Rome')->addDays(30)),
    ])>
        @if ($company)
            <div class="bl-company-license">
                <div>
                    <h2 class="bl-company-name">
                        {{ $this->companyRenewalsAction->label($company->name)->link()->color('gray') }}
                    </h2>
                    <div class="bl-company-license-label">
                        <x-filament::icon icon="heroicon-o-shield-check" class="bl-company-icon" />
                        <span>Licenza Aziendale</span>
                        @if ($company->is_suspended)
                            <x-filament::badge color="danger">Sospesa</x-filament::badge>
                        @endif
                        <x-filament::icon-button
                            icon="heroicon-o-information-circle"
                            color="gray"
                            size="sm"
                            :label="'Ultimo aggiornamento dei computer: '.($company->endpoints_synced_at?->format('d/m/Y H:i') ?? 'Non disponibile')"
                            title=""
                            x-tooltip="{ content: $el.getAttribute('aria-label'), theme: $store.theme, trigger: 'mouseenter focus click' }"
                        />
                    </div>
                    <h2 class="bl-company-product">{{ $company->technical_product ?? 'Non fornito dall’API' }}</h2>
                    <p class="fi-ta-header-description">GravityZone · {{ $company->technical_license_type ?? 'Tipo non disponibile' }}</p>
                </div>
                @if ($showCompanyName ?? false)
                    <div class="bl-company-license-actions">
                        @foreach ($this->getCachedHeaderActions() as $action)
                            {{ $action }}
                        @endforeach
                        <x-filament::icon-button
                            icon="heroicon-m-chevron-up"
                            color="gray"
                            :label="'Comprimi '.$company->name"
                            wire:click="$parent.toggleCompany({{ $company->id }})"
                        />
                    </div>
                @endif
            </div>
            <dl class="bl-company-kpis" aria-label="Riepilogo aziendale">
                @foreach ($metrics as $label => $count)
                    <div>
                        <dt>{{ $label }}@if ($label === 'In scadenza') <span>· 30 giorni</span>@endif</dt>
                        <dd>{{ $count }}</dd>
                    </div>
                @endforeach
                <div class="bl-company-expiry">
                    <dt>{{ $company->technical_license_type === 'Mensile' ? 'Fine abbonamento' : 'Scadenza tecnica' }}</dt>
                    <dd>
                        {{ $company->technical_expires_on?->format('d/m/Y') ?? 'Non disponibile' }}
                        @if ($company->technical_expires_on?->lt(today('Europe/Rome')))
                            <x-filament::badge color="danger">Scaduta</x-filament::badge>
                        @endif
                    </dd>
                </div>
            </dl>
            @if (! $company->is_present)
                <p role="status">Azienda non più rilevata in GravityZone. Dati locali conservati.</p>
            @endif
            @if ($company->gravityzone_type === 0 && $company->can_manage_security === false)
                <p role="status">Questo partner non condivide la gestione della sicurezza con l’account API. Il suo inventario non viene letto.</p>
            @endif
            @foreach (array_filter([$company->sync_error, $company->endpoints_sync_error]) as $error)
                <p role="status">{{ $error }} I dati mostrati sono gli ultimi disponibili.</p>
            @endforeach
        @endif
        @if (! $settings)
            <p class="fi-ta-header-description">GravityZone non configurato. Un amministratore può configurarlo da Impostazioni → API.</p>
        @else
            @foreach (array_filter([$settings->companies_sync_error, $settings->endpoints_sync_error]) as $error)
                <p role="status">{{ $error }}</p>
            @endforeach
        @endif
    </div>
@endif
