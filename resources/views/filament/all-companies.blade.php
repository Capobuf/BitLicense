<div class="bl-all-companies">
    <x-filament-actions::modals />
    @forelse ($companies as $company)
        @livewire(\App\Livewire\CompanyLicenses::class, ['companyId' => $company->id, 'filters' => $this->tableFilters ?? []], key('company-licenses-'.$company->id))
    @empty
        <x-filament::section>
            Nessuna azienda da mostrare.
        </x-filament::section>
    @endforelse
</div>
