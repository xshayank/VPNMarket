<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InboundResource\Pages;
use App\Models\Inbound;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InboundResource extends Resource
{
    protected static ?string $model = Inbound::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationLabel = 'اینباندها (سنایی/X-UI)';
    protected static ?string $modelLabel = 'اینباند';
    protected static ?string $pluralModelLabel = 'اینباندها';
    protected static ?string $navigationGroup = 'مدیریت پنل';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->label('عنوان دلخواه برای اینباند')
                    ->required()
                    ->helperText('یک نام مشخص برای این اینباند انتخاب کنید (مثلاً: VLESS WS آلمان).'),

                Forms\Components\Textarea::make('inbound_data')
                    ->label('اطلاعات JSON اینباند')
                    ->required()
                    ->json() // اعتبارسنجی می‌کند که ورودی حتماً JSON باشد
                    ->rows(15)
                    ->helperText('اطلاعات کامل اینباند را از پنل سنایی (که از API دریافت می‌شود) کپی و در اینجا پیست کنید.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('عنوان')->searchable(),
                // نمایش ID اینباند از داخل JSON
                Tables\Columns\TextColumn::make('inbound_data.id')->label('ID در پنل'),
                Tables\Columns\TextColumn::make('inbound_data.remark')->label('Remark در پنل'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array { return [
        'index' => Pages\ListInbounds::route('/'),
        'create' => Pages\CreateInbound::route('/create'),
        'edit' => Pages\EditInbound::route('/{record}/edit'),
    ]; }
}
