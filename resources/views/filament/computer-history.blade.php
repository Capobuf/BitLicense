<div style="display: grid; gap: 1rem">
    @foreach (array_reverse($history) as $entry)
        <div>
            <strong>{{ \Carbon\CarbonImmutable::parse($entry['recorded_at'])->timezone('Europe/Rome')->format('d/m/Y H:i') }} · {{ match ($entry['event']) { 'transferred_out' => 'Trasferita al sostitutivo', 'transferred_in' => 'Ricevuta dal PC precedente', 'reconciled' => 'Collegamento GravityZone', default => 'Modifica dati commerciali' } }}</strong>
            @if ($entry['related_computer_id'] ?? null)
                <p>Computer #{{ $entry['related_computer_id'] }} · {{ $entry['related_computer_name'] ?? '' }}</p>
            @endif
            <p>Dati precedenti: {{ $entry['previous']['product'] ?? 'Prodotto non indicato' }} ·
                {{ isset($entry['previous']['activated_on']) ? \Carbon\CarbonImmutable::parse($entry['previous']['activated_on'])->format('d/m/Y') : 'Attivazione mancante' }} →
                {{ isset($entry['previous']['expires_on']) ? \Carbon\CarbonImmutable::parse($entry['previous']['expires_on'])->format('d/m/Y') : 'Scadenza mancante' }} ·
                {{ isset($entry['previous']['price_cents']) ? number_format($entry['previous']['price_cents'] / 100, 2, ',', '.').' €' : 'Prezzo non indicato' }}
            </p>
            @if ($entry['previous']['invoice_reference'] ?? null)<p>Fattura: {{ $entry['previous']['invoice_reference'] }}</p>@endif
            @if ($entry['previous']['quote_reference'] ?? null)<p>Preventivo: {{ $entry['previous']['quote_reference'] }}</p>@endif
        </div>
    @endforeach
</div>
