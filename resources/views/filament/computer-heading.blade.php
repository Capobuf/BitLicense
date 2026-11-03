<span style="display: inline-flex; align-items: center; gap: .5rem; max-width: 100%">
    <span style="overflow-wrap: anywhere">{{ ($computer->gravityzone_id === null ? $computer->company : $computer->gravityzoneCompany)?->name ?? 'Azienda non disponibile' }}</span>
    @if ($computer->gravityzone_id !== null)
        @php
            $lastSeen = 'Ultimo Rilevamento: '.($computer->last_seen_at?->timezone('Europe/Rome')->format('d/m/Y H:i') ?? 'Non disponibile');
        @endphp
        <x-filament::icon-button
            icon="heroicon-o-information-circle"
            color="gray"
            size="sm"
            :label="$lastSeen"
            title=""
            x-tooltip="{
                content: $el.getAttribute('aria-label'),
                theme: $store.theme,
                trigger: 'mouseenter focus click',
            }"
        />
    @endif
</span>
