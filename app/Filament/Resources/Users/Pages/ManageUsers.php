<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected static ?string $title = 'Impostazioni';

    protected static ?string $breadcrumb = 'Utenti';

    protected ?string $subheading = 'Crea gli accessi interni e disabilita quelli non più necessari. Per i tuoi dati e la password usa il profilo personale.';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->createAnother(false)];
    }
}
