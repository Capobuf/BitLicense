<?php

namespace App\Filament\Resources\Companies;

use App\Filament\Actions\CompanyRenewalsAction;
use App\Filament\Pages\Licenze;
use App\Filament\Resources\Companies\Pages\ManageCompanies;
use App\Models\Company;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'aziende';

    protected static ?string $modelLabel = 'azienda';

    protected static ?string $pluralModelLabel = 'aziende';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = -1;

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['computers' => fn (Builder $query): Builder => $query->detected(), 'renewals' => fn (Builder $query): Builder => $query->where('is_regrouped', false)])->withMax('renewals', 'expires_on'))
            ->columns([
                TextColumn::make('name')->label('Azienda')->searchable()->sortable()->wrap()->weight('semibold'),
                TextColumn::make('technical_product')->label('Licenza GravityZone')->wrap()->placeholder('Non disponibile'),
                TextColumn::make('computers_count')->label('Computer')->numeric()->sortable(),
                TextColumn::make('renewals_count')->label('Operazioni')->numeric()->sortable(),
                TextColumn::make('renewals_max_expires_on')->label('Ultima scadenza registrata')->date()->placeholder('Nessun periodo')->sortable(),
            ])
            ->recordAction('companyRenewals')->stackedOnMobile()->defaultSort('name')
            ->recordActions([
                CompanyRenewalsAction::make(),
                Action::make('computers')->label('Computer')->icon(Heroicon::OutlinedComputerDesktop)
                    ->url(fn (Company $record): string => Licenze::getUrl(['company' => $record->id])),
            ])
            ->emptyStateHeading('Nessuna azienda disponibile')
            ->emptyStateDescription('Le aziende compaiono dopo la sincronizzazione da GravityZone.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCompanies::route('/')];
    }
}
