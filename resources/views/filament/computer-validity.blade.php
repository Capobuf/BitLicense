@php($computer = $getRecord())
<div class="bl-computer-validity">
    <p class="bl-computer-activation"><span class="fi-sr-only">Attivazione: </span>{{ $computer->activated_on?->format('d/m/Y') ?? 'Da completare' }}</p>
    <p><span class="fi-sr-only">Scadenza: </span>{{ $computer->expires_on?->format('d/m/Y') ?? 'Da completare' }}</p>
</div>
