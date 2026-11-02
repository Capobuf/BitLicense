<?php

namespace App\Filament\Resources\Workstations\Pages;

use App\Filament\Resources\Workstations\WorkstationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Arr;

class ManageWorkstations extends ManageRecords
{
    protected static string $resource = WorkstationResource::class;

    protected ?string $subheading = 'Postazioni locali e nomi commerciali sono indipendenti dai nomi tecnici GravityZone. L’associazione a un endpoint richiede una scelta esplicita nella pagina Endpoint GravityZone.';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuova postazione')->modalHeading('Nuova postazione')->modalSubmitActionLabel('Crea postazione')->createAnother(false)->mutateDataUsing(fn (array $data): array => Arr::only($data, ['company_id', 'name', 'notes', 'company_product_id', 'commercial_description', 'installed_on']))];
    }
}
