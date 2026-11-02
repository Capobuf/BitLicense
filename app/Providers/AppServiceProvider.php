<?php

namespace App\Providers;

use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::configureUsing(fn (Schema $schema) => $schema
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i')
            ->defaultNumberLocale('it')
            ->defaultCurrency('EUR'));

        Table::configureUsing(fn (Table $table) => $table
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i')
            ->defaultNumberLocale('it')
            ->defaultCurrency('EUR'));

        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i'));
    }
}
