<div class="bl-commercial-detail">
    @php
        $unlinked = $this->computers->filter(fn ($pc) => $pc->periods->isEmpty() && ! $pc->hasTransferredLicense())->count();
        $due = $this->computers->filter(fn ($pc) => $pc->expires_on !== null && $pc->expires_on->lte(today('Europe/Rome')->addDays(30)) && $pc->nextPeriod() === null)->count();
    @endphp
    <dl class="bl-commercial-summary bl-commercial-overview">
        <div><dt>Da rinnovare · entro 30 giorni o scadute</dt><dd>{{ $due }}</dd></div>
        <div><dt>Computer da associare</dt><dd>{{ $unlinked }}</dd></div>
    </dl>
    @if ($computerId)
        <p class="bl-commercial-note">Stai consultando le operazioni dell’intera azienda. Una nuova vendita o un rinnovo preseleziona {{ $this->computers->find($computerId)?->display_name }}.</p>
    @endif
    {{ $this->table }}
</div>
