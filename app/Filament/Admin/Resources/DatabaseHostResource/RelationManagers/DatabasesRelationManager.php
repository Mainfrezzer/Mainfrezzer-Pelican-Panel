<?php

namespace App\Filament\Admin\Resources\DatabaseHostResource\RelationManagers;

use App\Models\Database;
use App\Services\Databases\DatabasePasswordService;
use App\Tables\Columns\DateTimeColumn;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DatabasesRelationManager extends RelationManager
{
    protected static string $relationship = 'databases';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('database')
                    ->columnSpanFull(),
                TextInput::make('username'),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->hintAction(
                        Action::make('rotate')
                            ->icon('tabler-refresh')
                            ->requiresConfirmation()
                            ->action(fn (DatabasePasswordService $service, Database $database, $set, $get) => $this->rotatePassword($service, $database, $set, $get))
                            ->authorize(fn (Database $database) => auth()->user()->can('update database', $database))
                    )
                    ->formatStateUsing(fn (Database $database) => $database->password),
                TextInput::make('remote')
                    ->label('Connections From')
                    ->formatStateUsing(fn ($record) => $record->remote === '%' ? 'Anywhere ( % )' : $record->remote),
                TextInput::make('max_connections')
                    ->formatStateUsing(fn ($record) => $record->max_connections === 0 ? 'Unlimited' : $record->max_connections),
                TextInput::make('JDBC')
                    ->label('JDBC Connection String')
                    ->columnSpanFull()
                    ->password()
                    ->revealable()
                    ->formatStateUsing(fn (Get $get, Database $database) => 'jdbc:mysql://' . $get('username') . ':' . urlencode($database->password) . '@' . $database->host->host . ':' . $database->host->port . '/' . $get('database')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('servers')
            ->columns([
                TextColumn::make('database')
                    ->icon('tabler-database'),
                TextColumn::make('username')
                    ->icon('tabler-user'),
                TextColumn::make('remote')
                    ->formatStateUsing(fn ($record) => $record->remote === '%' ? 'Anywhere ( % )' : $record->remote),
                TextColumn::make('server.name')
                    ->icon('tabler-brand-docker')
                    ->url(fn (Database $database) => route('filament.admin.resources.servers.edit', ['record' => $database->server_id])),
                TextColumn::make('max_connections')
                    ->formatStateUsing(fn ($record) => $record->max_connections === 0 ? 'Unlimited' : $record->max_connections),
                DateTimeColumn::make('created_at'),
            ])
            ->actions([
                DeleteAction::make()
                    ->authorize(fn (Database $database) => auth()->user()->can('delete database', $database)),
                ViewAction::make()
                    ->color('primary')
                    ->hidden(fn () => !auth()->user()->can('viewList database')),
            ]);
    }

    protected function rotatePassword(DatabasePasswordService $service, Database $database, Set $set, Get $get): void
    {
        $newPassword = $service->handle($database);
        $jdbcString = 'jdbc:mysql://' . $get('username') . ':' . urlencode($newPassword) . '@' . $database->host->host . ':' . $database->host->port . '/' . $get('database');

        $set('password', $newPassword);
        $set('JDBC', $jdbcString);
    }
}
