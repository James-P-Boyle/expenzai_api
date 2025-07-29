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
            ->query(function () {
                $query = User::query();
                \Illuminate\Support\Facades\Log::info('🔍 UserResource Table Debug', [
                    'total_users' => User::count(),
                    'query_count' => $query->count(),
                    'users_sample' => User::select('id', 'name', 'email', 'user_tier')->get()->toArray(),
                ]);
                return $query;
            })
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
                Tables\Columns\IconColumn::make('email_receipts_enabled')
                    ->boolean()
                    ->label('Email Receipts'),
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
                    ->visible(fn ($record) => !$record->email_receipts_enabled)
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
                Action::make('verifyEmail')
                    ->label('Verify Email')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn ($record) => is_null($record->email_verified_at))
                    ->action(function ($record) {
                        $record->update(['email_verified_at' => now()]);
                        \Filament\Notifications\Notification::make()
                            ->title('Email verified')
                            ->body("User {$record->email} has been verified")
                            ->success()
                            ->send();
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
