<?php

namespace Tests\Feature;

use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceBusinessContentTranslationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CentralFinanceBusinessContentTranslationTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'cf_translation_');
        Config::set('database.connections.mysql', ['driver'=>'sqlite', 'database'=>$this->database, 'prefix'=>'', 'foreign_key_constraints'=>true]);
        DB::purge('mysql');
        Schema::connection('mysql')->create('central_finance_content_translations', function (Blueprint $table): void {
            $table->id(); $table->string('subject_type'); $table->unsignedBigInteger('subject_id'); $table->unsignedBigInteger('school_id')->default(0);
            $table->string('locale'); $table->text('translated_value'); $table->string('source_hash'); $table->string('status'); $table->unsignedBigInteger('updated_by'); $table->string('reason'); $table->timestamps();
            $table->unique(['subject_type','subject_id','school_id','locale']);
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->database);
        parent::tearDown();
    }

    public function test_english_is_separate_from_canonical_text_and_becomes_stale_after_original_change(): void
    {
        $actor = new CentralFinanceUser; $actor->id = 99;
        $translations = app(CentralFinanceBusinessContentTranslationService::class);
        $translations->saveEnglish($actor, 'chart_account', 7, null, '学费', 'Tuition', 'Reviewed English label');

        $this->assertSame('Tuition', $translations->display('chart_account', 7, null, '学费', 'en'));
        $this->assertSame('学费', $translations->display('chart_account', 7, null, '学费', 'zh'));

        $translations->markEnglishStale('chart_account', 7, null, '新学费');
        $this->assertSame('新学费', $translations->display('chart_account', 7, null, '新学费', 'en'));
        $this->assertSame('stale', DB::connection('mysql')->table('central_finance_content_translations')->value('status'));
    }
}
