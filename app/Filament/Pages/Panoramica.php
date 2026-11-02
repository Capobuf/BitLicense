<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Panoramica extends Dashboard
{
    protected static ?string $title = 'Panoramica';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmptyState::make('Benvenuto in BitLicense')
                ->description('Il tuo spazio per seguire le coperture commerciali e i rinnovi Bitdefender.')
                ->icon(Heroicon::OutlinedShieldCheck),
        ]);
    }
}
