<?php

namespace App\Filament\Resources\Coverages\Pages;

use App\Filament\Resources\Coverages\CoverageResource;
use Filament\Resources\Pages\ManageRecords;
use Livewire\Attributes\Locked;

class ManageCoverages extends ManageRecords
{
    protected static string $resource = CoverageResource::class;

    protected ?string $subheading = 'Periodi commerciali acquistati per le singole postazioni, indipendenti dalla scadenza tecnica aziendale GravityZone. Il trasferimento a un PC sostitutivo conserva periodo, importo e storico.';

    #[Locked]
    public array $expectedAssignments = [];

    protected function getHeaderActions(): array
    {
        return [CoverageResource::createAction()];
    }
}
