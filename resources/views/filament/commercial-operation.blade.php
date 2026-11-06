<div class="bl-commercial-detail">
    @if ($operation->detection_month)
        <p class="bl-commercial-note">Vendita riconosciuta dai primi rilevamenti GravityZone: {{ substr($operation->detection_month, 5) }}/{{ substr($operation->detection_month, 0, 4) }}. Raggruppamento modificabile con «Sposta PC»; ogni computer conserva le proprie date.</p>
    @endif
    @if ($operation->is_regrouped)
        <p class="bl-commercial-note">Questo raggruppamento non contiene più computer. I riferimenti e lo storico restano conservati.</p>
    @endif
    <dl class="bl-commercial-summary">
        @if ($operation->kind === 'renewal')
            <div><dt>Licenze rinnovate</dt><dd>{{ $operation->quantity }}</dd></div>
            <div><dt>Computer associati</dt><dd>{{ $operation->periods->count() }}</dd></div>
            <div><dt>Licenze disponibili</dt><dd>{{ max(0, $operation->quantity - $operation->periods->count()) }}</dd></div>
            <div><dt>Validità del rinnovo</dt><dd>{{ $operation->starts_on?->format('d/m/Y') }} → {{ $operation->expires_on?->format('d/m/Y') ?? 'Da completare' }}</dd></div>
        @endif
        <div><dt>Data operazione</dt><dd>{{ $operation->occurred_on?->format('d/m/Y') ?? 'Non registrata' }}</dd></div>
        <div><dt>{{ $operation->kind === 'upgrade' ? 'Differenza complessiva' : 'Totale operazione' }}</dt><dd>{{ $operation->price_cents === null ? 'Non indicato' : '€ '.str_replace('.', ',', \App\Support\Euro::fromCents($operation->price_cents)) }}</dd></div>
        <div><dt>Riferimento fattura</dt><dd>{{ $operation->invoice_reference ?: 'Non indicato' }}</dd></div>
        <div><dt>Riferimento preventivo</dt><dd>{{ $operation->quote_reference ?: 'Non indicato' }}</dd></div>
    </dl>
    @if ($operation->kind === 'upgrade')
        <p class="bl-commercial-note">Upgrade aziendale dal {{ $operation->occurred_on?->format('d/m/Y') }}. Le scadenze dei periodi interessati restano invariate. Non costituisce un rinnovo.</p>
        <ul class="bl-cycle-list">
            @foreach ($operation->affected_periods ?? [] as $affected)
                <li>
                    <div><strong>{{ $affected['computer_name'] }}</strong>
                        <p class="bl-commercial-note">{{ $affected['previous_product'] ?: 'Licenza precedente non disponibile' }} → {{ ($affected['product'] ?? $operation->product) ?: 'Non disponibile' }}</p>
                    </div>
                    <span>Residuo: {{ $operation->occurred_on->format('d/m/Y') }} → {{ \Carbon\CarbonImmutable::parse($affected['expires_on'])->format('d/m/Y') }}</span>
                </li>
            @endforeach
        </ul>
    @elseif ($operation->periods->isNotEmpty())
        <h3 class="bl-commercial-title">Computer e validità acquistata</h3>
        <ul class="bl-cycle-list">
            @foreach ($operation->periods as $period)
                <li>
                    <div><strong>{{ $period->computer?->display_name ?? 'Computer non disponibile' }}</strong>
                        <p class="bl-commercial-note">Licenza registrata: {{ $period->product ?: 'Non disponibile' }}</p>
                        @if ($period->origin_computer_id !== $period->computer_id)
                            <p class="bl-commercial-note">Licenza trasferita da {{ $period->originComputer?->display_name ?? '#'.$period->origin_computer_id }}</p>
                        @endif
                    </div>
                    <span>{{ $period->starts_on->format('d/m/Y') }} → {{ $period->expires_on?->format('d/m/Y') ?? 'Da completare' }}</span>
                </li>
            @endforeach
        </ul>
        @if ($operation->quantity > $operation->periods->count())
            <p class="bl-commercial-note">{{ $operation->quantity - $operation->periods->count() }} licenze non collegate a un computer. Lo storico mostra solo i collegamenti registrati.</p>
        @endif
    @elseif ($operation->kind === 'renewal' && $operation->occurred_on !== null)
        <p class="bl-commercial-note">Nessun computer associato. Puoi aggiungerli da «Gestisci rinnovo e computer» o dalla scheda del singolo PC.</p>
    @else
        <p class="bl-commercial-note">Registro aziendale senza collegamenti ai computer: {{ $operation->starts_on?->format('d/m/Y') }} → {{ $operation->expires_on?->format('d/m/Y') }}, {{ $operation->quantity }} licenze. I computer coinvolti non sono ricostruiti automaticamente.</p>
    @endif
    @php
        $moves = collect($operation->history)->where('event', 'regrouped');
        $sales = $moves->isEmpty() ? collect() : \App\Models\CompanyRenewal::where('company_id', $operation->company_id)
            ->whereKey($moves->pluck('source_id')->merge($moves->pluck('target_id'))->unique())->get()->keyBy('id');
    @endphp
    @foreach ($moves as $change)
        <p class="bl-commercial-note">{{ \Carbon\CarbonImmutable::parse($change['recorded_at'])->timezone('Europe/Rome')->format('d/m/Y H:i') }} · {{ count($change['periods']) }} PC spostati da {{ $sales[$change['source_id']]?->reference ?? '?' }} a {{ $sales[$change['target_id']]?->reference ?? '?' }}. Date individuali conservate.</p>
    @endforeach
</div>
