@php
    $selected = $this->data['company_id'] ?? null;
    $license = $this->data['license'] ?? null;
    $current = $selected === 'all' ? 'Tutte le aziende' : ($companies->firstWhere('id', $selected)?->name ?? 'Scegli un’azienda');
@endphp
<nav
    class="bl-picker"
    aria-label="Selezione azienda"
    x-data="{ open: false, search: '', normalize: (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase() }"
    x-bind:class="{ 'bl-picker-open': open }"
>
    <button type="button" class="bl-picker-toggle" x-on:click="open = ! open" x-bind:aria-expanded="open">
        <x-filament::icon icon="heroicon-o-building-office-2" class="bl-company-icon" />
        <span>{{ $current }}@if (filled($license)) <span class="bl-picker-muted">· {{ Str::afterLast($license, ' · ') }}</span>@endif</span>
        <x-filament::icon icon="heroicon-m-chevron-down" class="bl-picker-toggle-chevron" />
    </button>

    <div class="bl-picker-panel">
        @if ($licenses->isNotEmpty())
            <div class="bl-picker-licenses" role="group" aria-label="Licenza aziendale GravityZone">
                @foreach ([null => 'Tutte'] + $licenses->mapWithKeys(fn (string $product): array => [$product => Str::afterLast($product, ' · ')])->all() as $value => $label)
                    @php($active = (string) $license === (string) $value)
                    <x-filament::button
                        size="xs"
                        :color="$active ? 'primary' : 'gray'"
                        :outlined="! $active"
                        :aria-pressed="$active ? 'true' : 'false'"
                        :title="$value ?: 'Tutte le licenze'"
                        wire:click="$set('data.license', {{ Js::from($value ?: null) }})"
                    >{{ $label }}</x-filament::button>
                @endforeach
            </div>
        @endif

        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass" class="bl-picker-search">
            <x-filament::input type="search" x-model="search" placeholder="Cerca azienda" aria-label="Cerca azienda" />
        </x-filament::input.wrapper>

        <ul class="bl-picker-list">
            <li x-show="! search">
                <button type="button" wire:click="$set('data.company_id', 'all')" x-on:click="open = false"
                    @class(['bl-picker-item', 'bl-picker-item-active' => $selected === 'all']) @if ($selected === 'all') aria-current="true" @endif>
                    <x-filament::icon icon="heroicon-o-squares-2x2" class="bl-picker-all-icon" />
                    <span class="bl-picker-name">Tutte le aziende</span>
                    <span class="bl-picker-count">{{ $companies->sum('computers_count') }}<span class="fi-sr-only"> computer</span></span>
                </button>
            </li>
            @forelse ($companies as $company)
                @php([$status, $color] = $company->technicalStatus())
                <li wire:key="picker-{{ $company->id }}" data-name="{{ $company->name }}" x-show="normalize($el.dataset.name).includes(normalize(search))">
                    <button type="button" wire:click="$set('data.company_id', {{ $company->id }})" x-on:click="open = false"
                        @class(['bl-picker-item', 'bl-picker-item-active' => (string) $selected === (string) $company->id]) @if ((string) $selected === (string) $company->id) aria-current="true" @endif>
                        <span class="bl-status-dot bl-status-{{ $color }}" title="Licenza aziendale: {{ $status }}" aria-hidden="true"></span>
                        <span class="bl-picker-name">{{ $company->name }}<span class="fi-sr-only">, licenza aziendale {{ $status }}</span></span>
                        <span class="bl-picker-count">{{ $company->computers_count }}<span class="fi-sr-only"> computer</span></span>
                    </button>
                </li>
            @empty
                <li class="bl-picker-muted bl-picker-empty">Nessuna azienda con questa licenza.</li>
            @endforelse
        </ul>
    </div>
</nav>
