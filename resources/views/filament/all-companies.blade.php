<div class="bl-all-companies">
    <x-filament-actions::modals />
    @forelse ($companies as $company)
        @if (in_array($company->id, $expanded, true))
            @livewire(\App\Livewire\CompanyLicenses::class, ['companyId' => $company->id, 'filters' => $this->navigationFilters], key('company-licenses-'.$company->id))
        @else
            @php
                [$status, $color] = $company->technicalStatus();
                $counts = $statuses[$company->id] ?? collect();
            @endphp
            <button type="button" class="bl-company-row" wire:key="company-row-{{ $company->id }}" wire:click="toggleCompany({{ $company->id }})" aria-expanded="false">
                <x-filament::icon icon="heroicon-m-chevron-right" class="bl-company-row-chevron" />
                <span class="bl-company-row-main">
                    <span class="bl-company-row-name">{{ $company->name }}</span>
                    <span class="bl-picker-muted">{{ $company->technical_product ?? 'Licenza non fornita dall’API' }}</span>
                </span>
                <span class="bl-company-row-expiry">
                    <span class="bl-picker-muted">{{ $company->technical_license_type === 'Mensile' ? 'Fine abbonamento' : 'Scadenza tecnica' }}</span>
                    <span>
                        <span class="bl-status-dot bl-status-{{ $color }}" aria-hidden="true"></span>
                        {{ $company->technical_expires_on?->format('d/m/Y') ?? 'Non disponibile' }}
                        @if ($color !== 'success')
                            <x-filament::badge :color="$color" size="sm">{{ $status }}</x-filament::badge>
                        @endif
                    </span>
                </span>
                <span class="bl-company-row-counts">
                    <span class="bl-picker-muted">Computer</span>
                    <span>
                    <strong>{{ $counts->sum() }}</strong>
                    @foreach (['Scaduta' => ['scadute', 'danger'], 'In scadenza' => ['in scadenza', 'warning'], 'Da completare' => ['da completare', 'gray']] as $key => [$label, $badge])
                        @if (($counts[$key] ?? 0) > 0)
                            <x-filament::badge :color="$badge" size="sm">{{ $counts[$key] }} {{ $label }}</x-filament::badge>
                        @endif
                    @endforeach
                    </span>
                </span>
            </button>
        @endif
    @empty
        <x-filament::section>
            Nessuna azienda da mostrare.
        </x-filament::section>
    @endforelse
</div>
