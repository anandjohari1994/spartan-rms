<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('qualification')->nullable()->change();
            $table->unsignedSmallInteger('passout_year')->nullable()->change();
            $table->string('location', 120)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Do not invent profile data or discard incomplete candidates during rollback.
        if (DB::table('candidates')->whereNull('qualification')->orWhereNull('passout_year')->orWhereNull('location')->exists()) {
            throw new RuntimeException('Complete all candidate profiles before rolling back staged profile support.');
        }
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('qualification')->nullable(false)->change();
            $table->unsignedSmallInteger('passout_year')->nullable(false)->change();
            $table->string('location', 120)->nullable(false)->change();
        });
    }
};
