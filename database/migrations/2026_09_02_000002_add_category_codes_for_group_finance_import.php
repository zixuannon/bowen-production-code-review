<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::connection('mysql')->table('central_finance_categories', function (Blueprint $table): void { $table->string('category_code', 80)->nullable()->after('type'); });
        DB::connection('mysql')->table('central_finance_categories')->orderBy('id')->get()->each(function ($row): void {
            $base = strtoupper(preg_replace('/[^A-Z0-9]+/', '-', strtoupper((string) $row->name)) ?: 'CATEGORY');
            DB::connection('mysql')->table('central_finance_categories')->where('id', $row->id)->update(['category_code' => substr($base, 0, 64).'-'.$row->id]);
        });
        if (DB::connection('mysql')->table('central_finance_categories')->whereNull('category_code')->orWhere('category_code', '')->exists()) throw new RuntimeException('Category code backfill failed.');
        if (DB::connection('mysql')->table('central_finance_categories')->select('school_id', 'type', 'category_code')->groupBy('school_id', 'type', 'category_code')->havingRaw('COUNT(*) > 1')->exists()) throw new RuntimeException('Category code backfill produced duplicates.');
        Schema::connection('mysql')->table('central_finance_categories', function (Blueprint $table): void { $table->unique(['school_id','type','category_code'], 'cfc_school_type_code_unique'); });
    }
    public function down(): void { Schema::connection('mysql')->table('central_finance_categories', function (Blueprint $table): void { $table->dropUnique('cfc_school_type_code_unique'); $table->dropColumn('category_code'); }); }
};
