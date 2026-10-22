<?php
namespace App\Filament\Support;
use Closure;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
class ActionFeedback
{
    public static function run(?Schema $schema, Closure $callback): mixed
    {
        try { return $callback(); }
        catch (ValidationException $e) {
            Notification::make()->title('Please check the submitted information')->body(implode(' ', $e->validator->errors()->all()))->danger()->send();
            $prefix = $schema?->getStatePath();
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => [($prefix ? $prefix.'.' : '').$key => $messages])->all());
        }
    }
}
