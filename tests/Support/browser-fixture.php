<?php

use App\Models\User;
use App\Services\CallService;
use App\Services\CandidateService;
use App\Services\FollowUpService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// Synthetic UI data in a dedicated, disposable SQLite database only.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$path = storage_path('rms-browser.sqlite');
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $path || file_exists($path)) {
    throw new RuntimeException('Use testing with the new storage/rms-browser.sqlite database. Existing files are never reset.');
}
touch($path);
Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--force' => true]);
$password = bin2hex(random_bytes(12));
$admin = User::create(['name' => 'Demo Administrator', 'email' => 'admin@example.test', 'password' => $password, 'is_active' => true]);
$admin->assignRole('Super Admin');
$hr = User::create(['name' => 'Demo Recruiter', 'email' => 'hr@example.test', 'password' => $password, 'is_active' => true]);
$hr->assignRole('HR Executive');
foreach (['Aarav Sharma', 'Meera Patel', 'Rohan Desai', 'Ananya Rao', 'Ishaan Shah', 'Kavya Singh'] as $i => $name) {
    $c = app(CandidateService::class)->create($hr, ['entry_date' => today()->subDays($i)->toDateString(), 'full_name' => $name, 'mobile' => '900000000'.$i, 'qualification' => 'B.Tech', 'passout_year' => 2025, 'location' => 'Pune', 'job_position_id' => $i + 1, 'experience_type' => 'Fresher', 'calling_status' => 'Not Called', 'interest_status' => 'Interested']);
    if ($i % 2 === 0) {
        app(CallService::class)->create($hr, ['candidate_id' => $c->id, 'called_at' => now()->subMinutes($i), 'call_status' => 'Connected']);
    }
    app(FollowUpService::class)->save($hr, ['candidate_id' => $c->id, 'scheduled_at' => now()->addHours($i - 2), 'status' => 'Pending', 'priority' => 'High']);
}
echo 'Synthetic browser fixture ready. Login: admin@example.test or hr@example.test; temporary password: '.$password.PHP_EOL;
