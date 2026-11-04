<x-filament::icon-button
    icon="heroicon-o-information-circle"
    color="gray"
    size="sm"
    :label="($getRecord()?->gravityzoneLicenseDisplay()['description'] ?? '').' Stato tecnico indipendente dalle date commerciali. Una licenza attiva non certifica che tutti i moduli di protezione siano attivi.'"
    title=""
    x-tooltip="{ content: $el.getAttribute('aria-label'), theme: $store.theme, trigger: 'mouseenter focus click' }"
/>
