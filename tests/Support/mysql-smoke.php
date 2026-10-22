<?php

use App\Models\Candidate;
use App\Models\InterviewRound;
use App\Models\User;
use App\Services\CandidateService;
use App\Services\DashboardService;
use App\Services\ReportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Isolated MySQL/MariaDB migration and concurrent sequence test. Never touches the configured database. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = config('database.connections.mysql');
if (! in_array($connection['host'], ['127.0.0.1', 'localhost'], true) || app()->environment('production')) {
    throw new RuntimeException('This smoke test only permits a local non-production server.');
}
$worker = $argv[1] ?? null;
$db = $worker ? getenv('RMS_SMOKE_DATABASE') : 'rms_smoke_'.bin2hex(random_bytes(6));
if (! preg_match('/^rms_smoke_[a-f0-9]{12}$/D', $db)) {
    throw new RuntimeException('Invalid isolated schema.');
}
config(['database.default' => 'mysql', 'database.connections.mysql.database' => $db, 'database.connections.mysql.url' => null, 'cache.default' => 'array']);
DB::purge('mysql');
if ($worker) {
    if (! in_array($worker, ['1', '2', '3', '4'], true)) {
        throw new RuntimeException('Invalid worker.');
    }
    $actor = User::firstOrFail();
    $candidate = app(CandidateService::class)->create($actor, ['entry_date' => today()->toDateString(), 'full_name' => 'Concurrent test '.$worker, 'mobile' => '900000000'.$worker, 'qualification' => 'Test', 'passout_year' => 2025, 'location' => 'Test', 'job_position_id' => 1, 'experience_type' => 'Fresher', 'calling_status' => 'Not Called', 'interest_status' => 'Unknown']);
    echo $candidate->candidate_code.PHP_EOL;
    exit(0);
}
$pdo = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4', $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$children = [];
try {
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('db:seed', ['--force' => true]);
    $hr = User::create(['name' => 'Concurrency Test', 'email' => 'concurrency@example.test', 'password' => bin2hex(random_bytes(24)), 'is_active' => true]);
    $hr->assignRole('HR Executive');
    putenv('RMS_SMOKE_DATABASE='.$db);
    for ($i = 1; $i <= 4; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, (string) $i], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start concurrent worker.');
        }
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    foreach ($children as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Worker failed: '.$error.$output);
        }
    }
    if (Candidate::count() !== 4 || Candidate::distinct()->count('candidate_code') !== 4 || InterviewRound::count() !== 12) {
        throw new RuntimeException('Concurrent registration assertions failed.');
    }
    $hr->assignRole('Super Admin');
    foreach (array_keys(ReportService::TYPES) as $type) {
        iterator_to_array(app(ReportService::class)->rows($hr, ['type' => $type]));
    }
    app(DashboardService::class)->stats($hr);
    foreach (['growth', 'hr', 'position', 'outcomes', 'funnel'] as $kind) {
        app(DashboardService::class)->chart($hr, $kind);
    }
    echo 'PASS: MySQL-compatible migrations, four concurrent registrations, unique codes, twelve rounds, all report queries and dashboard aggregations.'.PHP_EOL;
} finally {
    // Only the random database created by this process may be removed.
    DB::disconnect('mysql');
    $pdo->exec('DROP DATABASE `'.$db.'`');
    putenv('RMS_SMOKE_DATABASE');
}
