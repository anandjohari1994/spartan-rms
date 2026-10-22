<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\ApplicationSetting;
use App\Models\CallLog;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\CandidateDocument;
use App\Models\FollowUp;
use App\Models\InterviewRound;
use App\Models\JobPosition;
use App\Models\User;
use App\Policies\ActivityLogPolicy;
use App\Policies\AdminPolicy;
use App\Policies\CandidatePolicy;
use App\Policies\CandidateRecordPolicy;
use Filament\Notifications\Notification;
use Filament\Pages\BasePage;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($publicPath = config('app.public_path')) {
            $this->app->usePublicPath($publicPath);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        BasePage::$reportValidationErrorUsing = function (ValidationException $exception): void {
            Notification::make()->title('Please check the submitted information')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();
        };
        Gate::policy(Candidate::class, CandidatePolicy::class);
        foreach ([InterviewRound::class, CallLog::class, FollowUp::class, CandidateDocument::class, CandidateAssignment::class] as $model) {
            Gate::policy($model, CandidateRecordPolicy::class);
        }
        foreach ([User::class, JobPosition::class, ApplicationSetting::class] as $model) {
            Gate::policy($model, AdminPolicy::class);
        }
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);
        Event::listen(Login::class, function ($event) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        });
    }
}
