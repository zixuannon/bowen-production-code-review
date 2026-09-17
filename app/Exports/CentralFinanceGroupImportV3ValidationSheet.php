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

final class CentralFinanceGroupImportV3ValidationSheet implements FromArray, WithEvents, WithTitle
{
    public function __construct(private readonly array $schools, private readonly array $funds, private readonly array $chart, private readonly array $fundAllocations, private readonly array $chartAllocations, private readonly array $activity) {}
    public function title(): string { return 'Validation'; }
    public function array(): array { return []; }
    public static function schoolKey(string $code): string { return 'S'.substr(hash('sha256', $code), 0, 20); }
    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $columns = [
                'A' => ['V3_SchoolCodes', array_column($this->schools, 'code')],
                'B' => ['V3_SchoolNames', array_column($this->schools, 'name')],
                'C' => ['V3_SchoolKeys', array_map(fn ($s) => self::schoolKey($s['code']), $this->schools)],
                'D' => ['V3_FundCodes', array_column($this->funds, 'code')],
                'E' => ['V3_FundNames', array_column($this->funds, 'name')],
                'F' => ['V3_FundCurrencies', array_column($this->funds, 'currency')],
                'G' => ['V3_AccountCodes', array_column($this->chart, 'category_code')],
                'H' => ['V3_AccountTypes', array_map(fn ($a) => ucfirst($a['type']), $this->chart)],
                'I' => ['V3_AccountNames', array_column($this->chart, 'name')],
                'J' => ['V3_PaymentMethods', FeesPaymentService::PAYMENT_METHODS],
                'K' => ['V3_AccountKeys', array_map(fn ($a) => 'code:'.$a['category_code'], $this->chart)],
                'L' => ['V3_FundKeys', array_map(fn ($a) => 'code:'.$a['code'], $this->funds)],
                'N' => ['V3_FundIncoming', array_map(fn ($a) => (float) ($a['incoming'] ?? 0), $this->funds)],
                'O' => ['V3_FundOutgoing', array_map(fn ($a) => (float) ($a['outgoing'] ?? 0), $this->funds)],
            ];
            foreach ($columns as $column => [$name, $values]) $this->range($sheet, $name, $column, 2, $values);
            $this->range($sheet, 'V3_Fund_EMPTY', 'P', 2, []);
            $this->range($sheet, 'V3_CoA_EMPTY', 'Q', 2, []);
            foreach ($this->activity as $index => $item) {
                $row = $index + 2;
                $sheet->setCellValueExplicit('R'.$row, $item['school_code'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('S'.$row, $item['currency'], DataType::TYPE_STRING);
                $sheet->setCellValue('T'.$row, $item['incoming']);
                $sheet->setCellValue('U'.$row, $item['outgoing']);
            }
            $start = 2;
            foreach ($this->schools as $school) {
                // HQ ownership never bypasses explicit school allocation.
                $fundCodes = array_values(array_unique(array_map(fn ($a) => (string) $a['code'], array_filter($this->fundAllocations, fn ($a) => ($a['school_code'] ?? null) === $school['code']))));
                $chartCodes = array_values(array_unique(array_map(fn ($a) => (string) $a['category_code'], array_filter($this->chartAllocations, fn ($a) => ($a['school_code'] ?? null) === $school['code']))));
                $key = self::schoolKey($school['code']);
                $this->range($sheet, 'V3_Fund_'.$key, 'V', $start, $fundCodes);
                $this->range($sheet, 'V3_CoA_'.$key, 'W', $start, $chartCodes);
                $start += max(count($fundCodes), count($chartCodes), 1) + 1;
            }
            $sheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        }];
    }
    private function range(Worksheet $sheet, string $name, string $column, int $start, array $values): void
    {
        foreach ($values ?: [''] as $offset => $value) $sheet->setCellValueExplicit($column.($start + $offset), $value, is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
        $end = $start + max(count($values), 1) - 1;
        $sheet->getParent()->addNamedRange(new NamedRange($name, $sheet, '$'.$column.'$'.$start.':$'.$column.'$'.$end));
    }
}
