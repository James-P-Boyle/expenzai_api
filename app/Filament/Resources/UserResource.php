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
use Illuminate\Support\Facades\Hash;

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
                    ->maxLength(255)
                    ->unique(User::class, 'email', ignoreRecord: true),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->required(fn (string $context): bool => $context === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->helperText('Leave blank to keep current password when editing'),
                Forms\Components\Select::make('user_tier')
                    ->options([
                        'free' => 'Free',
                        'pro' => 'Pro',
                    ])
                    ->required()
                    ->default('free')
                    ->helperText('Pro users automatically get email receipt processing'),
                Forms\Components\TextInput::make('receipt_email_address')
                    ->label('Receipt Email Address')
                    ->disabled()
                    ->helperText('Auto-generated for Pro users'),
                Forms\Components\DateTimePicker::make('email_verified_at')
                    ->label('Email Verified At')
                    ->helperText('Leave blank for unverified users'),
                Toggle::make('is_admin')
                    ->label('Admin Access')
                    ->default(false),
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
                Tables\Columns\TextColumn::make('user_tier')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'free' => 'gray',
                        'pro' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\IconColumn::make('email_verified_at')
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
                Tables\Filters\TernaryFilter::make('email_verified_at')
                    ->label('Email Verified')
                    ->nullable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('viewReceipts')
                    ->label('View Receipts')
                    ->icon('heroicon-o-document-text')
                    ->url(fn ($record) => '/admin/receipts?tableFilters[user_id][value]=' . $record->id)
                    ->openUrlInNewTab(),
                Action::make('verifyEmail')
                    ->label('Verify Email')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn ($record) => is_null($record->email_verified_at))
                    ->action(function ($record, $livewire) {
                        try {
                            $record->update(['email_verified_at' => now()]);
                            
                            \Filament\Notifications\Notification::make()
                                ->title('Email verified')
                                ->body("User {$record->email} has been verified")
                                ->success()
                                ->send();
                                
                            $livewire->dispatch('$refresh');
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Error verifying email')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
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