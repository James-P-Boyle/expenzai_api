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
                    ->helperText('Pro users can receive receipt emails')
                    ->default(false),
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
                    ->sortable(),
            ])
            ->filters([
            ])
            ->actions([
            ])
            ->bulkActions([
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