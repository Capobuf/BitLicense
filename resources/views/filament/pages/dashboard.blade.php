<x-filament-panels::page>
    @php
        $computerTotal = array_sum($statuses);
        $colors = ['Attiva' => 'var(--success-500)', 'In scadenza' => 'var(--warning-500)', 'Scaduta' => 'var(--danger-500)', 'Da completare' => 'var(--gray-400)', 'Da attivare' => 'var(--primary-500)'];
        $statusFilters = ['Attiva' => 'active', 'In scadenza' => 'expiring', 'Scaduta' => 'expired', 'Da completare' => 'missing', 'Da attivare' => 'future'];
        $monthMaximum = max(1, max(array_column($months, 'count')));
    @endphp
    <div class="bl-dashboard">
        <section class="bl-dash-hero" aria-labelledby="dashboard-title">
            <div class="bl-dash-hero-content">
                <div class="bl-dash-eyebrow"><span class="bl-dash-dot"></span> BITLICENSE <span>/</span> PANORAMICA</div>
                <h1 id="dashboard-title">Ogni scadenza.<br><span>Una visione chiara.</span></h1>
                <p>Aziende, computer e prossime scadenze.<br>Il punto di partenza per la tua giornata.</p>
                <div class="bl-dash-hero-actions">
                    <x-filament::button tag="a" :href="\App\Filament\Pages\Licenze::getUrl()" icon="heroicon-o-arrow-right" icon-position="after">Apri Licenze</x-filament::button>
                    <span class="bl-dash-date">{{ $today->locale('it')->translatedFormat('d F Y') }}</span>
                </div>
            </div>
            <div class="bl-dash-orbit" aria-hidden="true">
                <div class="bl-dash-orbit-inner"><x-filament::icon icon="heroicon-o-shield-check" /></div>
                <span class="bl-dash-orbit-point"></span>
                <span class="bl-dash-orbit-label">AZIENDE + COMPUTER</span>
            </div>
        </section>

        <x-filament::section>
            <div class="bl-dash-controls">
                <div>
                    <label for="dashboard-period">Periodo scadenze</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="dashboard-period" wire:model.live="period">
                            @foreach (\App\Filament\Pages\Dashboard::PERIODS as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <p class="bl-dash-caption">Dal {{ $today->format('d/m/Y') }} al {{ $horizon->format('d/m/Y') }}, inclusi.<br>Il periodo filtra le scadenze tecniche delle aziende e il grafico delle scadenze commerciali.</p>
            </div>
        </x-filament::section>

        <div class="bl-dash-metrics" role="group" aria-label="Riepilogo generale">
            {{ $stats }}
        </div>

        <div class="bl-dash-charts">
            <x-filament::section class="bl-dash-card" heading="Stato delle licenze" description="Computer · Dati commerciali" icon="heroicon-o-chart-pie">
                <div class="bl-dash-status-chart">
                    <div class="bl-dash-donut" aria-hidden="true">
                        <svg viewBox="0 0 120 120">
                            <circle class="bl-dash-ring-track" cx="60" cy="60" r="49" fill="none" stroke-width="10" />
                            @php $offset = 0; @endphp
                            @foreach ($statuses as $status => $count)
                                @if ($count > 0)
                                    @php $percentage = $count / $computerTotal * 100; @endphp
                                    <a href="{{ \App\Filament\Pages\Licenze::getUrl(['company' => 'all', 'status' => $statusFilters[$status]]) }}" tabindex="-1"><circle cx="60" cy="60" r="49" fill="none" stroke="{{ $colors[$status] }}" stroke-width="10" pathLength="100" stroke-dasharray="{{ $percentage }} {{ 100 - $percentage }}" stroke-dashoffset="{{ -$offset }}" transform="rotate(-90 60 60)" /></a>
                                    @php $offset += $percentage; @endphp
                                @endif
                            @endforeach
                        </svg>
                        <div><strong>{{ number_format($computerTotal, 0, ',', '.') }}</strong><span>computer</span></div>
                    </div>
                    <ul class="bl-dash-legend">
                        @foreach ($statuses as $status => $count)
                            <li><a href="{{ \App\Filament\Pages\Licenze::getUrl(['company' => 'all', 'status' => $statusFilters[$status]]) }}"><span><i style="background: {{ $colors[$status] }}"></i>{{ $status }}</span><strong>{{ number_format($count, 0, ',', '.') }}</strong></a></li>
                        @endforeach
                    </ul>
                </div>
                <p class="bl-dash-caption">{{ $computerTotal ? 'Clicca uno stato per aprire i PC. “In scadenza” indica sempre i prossimi 30 giorni, incluso oggi, indipendentemente dal periodo selezionato.' : 'Nessun computer presente. I grafici si popoleranno con i tuoi dati.' }}</p>
            </x-filament::section>

            <x-filament::section class="bl-dash-card" heading="Scadenze in arrivo" description="Computer · Date commerciali registrate" icon="heroicon-o-chart-bar">
                <x-slot name="afterHeader"><x-filament::badge color="gray">{{ $periodLabel }}</x-filament::badge></x-slot>
                <div class="bl-dash-bars-scroll">
                    <div class="bl-dash-bars" style="--months: {{ count($months) }}" role="group" aria-label="Computer per mese di scadenza commerciale, clicca per aprire Licenze">
                        @foreach ($months as $month)
                            <a href="{{ \App\Filament\Pages\Licenze::getUrl(['company' => 'all', 'expires_from' => $month['from'], 'expires_until' => $month['until']]) }}" aria-label="{{ $month['label'] }}: {{ $month['count'] }} computer">
                                <span>{{ $month['count'] }}</span>
                                <div class="bl-dash-bar-track" aria-hidden="true"><i style="height: {{ $month['count'] / $monthMaximum * 100 }}%"></i></div>
                                <span class="bl-dash-month">{{ $month['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
                <p class="bl-dash-caption">Dal {{ $today->format('d/m/Y') }} al {{ $horizon->format('d/m/Y') }}. Clicca un mese per vedere i PC. Include le scadenze note anche se manca l’attivazione.</p>
            </x-filament::section>
        </div>

        <x-filament::section class="bl-dash-card bl-dash-companies" id="companies" heading="Scadenze tecniche delle aziende" icon="heroicon-o-building-office-2"
            description="GravityZone · Indipendenti dalle date commerciali dei PC. Clicca un’azienda per aprire Licenze.">
            <x-slot name="afterHeader"><x-filament::badge color="gray">{{ $companies->total() }} aziende</x-filament::badge></x-slot>
            <div class="bl-dash-controls bl-dash-company-filters">
                <div>
                    <label for="dashboard-company-search">Cerca azienda</label>
                    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input id="dashboard-company-search" type="search" placeholder="Nome azienda…" maxlength="255" wire:model.live.debounce.300ms="companySearch" />
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label for="dashboard-company-state">Mostra aziende</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="dashboard-company-state" wire:model.live="companyState">
                            @foreach (\App\Filament\Pages\Dashboard::COMPANY_STATES as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>
            @if ($companies->isNotEmpty())
                <div class="bl-dash-company-list">
                    @foreach ($companies as $company)
                        @php $days = $company->technical_expires_on ? (int) $today->diffInDays($company->technical_expires_on, false) : null; @endphp
                        <a class="bl-dash-company-row" href="{{ \App\Filament\Pages\Licenze::getUrl(['company' => $company->id]) }}">
                            <x-filament::icon icon="heroicon-o-building-office-2" class="bl-dash-company-avatar" />
                            <span class="bl-dash-company-detail"><strong>{{ $company->name }}</strong><span>{{ $company->computers_count }} computer
                                @if (! $company->is_present) · Non più rilevata @elseif ($company->is_suspended) · Sospesa @endif
                                @if ($company->sync_error) · Dati da verificare @endif
                            </span></span>
                            <span class="bl-dash-company-date">@if ($company->technical_expires_on)<time datetime="{{ $company->technical_expires_on->toDateString() }}">{{ $company->technical_expires_on->format('d/m/Y') }}</time>@else Non disponibile @endif<span>Scadenza tecnica</span></span>
                            <x-filament::badge :color="$days === null ? 'gray' : ($days < 0 ? 'danger' : ($company->technical_expires_on->lte($horizon) ? 'warning' : 'success'))">{{ $days === null ? 'Data mancante' : ($days < 0 ? 'Scaduta da '.abs($days).' g' : ($days === 0 ? 'Scade oggi' : 'Tra '.$days.' giorni')) }}</x-filament::badge>
                            <x-filament::icon icon="heroicon-o-arrow-up-right" class="bl-dash-company-arrow" />
                        </a>
                    @endforeach
                </div>
                @if ($companies->hasPages())
                    <div class="bl-dash-pagination"><x-filament::pagination :paginator="$companies" /></div>
                @endif
            @else
                <div class="bl-dash-empty"><x-filament::icon icon="heroicon-o-calendar-days" /><h3>{{ $companyTotals->total ? 'Nessuna azienda corrisponde ai filtri' : 'La tua panoramica parte da qui' }}</h3><p>{{ $companyTotals->total ? 'Modifica la ricerca, il periodo o scegli Tutte le aziende.' : 'Le aziende compariranno qui dopo la prima importazione da GravityZone.' }}</p></div>
            @endif
            <p class="bl-dash-caption bl-dash-company-note"><x-filament::icon icon="heroicon-o-information-circle" />Scadenza tecnica non disponibile: {{ (int) $companyTotals->unknown }}. Per vederle scegli “Tutte le aziende”.</p>
        </x-filament::section>
        <p class="bl-dash-footer">Panoramica dei dati locali disponibili · Le date tecniche e commerciali restano indipendenti.</p>
    </div>
</x-filament-panels::page>
