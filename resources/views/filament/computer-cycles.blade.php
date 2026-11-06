@php
    $current = $computer->effectivePeriod();
    $next = $computer->nextPeriod();
    $sale = $computer->periods->sortBy('starts_on')->first(fn ($period) => $period->operation->kind === 'initial')?->operation;
    $upgrades = $computer->company->renewals->where('kind', 'upgrade')->filter(fn ($operation) => collect($operation->affected_periods)->contains(fn ($affected) => $computer->periods->contains('id', $affected['period_id'])))->sortByDesc('occurred_on');
    $renewals = $computer->renewalCount();
    $interactive = $interactive ?? false;
@endphp
<div class="bl-commercial-detail">
    @if ($current)
        <dl class="bl-commercial-summary">
            <div><dt>Vendita di appartenenza</dt><dd>{{ $sale ? $sale->reference.' · '.$sale->label : 'Non collegata' }}@if ($sale?->occurred_on) · {{ $sale->occurred_on->format('d/m/Y') }}@endif</dd></div>
            <div><dt>Periodo attuale · {{ $current->operation->reference }}</dt><dd>{{ $current->starts_on->format('d/m/Y') }} → {{ $current->expires_on?->format('d/m/Y') ?? 'Da completare' }}</dd></div>
            @if ($next && $next->id !== $current->id)
                <div><dt>Prossimo periodo · {{ $next->operation->reference }}</dt><dd>{{ $next->starts_on->format('d/m/Y') }} → {{ $next->expires_on?->format('d/m/Y') ?? 'Da completare' }}</dd></div>
            @endif
        </dl>
    @elseif ($computer->hasTransferredLicense())
        <p>Licenza trasferita al computer sostitutivo.</p>
    @else
        <p>Da associare a una vendita o a un rinnovo.</p>
    @endif

    @if ($interactive)
        <div class="fi-ac fi-wrapped">
            @if ($sale)
                <x-filament::button color="gray" icon="heroicon-o-pencil-square" wire:click="mountAction('edit', {}, { table: true, recordKey: '{{ $sale->id }}' })">Modifica vendita {{ $sale->reference }}</x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-arrows-right-left" wire:click="mountAction('moveComputers', {}, { table: true, recordKey: '{{ $sale->id }}' })">Cambia vendita</x-filament::button>
            @endif
            @if ($current && $current->operation->id !== $sale?->id)
                <x-filament::button color="gray" icon="heroicon-o-pencil-square" wire:click="mountAction('edit', {}, { table: true, recordKey: '{{ $current->operation->id }}' })">Modifica {{ $current->operation->reference }}</x-filament::button>
            @endif
            <x-filament::button icon="heroicon-o-link" :disabled="$computer->periods->contains('expires_on', null)" wire:click="mountAction('associateRenewal', {}, { table: true })">Associa a rinnovo</x-filament::button>
            <x-filament::button color="gray" icon="heroicon-o-plus" wire:click="mountAction('newSale', {}, { table: true })">{{ $current ? 'Nuova vendita aziendale' : 'Nuova vendita' }}</x-filament::button>
        </div>
        @if ($current && $computer->periods->contains('expires_on', null))
            <p class="bl-commercial-note">Completa la scadenza del periodo prima di rinnovare.</p>
        @endif
    @endif

    @if ($computer->periods->isNotEmpty() || $computer->originPeriods->isNotEmpty())
        <details>
            <summary class="bl-commercial-title">Storico · {{ $renewals }} {{ $renewals === 1 ? 'rinnovo' : 'rinnovi' }}</summary>
            <ol class="bl-cycle-list">
                @foreach ($computer->periods->sortByDesc('starts_on') as $period)
                    <li>
                        <div><strong>{{ $period->operation->reference }} · {{ $period->operation->label }}</strong>
                            @if ($period->operation->detection_month)<p class="bl-commercial-note">Rilevata da GravityZone</p>@endif
                            @if ($period->product)<p class="bl-commercial-note">{{ $period->product }}</p>@endif
                            @if ($period->operation->invoice_reference)<p class="bl-commercial-note">Rif. fattura: {{ $period->operation->invoice_reference }}</p>@endif
                            @if ($interactive)
                                <x-filament::link tag="button" type="button" wire:click="mountAction('edit', {}, { table: true, recordKey: '{{ $period->operation->id }}' })">Modifica {{ $period->operation->reference }}</x-filament::link>
                            @endif
                        </div>
                        <span>{{ $period->starts_on->format('d/m/Y') }} → {{ $period->expires_on?->format('d/m/Y') ?? 'Da completare' }}</span>
                    </li>
                @endforeach
            </ol>
            @if ($upgrades->isNotEmpty())
                <h3 class="bl-commercial-title">Aggiornamenti aziendali</h3>
                <ul class="bl-cycle-list">
                    @foreach ($upgrades as $upgrade)
                        @php($affected = collect($upgrade->affected_periods)->first(fn ($row) => $computer->periods->contains('id', $row['period_id'])))
                        <li><div><strong>{{ $upgrade->reference }} · {{ ($affected['product'] ?? $upgrade->product) ?: 'Licenza non disponibile' }}</strong>
                            <p class="bl-commercial-note">Da {{ $affected['previous_product'] ?: 'licenza non disponibile' }}</p></div>
                            <span>Dal {{ $upgrade->occurred_on->format('d/m/Y') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($computer->periods->isEmpty() && $computer->originPeriods->isNotEmpty())
                <ul class="bl-cycle-list">
                    @foreach ($computer->originPeriods as $period)
                        <li><strong>{{ $period->operation->reference }}</strong><span>{{ $period->starts_on->format('d/m/Y') }} → {{ $period->expires_on?->format('d/m/Y') ?? 'Da completare' }} · trasferito al computer #{{ $period->computer_id }}</span></li>
                    @endforeach
                </ul>
            @endif
        </details>
    @endif
    @if ($interactive)
        <x-filament-actions::modals />
    @endif
</div>
