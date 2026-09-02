<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        $invalid = DB::connection('mysql')->table('schools')->whereNull('code')->orWhere('code', '')->exists();
        $duplicate = DB::connection('mysql')->table('schools')->select('code')->groupBy('code')->havingRaw('COUNT(*) > 1')->exists();
        if ($invalid || $duplicate) throw new RuntimeException('Cannot enforce permanent School Code uniqueness: blank or duplicate codes exist.');
        Schema::connection('mysql')->table('schools', function (Blueprint $table): void { $table->unique('code', 'schools_code_unique'); });
    }
    public function down(): void { Schema::connection('mysql')->table('schools', function (Blueprint $table): void { $table->dropUnique('schools_code_unique'); }); }
};
