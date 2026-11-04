<?php

namespace App\Filament\Actions;

use App\Livewire\CompanyRenewals;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

class CompanyRenewalsAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'companyRenewals';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Vendite e rinnovi')->icon(Heroicon::OutlinedClock)->model(Company::class)
            ->authorize(fn (?Company $record): bool => $record !== null && Gate::allows('update', $record))
            ->modalHeading(fn (?Company $record): string => ($record ? $record->name.' · ' : '').'Vendite e rinnovi')
            ->modalDescription('Vendite, rinnovi e aggiornamenti, con periodi e storico per computer.')
            ->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Chiudi')
            ->schema(fn (?Company $record): array => $record === null ? [] : [
                Livewire::make(CompanyRenewals::class, ['companyId' => $record->id])->key('company-renewals-'.$record->id),
            ]);
    }
}
