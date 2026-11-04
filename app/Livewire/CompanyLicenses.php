<?php

namespace App\Livewire;

use App\Filament\Pages\Licenze;
use App\Models\Company;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;

class CompanyLicenses extends Licenze
{
    protected string $view = 'livewire.company-licenses';

    protected bool $showCompanyName = true;

    #[Locked]
    public ?array $data = [];

    public function mount(?int $companyId = null, array $filters = []): void
    {
        parent::mount();
        $this->data = ['company_id' => Company::findOrFail($companyId)->id];
        if ($filters !== []) {
            $this->tableFilters = $filters;
        }
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->identifier(fn (): string => 'company'.$this->selectedCompanyId());
    }

    protected function queryString(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [$this->createAction()];
    }
}
