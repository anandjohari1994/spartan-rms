<?php

namespace App\Filament\Support;

use App\Models\Candidate;
use App\Models\User;
use App\Support\Recruitment;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

class Fields
{
    public static function select(string $name, array $options): Select
    {
        return Select::make($name)->options(Recruitment::options($options))->required()->native(false);
    }

    public static function candidate(): Select
    {
        return Select::make('candidate_id')->label('Candidate')->searchable()->required()
            ->getSearchResultsUsing(fn (string $search) => Candidate::visibleTo(auth()->user())->where(fn ($q) => $q->where('full_name', 'like', '%'.$search.'%')->orWhere('candidate_code', 'like', '%'.$search.'%'))->limit(30)->get()->mapWithKeys(fn ($c) => [$c->id => $c->candidate_code.' · '.$c->full_name])->all())
            ->getOptionLabelUsing(fn ($value) => Candidate::visibleTo(auth()->user())->find($value)?->full_name);
    }

    public static function hr(string $name = 'new_hr_id'): Select
    {
        return Select::make($name)->label('HR Executive')->options(fn () => User::role('HR Executive')->where('is_active', true)->pluck('name', 'id'))->searchable()->required();
    }

    public static function resume(): FileUpload
    {
        return FileUpload::make('resume')->acceptedFileTypes(['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])->maxSize(5120)->storeFiles(false)->previewable(false)->helperText('Private PDF or DOCX, up to 5 MB.');
    }

    public static function interview(): array
    {
        return [self::select('status', Recruitment::INTERVIEWS), DateTimePicker::make('scheduled_at')->seconds(false), DateTimePicker::make('completed_at')->seconds(false), TextInput::make('score')->numeric()->minValue(0)->maxValue(100), Textarea::make('remarks')->maxLength(10000), Textarea::make('override_reason')->maxLength(2000)->visible(fn () => auth()->user()->isAdmin())->helperText('Mandatory for an administrator to override progression or reopen a finished round.')];
    }
}
