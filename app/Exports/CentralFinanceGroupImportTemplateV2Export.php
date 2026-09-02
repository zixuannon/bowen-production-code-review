<?php
namespace App\Exports;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
final class CentralFinanceGroupImportTemplateV2Export implements FromArray, WithHeadings {
 public const VERSION='group-finance-v2';
 public const HEADINGS=['序号','School Code','校区','日期','报销人','摘要','Fund Account Code','Fund Account Type','Account Owner','Category Code','付款方式','收入','支出','余款','Reference / 单据号','Currency','备注'];
 public function headings():array{return self::HEADINGS;}
 public function array():array{return [['1','SCH202615','Zixuan','2026-09-02','Claimant','Description','ZIX-CASH','cash','school','SUPPLIES-1','Cash','1000','','','REF-001','MMK','']];}
}
