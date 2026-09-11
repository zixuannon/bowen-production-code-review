<?php

namespace App\Exports;

use App\Services\FeesPaymentService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** @internal Hidden worksheet containing scoped named ranges for Excel validation. */
final class CentralFinanceGroupImportV21ValidationListsSheet implements FromArray, WithEvents, WithTitle
{
    /** @param list<array{code:string,name:string}> $schools @param list<array{code:string,name:string,school_code:?string,account_type:string,owner_type:string,currency:string}> $accounts @param list<array{school_code:string,type:string,category_code:string,name:string}> $categories */
    public function __construct(private readonly array $schools, private readonly array $accounts, private readonly array $categories) {}
    public function title(): string { return 'Validation Lists'; }
    public function array(): array
    {
        $rows = [['School Code', 'School Name', 'Fund Account Code', 'Fund Account Type', 'Account Owner', 'Currency', 'Category Code', 'Category School', 'Category Type', 'Payment Method']];
        $max = max(count($this->schools), count($this->accounts), count($this->categories), count(FeesPaymentService::PAYMENT_METHODS), 1);
        for ($index = 0; $index < $max; $index++) {
            $school = $this->schools[$index] ?? []; $account = $this->accounts[$index] ?? []; $category = $this->categories[$index] ?? [];
            $rows[] = [$school['code'] ?? '', $school['name'] ?? '', $account['code'] ?? '', $account['account_type'] ?? '', $account['owner_type'] ?? '', $account['currency'] ?? '', $category['category_code'] ?? '', $category['school_code'] ?? '', $category['type'] ?? '', FeesPaymentService::PAYMENT_METHODS[$index] ?? ''];
        }
        return $rows;
    }
    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate(); $workbook = $sheet->getParent(); $max = max(count($this->schools), count($this->accounts), count($this->categories), count(FeesPaymentService::PAYMENT_METHODS), 1) + 1;
            foreach ($this->schools as $index => $school) $sheet->setCellValueExplicit('A'.($index + 2), (string) $school['code'], DataType::TYPE_STRING);
            foreach ($this->accounts as $index => $account) $sheet->setCellValueExplicit('C'.($index + 2), (string) $account['code'], DataType::TYPE_STRING);
            foreach ($this->categories as $index => $category) $sheet->setCellValueExplicit('G'.($index + 2), (string) $category['category_code'], DataType::TYPE_STRING);
            $workbook->addNamedRange(new NamedRange('SchoolCodes', $sheet, "\$A\$2:\$A\${$max}"));
            $workbook->addNamedRange(new NamedRange('SchoolNames', $sheet, "\$B\$2:\$B\${$max}"));
            $workbook->addNamedRange(new NamedRange('FundAccountCodes', $sheet, "\$C\$2:\$C\${$max}"));
            $workbook->addNamedRange(new NamedRange('FundAccountTypes', $sheet, "\$D\$2:\$D\${$max}"));
            $workbook->addNamedRange(new NamedRange('FundAccountOwners', $sheet, "\$E\$2:\$E\${$max}"));
            $workbook->addNamedRange(new NamedRange('FundAccountCurrencies', $sheet, "\$F\$2:\$F\${$max}"));
            $workbook->addNamedRange(new NamedRange('CategoryCodes', $sheet, "\$G\$2:\$G\${$max}"));
            $workbook->addNamedRange(new NamedRange('Payment_Methods', $sheet, '$J$2:$J$'.(count(FeesPaymentService::PAYMENT_METHODS) + 1)));
            $sheet->setCellValue('N2', '');
            $workbook->addNamedRange(new NamedRange('Empty_Options', $sheet, '$N$2'));
            $this->addSchoolAccountRanges($workbook, $sheet); $this->addCategoryRanges($workbook, $sheet);
            $sheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        }];
    }
    private function addSchoolAccountRanges(\PhpOffice\PhpSpreadsheet\Spreadsheet $workbook, Worksheet $sheet): void
    {
        foreach ($this->schools as $school) {
            $code = $school['code']; $accounts = array_values(array_filter($this->accounts, static fn (array $account): bool => $account['school_code'] === $code || $account['owner_type'] === 'hq'));
            $rowStart = $sheet->getHighestRow() + 2; $sheet->setCellValue("K{$rowStart}", "Fund Accounts for {$code}");
            foreach ($accounts as $offset => $account) $sheet->setCellValueExplicit('K'.($rowStart + 1 + $offset), (string) $account['code'], DataType::TYPE_STRING);
            $rowEnd = max($rowStart + 1, $rowStart + count($accounts));
            $workbook->addNamedRange(new NamedRange('FundAccounts_'.$this->safeName($code), $sheet, "\$K\$".($rowStart + 1).":\$K\${$rowEnd}"));
        }
    }
    private function addCategoryRanges(\PhpOffice\PhpSpreadsheet\Spreadsheet $workbook, Worksheet $sheet): void
    {
        foreach ($this->schools as $school) foreach (['income', 'expense'] as $type) {
            $categories = array_values(array_filter($this->categories, static fn (array $category): bool => $category['school_code'] === $school['code'] && $category['type'] === $type));
            $rowStart = $sheet->getHighestRow() + 2; $sheet->setCellValue("L{$rowStart}", "Categories {$school['code']} {$type}");
            foreach ($categories as $offset => $category) $sheet->setCellValueExplicit('L'.($rowStart + 1 + $offset), (string) $category['category_code'], DataType::TYPE_STRING);
            $rowEnd = max($rowStart + 1, $rowStart + count($categories));
            $workbook->addNamedRange(new NamedRange('Categories_'.$this->safeName($school['code']).'_'.$type, $sheet, "\$L\$".($rowStart + 1).":\$L\${$rowEnd}"));
        }
    }
    private function safeName(string $value): string { return preg_replace('/[^A-Za-z0-9_]/', '_', $value) ?: 'UNKNOWN'; }
}
