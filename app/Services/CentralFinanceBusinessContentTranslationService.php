<?php

namespace App\Services;

use App\Models\CentralFinanceContentTranslation;
use App\Models\CentralFinanceUser;
use Illuminate\Support\Facades\Schema;

/** zh/original remains canonical. en is an independently audited display layer. */
final class CentralFinanceBusinessContentTranslationService
{
    public const FUND_ACCOUNT = 'fund_account';
    public const CHART_ACCOUNT = 'chart_account';
    public const TENANT_FEE = 'tenant_fee';

    public function display(string $type, int $id, ?int $schoolId, string $original, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        if (!$this->usesEnglish($locale) || !Schema::connection('mysql')->hasTable('central_finance_content_translations')) return $original;
        $scopeSchoolId = $schoolId ?? 0;
        $translation = CentralFinanceContentTranslation::on('mysql')->where([
            'subject_type' => $type, 'subject_id' => $id, 'locale' => 'en', 'status' => 'current',
            'school_id' => $scopeSchoolId,
        ])->value('translated_value');
        return is_string($translation) && trim($translation) !== '' ? $translation : $original;
    }

    public function english(string $type, int $id, ?int $schoolId): string
    {
        if (!Schema::connection('mysql')->hasTable('central_finance_content_translations')) return '';
        return (string) CentralFinanceContentTranslation::on('mysql')->where([
            'subject_type'=>$type, 'subject_id'=>$id, 'school_id'=>$schoolId ?? 0, 'locale'=>'en',
        ])->value('translated_value');
    }

    public function saveEnglish(CentralFinanceUser $actor, string $type, int $id, ?int $schoolId, string $original, string $english, string $reason): void
    {
        $english = trim($english); $reason = trim($reason);
        if ($english === '' || $reason === '' || !Schema::connection('mysql')->hasTable('central_finance_content_translations')) return;
        CentralFinanceContentTranslation::on('mysql')->updateOrCreate(
            ['subject_type'=>$type, 'subject_id'=>$id, 'school_id'=>$schoolId ?? 0, 'locale'=>'en'],
            ['translated_value'=>$english, 'source_hash'=>$this->sourceHash($original), 'status'=>'current', 'updated_by'=>$actor->id, 'reason'=>$reason]
        );
    }

    public function markEnglishStale(string $type, int $id, ?int $schoolId, string $newOriginal): void
    {
        if (!Schema::connection('mysql')->hasTable('central_finance_content_translations')) return;
        CentralFinanceContentTranslation::on('mysql')->where([
            'subject_type'=>$type, 'subject_id'=>$id, 'school_id'=>$schoolId ?? 0, 'locale'=>'en',
        ])->where('source_hash', '!=', $this->sourceHash($newOriginal))->update(['status'=>'stale', 'updated_at'=>now()]);
    }

    private function usesEnglish(string $locale): bool { return strtolower(str_replace('_', '-', $locale)) === 'en'; }
    private function sourceHash(string $source): string { return hash('sha256', trim($source)); }
}
