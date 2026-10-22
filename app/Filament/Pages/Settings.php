<?php

namespace App\Filament\Pages;

use App\Models\ApplicationSetting;
use App\Services\AuditService;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class Settings extends Page
{
    protected string $view = 'filament.pages.settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() && auth()->user()->can('settings.manage');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(['company_name' => ApplicationSetting::where('key', 'company_name')->value('value') ?: 'Spartan Infosoft Pvt. Ltd.', 'support_email' => ApplicationSetting::where('key', 'support_email')->value('value')]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([TextInput::make('company_name')->required()->maxLength(255), TextInput::make('support_email')->email()->maxLength(255)]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        DB::transaction(function () use ($data) {
            $old = ApplicationSetting::pluck('value', 'key')->all();
            foreach (['company_name', 'support_email'] as $key) {
                ApplicationSetting::updateOrCreate(['key' => $key], ['value' => $data[$key] ?? null]);
            }
            app(AuditService::class)->record(auth()->user(), auth()->user(), 'settings.updated', $old, $data);
        });
        Notification::make()->title('Settings saved')->success()->send();
    }
}
