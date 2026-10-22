<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateRmsAdmin extends Command
{
    protected $signature = 'rms:create-admin';

    protected $description = 'Create an active RMS administrator interactively without default credentials';

    public function handle(): int
    {
        $data = ['name' => $this->ask('Name'), 'email' => $this->ask('Email'), 'password' => $this->secret('Password (12+ characters, mixed case and a number)'), 'password_confirmation' => $this->secret('Confirm password')];
        $validator = Validator::make($data, ['name' => 'required|string|max:255', 'email' => 'required|email|unique:users,email', 'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

return self::FAILURE;
        }
        DB::transaction(function () use ($data) {
            $user = User::create([...Arr::except($data, ['password_confirmation']), 'is_active' => true]);
            $user->assignRole('Super Admin');
            app(AuditService::class)->record($user, $user, 'user.admin_bootstrapped');
        });
        $this->info('Administrator created. Sign in at /admin.');

        return self::SUCCESS;
    }
}
