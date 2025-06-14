<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionResource\Pages;
use App\Filament\Resources\TransactionResource\RelationManagers;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Repositories\CurrencyRepository;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-up-down';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('type')
                    ->label('Tipe')
                    ->default(fn(Transaction $record) => $record ? ($record->reference_id ? 'Transfer' : ucfirst($record->type)) : null)
                    ->options([
                        'expense' => 'Expense',
                        'income' => 'Income',
                    ])
                    ->hidden(fn(Get $get) => $get('reference_id'))
                    ->native(false)
                    ->searchable()
                    ->live()
                    ->required(),
                Forms\Components\Hidden::make('reference_id'),
                Forms\Components\Select::make("destionation_id")
                    ->label("Destination Wallet")
                    ->options(Wallet::all()->pluck("name", "id")->toArray())
                    ->native(false)
                    ->searchable()
                    ->live()
                    ->hidden(fn(Get $get) => $get('type') !== 'transfer')
                    ->required(),
                Forms\Components\TextInput::make('wallet_id')
                    ->label('Dompet')
                    ->formatStateUsing(fn(Transaction $record) => $record->wallet->name)
                    ->disabled()
                    ->required(),
                Forms\Components\TextInput::make('amount')
                    ->label('Jumlah')
                    ->prefix(fn(Transaction $record) => $record->wallet->currency)
                    ->default(fn(Transaction $record) => $record->amount)
                    ->required(),
                Forms\Components\Textarea::make('description')
                    ->label('Deskripsi')
                    ->default(fn(Transaction $record) => $record->description ?? '-'),
                Forms\Components\DateTimePicker::make('created_at')
                    ->label('Dibuat Pada')
                    ->native(false)
                    ->default(fn(Transaction $record) => $record->created_at->format('d-m-Y H:i'))
                    ->required(),

            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        /* @var \App\Models\User $user */
        $user = Auth::user();
        return !$record->reference_id && !$user->transactions->firstWhere('reference_id', $record->id);
    }

    public static function query(): Builder
    {
        return Transaction::query()->with("wallet")->orderBy("created_at", "desc");
    }

    public static function table(Table $table): Table
    {
        $currencyRepository = app(CurrencyRepository::class);
        $detailCurrencies = $currencyRepository->getDetails();

        return $table
            ->query(self::query())
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->formatStateUsing(fn(Transaction $record) => $record->reference_id ? 'transfer' : $record->type)
                    ->badge()
                    ->color(fn(Transaction $record) => match (true) {
                        $record->reference_id &&  $record->type === 'income' => 'info', // Transfer
                        $record->type === 'expense' => 'danger',
                        !$record->reference_id && $record->type === 'income' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('wallet.name'),
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(fn($state, Transaction $record) => Number::currency(floatval($state), $record->wallet->currency)),
                Tables\Columns\TextColumn::make('description')
                    ->default("-"),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime(),
            ])
            ->searchable()
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'expense' => 'Expense',
                        'income' => 'Income',
                        'transfer' => 'Transfer',
                    ])
                    ->native(false)
                    ->query(function (Builder $query, array $data) {
                        if ($data['value'] === 'transfer') {
                            return $query->whereNotNull('reference_id');
                        }

                        if ($data['value']) {
                            return $query->where('type', $data['value']);
                        }

                        return $query;
                    }),

                Tables\Filters\SelectFilter::make('wallet')
                    ->relationship('wallet', 'name')
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\Action::make('show')
                    ->label('Detail')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Detail Transaksi')
                    ->form([
                        Forms\Components\TextInput::make('type')
                            ->label('Tipe')
                            ->default(fn(Transaction $record) => $record->reference_id ? 'Transfer' : ucfirst($record->type))
                            ->disabled(),

                        Forms\Components\TextInput::make('wallet.name')
                            ->label('Dompet')
                            ->default(fn(Transaction $record) => $record->wallet->name)
                            ->disabled(),

                        Forms\Components\TextInput::make('amount')
                            ->label('Jumlah')
                            ->default(fn(Transaction $record) => Number::currency(floatval($record->amount), $record->wallet->currency))
                            ->disabled(),

                        Forms\Components\Textarea::make('description')
                            ->label('Deskripsi')
                            ->default(fn(Transaction $record) => $record->description ?? '-')
                            ->disabled(),

                        Forms\Components\TextInput::make('created_at')
                            ->label('Dibuat Pada')
                            ->default(fn(Transaction $record) => $record->created_at->format('d-m-Y H:i'))
                            ->disabled(),
                    ])
                    ->modalWidth('md'),
                Tables\Actions\EditAction::make(),
            ])
            ->headerActions([
                Tables\Actions\Action::make("incomes")
                    ->form([
                        Forms\Components\TextInput::make("amount")
                            ->required(),
                        Forms\Components\Select::make("wallet_id")
                            ->label("Wallet")
                            ->options(Wallet::all()->pluck("name", "id")->toArray())
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn($state, callable $set) => $set("currency", Wallet::find($state)->currency))
                            ->native(false)
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make("currency")
                            ->options(
                                collect($detailCurrencies)->mapWithKeys(fn($data, $code) => [$code => sprintf("%s (%s)", $data["name"], $code)])->toArray()
                            )
                            ->searchable()
                            ->native(false)
                            ->required(),
                        Forms\Components\TextInput::make("descrption")
                            ->datalist(fn() => Auth::user()->transactions->pluck("description")->toArray())

                    ])
                    ->requiresConfirmation()
                    ->color("success")
                    ->icon("heroicon-o-banknotes")
                    ->action(function (array $data) use ($currencyRepository) {
                        $wallet = Wallet::find($data["wallet_id"]);

                        if ($data["currency"] != $wallet->currency) {
                            $data["amount"] = $currencyRepository->convert($data["amount"], $data["currency"], $wallet->currency);
                        }

                        $wallet->income($data["amount"], $data["descrption"]);
                    }),
                Tables\Actions\Action::make("expenses")
                    ->form([
                        Forms\Components\TextInput::make("amount")
                            ->required(),
                        Forms\Components\Select::make("wallet_id")
                            ->label("Wallet")
                            ->options(Wallet::all()->pluck("name", "id")->toArray())
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn($state, callable $set) => $set("currency", Wallet::find($state)->currency))
                            ->native(false)
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make("currency")
                            ->options(
                                collect($detailCurrencies)->mapWithKeys(fn($data, $code) => [$code => sprintf("%s (%s)", $data["name"], $code)])->toArray()
                            )
                            ->searchable()
                            ->native(false)
                            ->required(),
                        Forms\Components\TextInput::make("descrption")
                            ->datalist(fn() => Auth::user()->transactions->pluck("description")->toArray())

                    ])
                    ->requiresConfirmation()
                    ->color("danger")
                    ->icon("heroicon-o-minus-circle")
                    ->action(function (array $data) use ($currencyRepository) {
                        $wallet = Wallet::find($data["wallet_id"]);

                        if ($data["currency"] != $wallet->currency) {
                            $data["amount"] = $currencyRepository->convert($data["amount"], $data["currency"], $wallet->currency);
                        }

                        $wallet->expense($data["amount"], $data["descrption"]);
                    }),
                Tables\Actions\Action::make("transfer")
                    ->form([
                        Forms\Components\TextInput::make("amount")
                            ->required(),
                        Forms\Components\Select::make("origin_id")
                            ->label("Origin Wallet")
                            ->options(Wallet::all()->pluck("name", "id")->toArray())
                            ->native(false)
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make("destionation_id")
                            ->label("Destination Wallet")
                            ->options(Wallet::all()->pluck("name", "id")->toArray())
                            ->native(false)
                            ->searchable()
                            ->required(),
                        Forms\Components\TextInput::make("descrption")
                            ->datalist(fn() => Auth::user()->transactions->pluck("description")->toArray())
                    ])
                    ->requiresConfirmation()
                    ->color("info")
                    ->icon("heroicon-o-arrows-up-down")
                    ->action(function (array $data) use ($currencyRepository) {
                        $from = Wallet::find($data["origin_id"]);
                        $to = Wallet::find($data["destionation_id"]);

                        $originalAmount = $data["amount"];

                        if ($from->currency != $to->currency) {
                            $data["amount"] = $currencyRepository->convert($data["amount"], $from->currency, $to->currency);
                        }

                        $from->transfer($to, $originalAmount, $data["amount"], $data["descrption"]);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
            'create' => Pages\CreateTransaction::route('/create'),
            'edit' => Pages\EditTransaction::route('/{record}/edit'),
        ];
    }
}
