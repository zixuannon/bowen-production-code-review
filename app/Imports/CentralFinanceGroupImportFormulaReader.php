<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;

/**
 * Marker import: Group Finance Import V2.1 supports formula-derived canonical
 * identity fields in its downloaded workbook. The existing heading and row
 * validation contract remains owned by CentralFinanceGroupImportService.
 */
final class CentralFinanceGroupImportFormulaReader implements WithCalculatedFormulas
{
}
