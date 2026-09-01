<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CentralFinanceLocalizationContractTest extends TestCase
{
    public function test_every_ascii_central_finance_translation_source_has_a_zh_cn_value(): void
    {
        $keys = $this->translationSources();
        $zh = json_decode((string) file_get_contents($this->basePath('resources/lang/zh-cn.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach ($keys as $key) {
            if (! preg_match('/[A-Za-z]/', $key) || preg_match('/[一-龥]/u', $key)) {
                continue;
            }

            self::assertArrayHasKey($key, $zh, "Missing zh-cn Central Finance translation for [{$key}].");
            self::assertNotSame($key, $zh[$key], "Central Finance zh-cn value falls back to English for [{$key}].");
        }
    }

    public function test_chinese_source_labels_have_english_mode_equivalents(): void
    {
        $en = json_decode((string) file_get_contents($this->basePath('resources/lang/en.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach ($this->translationSources() as $key) {
            if (! preg_match('/[一-龥]/u', $key)) {
                continue;
            }

            self::assertArrayHasKey($key, $en, "Missing English Central Finance translation for Chinese source [{$key}].");
            self::assertMatchesRegularExpression('/[A-Za-z]/', $en[$key]);
        }
    }

    public function test_installed_cn_school_locale_uses_the_audited_zh_cn_catalog_for_central_finance(): void
    {
        $middleware = (string) file_get_contents($this->basePath('app/Http/Middleware/LanguageManager.php'));

        self::assertStringContainsString("routeIs('central-finance.*')", $middleware);
        self::assertStringContainsString("strtolower(\$locale) === 'cn'", $middleware);
        self::assertStringContainsString("? 'zh-cn'", $middleware);
    }

    public function test_runtime_status_and_audit_values_use_translation_entries_instead_of_english_formatters(): void
    {
        $workspace = (string) file_get_contents($this->basePath('resources/views/central-finance/workspace.blade.php'));
        $views = $workspace."\n".(string) file_get_contents($this->basePath('resources/views/central-finance/reimbursement-detail.blade.php'));
        $views .= "\n".(string) file_get_contents($this->basePath('resources/views/central-finance/ledger-source.blade.php'));

        self::assertStringNotContainsString('ucfirst($status)', $views);
        self::assertStringNotContainsString('strtoupper($document->status)', $views);
        self::assertStringContainsString('{{ __($cutoverStatus) }}', $workspace);
        self::assertStringContainsString("__(\$check['reason']", $workspace);
        self::assertStringContainsString("\$check['reason_params'] ?? []", $workspace);
        self::assertStringContainsString('{{ __($r->status) }}', $workspace);
        self::assertStringContainsString('{{ __($document->status) }}', $workspace);
        self::assertStringContainsString("{{ __('HQ') }}", $workspace);

        $zh = json_decode((string) file_get_contents($this->basePath('resources/lang/zh-cn.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach (['central', 'legacy', 'ready', 'open', 'partial', 'paid', 'waived', 'cancelled', 'active', 'inactive', 'archived', 'confirmed', 'validation_failed'] as $status) {
            self::assertArrayHasKey($status, $zh, "Missing zh-cn runtime status label for [{$status}].");
            self::assertMatchesRegularExpression('/[一-龥]/u', $zh[$status]);
        }
    }

    public function test_audit_action_and_document_type_values_have_readable_locale_entries(): void
    {
        $presentation = (string) file_get_contents($this->basePath('app/Services/CentralFinanceLedgerPresentationService.php'));
        $zh = json_decode((string) file_get_contents($this->basePath('resources/lang/zh-cn.json')), true, 512, JSON_THROW_ON_ERROR);
        $en = json_decode((string) file_get_contents($this->basePath('resources/lang/en.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach (['requested', 'confirmed', 'cancelled', 'rejected', 'submitted', 'approved', 'withdrawn', 'created', 'updated', 'deleted', 'refund', 'adjustment', 'discount', 'waiver', 'void', 'collected', 'lifecycle_active', 'lifecycle_inactive', 'lifecycle_archived'] as $action) {
            self::assertArrayHasKey($action, $zh);
            self::assertNotSame($action, $zh[$action]);
            self::assertArrayHasKey($action, $en);
        }

        foreach (['central_payment', 'central_payment_refund', 'expense', 'other_income', 'reimbursement', 'central_receivable', 'fund_account', 'internal_transfer', 'fund_handover', 'hq_funding', 'import_batch'] as $type) {
            self::assertStringContainsString("'{$type}'", $presentation);
        }
    }

    public function test_import_preview_and_receivable_audit_values_are_translated_at_the_presentation_boundary(): void
    {
        $workspace = (string) file_get_contents($this->basePath('resources/views/central-finance/workspace.blade.php'));
        $receivableDetail = (string) file_get_contents($this->basePath('resources/views/central-finance/receivable-detail.blade.php'));
        $controller = (string) file_get_contents($this->basePath('app/Http/Controllers/CentralFinanceWorkspaceController.php'));

        self::assertStringContainsString('__($expenseImportBatch->status)', $workspace);
        self::assertStringContainsString('__($paymentImportBatch->status)', $workspace);
        self::assertStringContainsString("\$translateImportErrors(\$row['errors'])", $workspace);
        self::assertStringContainsString('__($document->status)', $workspace);
        self::assertStringContainsString('__($audit->action)', $receivableDetail);
        self::assertStringNotContainsString('$exception->getMessage()]);', $controller);

        $zh = json_decode((string) file_get_contents($this->basePath('resources/lang/zh-cn.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ([
            'The Central payment import file is invalid or too large.',
            'Payment exceeds the outstanding receivable amount.',
            'The Central Expense import file is invalid or too large.',
            'Reference No is already reserved for this School.',
            'Fund Account is not authorized for this actor.',
        ] as $key) {
            self::assertArrayHasKey($key, $zh);
            self::assertMatchesRegularExpression('/[一-龥]/u', $zh[$key]);
        }
    }

    public function test_cutover_readiness_runtime_labels_and_reasons_have_both_locale_entries(): void
    {
        $readiness = (string) file_get_contents($this->basePath('app/Services/CentralFinanceCutoverReadinessService.php'));
        $zh = json_decode((string) file_get_contents($this->basePath('resources/lang/zh-cn.json')), true, 512, JSON_THROW_ON_ERROR);
        $en = json_decode((string) file_get_contents($this->basePath('resources/lang/en.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach ([
            'Group and School scope', 'The School is not an active Finance Group member.',
            'Active Fund Accounts', 'Create at least one active Central School Fund Account.',
            'Opening Balance audit', 'Every active Fund Account needs a signed initial opening-balance audit matching its configured balance.',
            'Central Head Finance', 'An authorized Head Finance user must have operate scope and an assigned Fund Account.',
            'School Accountant', 'An active School Accountant identity with an assigned Fund Account is required.',
            'Central Finance core services', 'Central Finance schema is incomplete: :tables.',
            'Fresh Start receivable cutoff', 'Set an explicit approved cutover-effective datetime before Central Receivable readiness.',
            'Student Profile reconciliation', 'Student Profile reconciliation must have missing=0, stale=0, and mismatched=0.',
            'Student Profile reconciliation is unavailable for this trusted School source.', 'Resolve failed Student Profile sync events before cutover.',
            'Receivable sync health', 'Receivable reconciliation must have no missing, stale, mismatched, or blocked paid sources.',
            'Receivable reconciliation is unavailable for this trusted School source.', 'Resolve failed Receivable sync events before cutover.',
        ] as $key) {
            self::assertStringContainsString($key, $readiness);
            self::assertArrayHasKey($key, $zh);
            self::assertNotSame($key, $zh[$key]);
            self::assertArrayHasKey($key, $en);
        }
    }

    public function test_account_labels_audit_reasons_and_pagination_use_locale_catalogs(): void
    {
        $display = (string) file_get_contents($this->basePath('app/Services/CentralFinanceFundAccountDisplayService.php'));
        $presentation = (string) file_get_contents($this->basePath('app/Services/CentralFinanceLedgerPresentationService.php'));
        $workspace = (string) file_get_contents($this->basePath('resources/views/central-finance/workspace.blade.php'));
        $zh = json_decode((string) file_get_contents($this->basePath('resources/lang/zh-cn.json')), true, 512, JSON_THROW_ON_ERROR);
        $en = json_decode((string) file_get_contents($this->basePath('resources/lang/en.json')), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringContainsString("__('Cash')", $display);
        self::assertStringContainsString("__('Other')", $display);
        self::assertStringContainsString('function auditReason', $presentation);
        self::assertStringContainsString('->auditReason($audit->reason)', $workspace);

        foreach (['所有导出均只读，并使用当前 School、账户和筛选 scope。', 'no real bank balance'] as $key) {
            self::assertArrayHasKey($key, $zh);
            self::assertArrayHasKey($key, $en);
            self::assertMatchesRegularExpression('/[一-龥]/u', $zh[$key]);
            self::assertMatchesRegularExpression('/[A-Za-z]/', $en[$key]);
        }

        foreach (['Showing', 'to', 'of', 'results', 'Pagination Navigation', 'Go to page :page'] as $key) {
            self::assertArrayHasKey($key, $zh);
            self::assertArrayHasKey($key, $en);
            self::assertNotSame($key, $zh[$key]);
        }

        $zhPagination = require $this->basePath('resources/lang/zh-cn/pagination.php');
        $enPagination = require $this->basePath('resources/lang/en/pagination.php');
        self::assertSame('&laquo; 上一页', $zhPagination['previous']);
        self::assertSame('下一页 &raquo;', $zhPagination['next']);
        self::assertSame('&laquo; Previous', $enPagination['previous']);
        self::assertSame('Next &raquo;', $enPagination['next']);
    }

    /** @return list<string> */
    private function translationSources(): array
    {
        $files = [$this->basePath('resources/views/layouts/sidebar.blade.php')];
        $directory = new \RecursiveDirectoryIterator($this->basePath('resources/views/central-finance'));
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
        foreach (array_merge(
            glob($this->basePath('app/Http/Controllers/CentralFinance*.php')) ?: [],
            glob($this->basePath('app/Services/CentralFinance*.php')) ?: [],
            [$this->basePath('app/Http/Controllers/FinanceOperatingWorkspaceController.php')],
        ) as $file) {
            $files[] = $file;
        }
        $keys = [];
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            if (str_ends_with($file, 'layouts/sidebar.blade.php')) {
                $contents = (string) strstr($contents, '@if ($hasCentralFinanceIdentity)');
                $contents = explode('{{-- XIAOBAILONG-INTEGRATION', $contents, 2)[0];
            }
            preg_match_all('/__\([\'\"]([^\'\"]+)[\'\"]\)/', $contents, $matches);
            foreach ($matches[1] as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    private function basePath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
    }
}
