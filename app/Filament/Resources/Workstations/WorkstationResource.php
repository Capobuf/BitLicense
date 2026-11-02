<?php

namespace App\Filament\Resources\Workstations;

use App\Filament\Resources\Workstations\Pages\ManageWorkstations;
use App\Models\Company;
use App\Models\Workstation;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class WorkstationResource extends Resource
{
    protected static ?string $model = Workstation::class;

    protected static ?string $slug = 'postazioni';

    protected static ?string $modelLabel = 'postazione';

    protected static ?string $pluralModelLabel = 'postazioni';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('company_id')->label('Azienda')->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required()->searchable()->exists(Company::class, 'id')
                ->disabled(fn (string $operation): bool => $operation === 'edit')->saved(fn (string $operation): bool => $operation === 'create'),
            TextInput::make('name')->label('Nome locale')->required()->maxLength(255),
            Textarea::make('notes')->label('Note')->maxLength(10000)->rows(3)->columnSpanFull(),
            TextEntry::make('endpoint.name')->label('Nome tecnico GravityZone')->placeholder('Postazione manuale, nessun endpoint associato')
                ->visible(fn (string $operation): bool => $operation === 'edit'),
            TextEntry::make('endpoint.company.name')->label('Azienda GravityZone')->placeholder('Nessun endpoint associato')
                ->visible(fn (string $operation): bool => $operation === 'edit'),
            TextEntry::make('association_warning')->label('Associazione da verificare')->columnSpanFull()
                ->state('L’endpoint risulta in un’altra azienda GravityZone. I dati della postazione locale sono conservati.')
                ->visible(fn (?Workstation $record): bool => $record?->endpoint !== null && $record->company_id !== $record->endpoint->company_id),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['company', 'endpoint.company']))
            ->columns([
                TextColumn::make('name')->label('Nome locale')->searchable()->sortable()->wrap()
                    ->description(fn (Workstation $record): string => '#'.$record->id.' · '.$record->company->name),
                TextColumn::make('endpoint.name')->label('Nome tecnico GravityZone')->placeholder('Postazione manuale')->wrap(),
                TextColumn::make('association')->label('Associazione')->badge()
                    ->state(fn (Workstation $record): string => $record->endpoint === null ? 'Manuale' : ($record->company_id !== $record->endpoint->company_id ? 'Da verificare: azienda diversa' : ($record->endpoint->is_present ? 'Endpoint rilevato' : 'Endpoint non rilevato')))
                    ->color(fn (Workstation $record): string => $record->endpoint !== null && (! $record->endpoint->is_present || $record->company_id !== $record->endpoint->company_id) ? 'warning' : 'gray')->wrap(),
            ])
            ->filters([SelectFilter::make('company')->label('Azienda')->relationship('company', 'name')->searchable()->preload()])
            ->recordActions([EditAction::make()->mutateDataUsing(fn (array $data): array => Arr::only($data, ['name', 'notes']))])
            ->emptyStateHeading('Nessuna postazione trovata')->emptyStateDescription('Crea una postazione manuale oppure associala da Endpoint GravityZone.')->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageWorkstations::route('/')];
    }
}
