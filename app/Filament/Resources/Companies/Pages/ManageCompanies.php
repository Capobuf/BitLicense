<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Actions\SynchronizeGravityZoneAction;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Resources\Pages\ManageRecords;
use Livewire\Attributes\On;

class ManageCompanies extends ManageRecords
{
    protected static string $resource = CompanyResource::class;

    protected ?string $subheading = 'Consulta vendite, rinnovi e aggiornamenti delle licenze aziendali.';

    public function mount(): void
    {
        parent::mount();
        SynchronizeGravityZoneAction::notifyRequestedOutcome();
    }

    protected function getHeaderActions(): array
    {
        return [SynchronizeGravityZoneAction::make()];
    }

    #[On('company-renewals-updated')]
    public function refreshPeriods(): void
    {
        $this->flushCachedTableRecords();
    }
}
