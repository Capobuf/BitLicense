<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'utenti';

    protected static ?string $modelLabel = 'utente';

    protected static ?string $pluralModelLabel = 'utenti';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nome')->required()->maxLength(255),
            TextInput::make('email')->label('Email')->email()->required()->maxLength(255)
                ->unique(ignoreRecord: true),
            Select::make('role')->label('Ruolo')->options(User::ROLES)
                ->default('operator')->required()->in(array_keys(User::ROLES)),
            Toggle::make('is_active')->label('Accesso abilitato')->default(true)->required(),
            TextInput::make('password')->label('Password')->password()->autocomplete('new-password')
                ->minLength(12)->maxLength(255)->confirmed()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->saved(fn (?string $state): bool => filled($state))
                ->helperText('Almeno 12 caratteri. Lascia vuoto in modifica per mantenere la password attuale.'),
            TextInput::make('password_confirmation')->label('Conferma password')
                ->password()->autocomplete('new-password')->saved(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable()->sortable(),
                TextColumn::make('role')->label('Ruolo')->badge()
                    ->formatStateUsing(fn (string $state): string => User::ROLES[$state]),
                IconColumn::make('is_active')->label('Accesso abilitato')->boolean(),
            ])
            ->filters([
                SelectFilter::make('role')->label('Ruolo')->options(User::ROLES),
                TernaryFilter::make('is_active')->label('Accesso abilitato'),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
