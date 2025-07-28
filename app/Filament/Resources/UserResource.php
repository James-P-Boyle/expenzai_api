<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Actions\Action;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'User Management';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('user_tier')
                    ->options([
                        'free' => 'Free',
                        'pro' => 'Pro',
                    ])
                    ->required()
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        // Auto-enable email receipts for Pro users
                        if ($state === 'pro') {
                            $set('email_receipts_enabled', true);
                        } else {
                            $set('email_receipts_enabled', false);
                        }
                    }),
                Toggle::make('email_receipts_enabled')
                    ->label('Email Receipts Enabled')
                    ->helperText('Pro users can receive receipt emails'),
                Forms\Components\TextInput::make('receipt_email_address')
                    ->label('Receipt Email Address')
                    ->disabled()
                    ->helperText('Auto-generated for Pro users'),
                Forms\Components\DateTimePicker::make('email_verified_at')
                    ->label('Email Verified At'),
                Toggle::make('is_admin')
                    ->label('Admin Access'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('user_tier')
                    ->colors([
                        'secondary' => 'free',
                        'success' => 'pro',
                    ])
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('effective_tier')
                    ->label('Effective Tier')
                    ->getStateUsing(fn ($record) => $record->getEffectiveTier())
                    ->colors([
                        'secondary' => 'free',
                        'success' => 'pro',
                    ]),
                IconColumn::make('email_receipts_enabled')
                    ->boolean()
                    ->label('Email Receipts'),
                IconColumn::make('email_verified_at')
                    ->boolean()
                    ->label('Verified')
                    ->getStateUsing(fn ($record) => !is_null($record->email_verified_at)),
                Tables\Columns\TextColumn::make('receipts_count')
                    ->label('Receipts')
                    ->counts('receipts')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_tier')
                    ->options([
                        'free' => 'Free',
                        'pro' => 'Pro',
                    ]),
                SelectFilter::make('email_verified')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('email_verified_at'))
                    ->label('Email Verified'),
                SelectFilter::make('email_receipts_enabled')
                    ->query(fn (Builder $query): Builder => $query->where('email_receipts_enabled', true))
                    ->label('Email Receipts Enabled'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('enableEmailReceipts')
                    ->label('Enable Email Receipts')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn ($record) => !$record->email_receipts_enabled && $record->getEffectiveTier() === 'pro')
                    ->action(function ($record) {
                        $emailAddress = $record->enableEmailReceipts();
                        \Filament\Notifications\Notification::make()
                            ->title('Email receipts enabled')
                            ->body("Receipt email: {$emailAddress}")
                            ->success()
                            ->send();
                    }),
                Action::make('viewReceipts')
                    ->label('View Receipts')
                    ->icon('heroicon-o-document-text')
                    ->url(fn ($record) => '/admin/receipts?tableFilters[user_id][value]=' . $record->id)
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }
}