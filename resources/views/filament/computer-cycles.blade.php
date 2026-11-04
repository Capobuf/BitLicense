@php
    $current = $computer->effectivePeriod();
    $next = $computer->nextPeriod();
    $upgrades = $computer->company->renewals->where('kind', 'upgrade')->filter(fn ($operation) => collect($operation->affected_periods)->contains(fn ($affected) => $computer->periods->contains('id', $affected['period_id'])))->sortByDesc('occurred_on');
    $renewals = $computer->periods->filter(fn ($period) => $period->operation->kind === 'renewal')->count();
@endphp
<div class="bl-commercial-detail">
    @if ($current)
        @if ($current->operation->detection_month)
            <p class="bl-commercial-note">Prima vendita riconosciuta dal rilevamento GravityZone. Il raggruppamento iniziale è per azienda e mese; puoi correggerlo da «Vendite e rinnovi».</p>
        @endif
        <dl class="bl-commercial-summary">
            <div><dt>Origine del periodo</dt><dd>{{ $current->operation->reference }} · {{ \App\Models\CompanyRenewal::KINDS[$current->operation->kind] }}</dd></div>
            <div><dt>Rinnovi registrati</dt><dd>{{ $renewals }}</dd></div>
            <div><dt>Licenza assegnata</dt><dd>{{ $computer->company_product ?: 'Non disponibile' }}</dd></div>
            <div><dt>Validità</dt><dd>{{ $current->starts_on->format('d/m/Y') }} → {{ $current->expires_on?->format('d/m/Y') ?? 'Da completare' }}</dd></div>
        </dl>
        @if ($next && $next->id !== $current->id)
            <p class="bl-commercial-note">Rinnovo già registrato: {{ $next->operation->reference }}, dal {{ $next->starts_on->format('d/m/Y') }} al {{ $next->expires_on?->format('d/m/Y') ?? 'Da completare' }}. Data di rinnovo: {{ $next->operation->occurred_on->format('d/m/Y') }}.</p>
        @else
            <p class="bl-commercial-note">Prossima scadenza da rinnovare: {{ $current->expires_on?->format('d/m/Y') ?? 'Da completare' }}. Nessun periodo successivo registrato.</p>
        @endif
        <p class="bl-commercial-note">Date e riferimenti provengono dall’operazione. Usa «Vendite e rinnovi» per registrare un nuovo periodo o correggere quello esistente.</p>
        <ol class="bl-cycle-list">
            @foreach ($computer->periods->sortByDesc('starts_on') as $period)
                <li>
                    <div><strong>{{ $period->operation->reference }} · {{ \App\Models\CompanyRenewal::KINDS[$period->operation->kind] }}</strong>
                        <p class="bl-commercial-note">Licenza registrata: {{ $period->product ?: 'Non disponibile' }}</p>
                        @if ($period->operation->invoice_reference)
                            <p class="bl-commercial-note">Rif. fattura: {{ $period->operation->invoice_reference }}</p>
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
                        <span>Dal {{ $upgrade->occurred_on->format('d/m/Y') }} · scadenza invariata</span>
                    </li>
                @endforeach
            </ul>
        @endif
    @else
        @if ($computer->hasTransferredLicense())
            <p>Licenza trasferita al computer sostitutivo.</p>
            <p class="bl-commercial-note">La validità corrente appartiene al sostituto. Lo storico del computer conserva i passaggi effettuati.</p>
        @else
            <p>Da associare a una vendita o a un rinnovo.</p>
            <p class="bl-commercial-note">Le date già presenti sono conservate. Per i computer GravityZone, il primo rilevamento identifica la prima vendita, raggruppata per azienda e mese.</p>
        @endif
    @endif
    @if ($computer->periods->isEmpty() && $computer->originPeriods->isNotEmpty())
        <h3 class="bl-commercial-title">Periodi trasferiti al sostitutivo</h3>
        <ul class="bl-cycle-list">
            @foreach ($computer->originPeriods as $period)
                <li><strong>{{ $period->operation->reference }}</strong><span>{{ $period->starts_on->format('d/m/Y') }} → {{ $period->expires_on?->format('d/m/Y') ?? 'Da completare' }} · trasferito al computer #{{ $period->computer_id }}</span></li>
            @endforeach
        </ul>
    @endif
</div>
