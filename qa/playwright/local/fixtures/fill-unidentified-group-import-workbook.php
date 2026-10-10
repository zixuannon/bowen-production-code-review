<?php

declare(strict_types=1);

require dirname(__DIR__, 4).'/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

if (($argc !== 3 && $argc !== 4) || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php fill-unidentified-group-import-workbook.php <downloaded-template.xlsx> <reference> [valid|invalid]\n");
    exit(2);
}

$reference = trim($argv[2]);
$mode = $argv[3] ?? 'valid';
if (!preg_match('/^LOCAL-GROUP-IMPORT-[A-Z0-9-]+$/', $reference)) {
    fwrite(STDERR, "A fixed local-only bank reference is required.\n");
    exit(2);
}
if (!in_array($mode, ['valid', 'invalid'], true)) {
    fwrite(STDERR, "The workbook mode must be valid or invalid.\n");
    exit(2);
}

$workbook = IOFactory::load($argv[1]);
$sheet = $workbook->getSheet(0);
$fundAccounts = $workbook->getSheetByName('Fund Accounts');
if ($fundAccounts === null) {
    fwrite(STDERR, "The downloaded Group Finance template is missing its Fund Accounts reference sheet.\n");
    exit(2);
}
$fundAccountName = null;
for ($row = 2; $row <= $fundAccounts->getHighestDataRow(); $row++) {
    if ((string) $fundAccounts->getCell("A{$row}")->getValue() === 'GROUP_QA_IMPORT_BANK') {
        $fundAccountName = (string) $fundAccounts->getCell("B{$row}")->getValue();
        break;
    }
}
if ($fundAccountName === null || $fundAccountName === '') {
    fwrite(STDERR, "The downloaded template does not include the dedicated local QA import Fund Account.\n");
    exit(2);
}
foreach ([
    'B2' => '2026-10-09',
    'C2' => $mode === 'invalid' ? 'Unknown local campus' : 'Unidentified / 待识别',
    'D2' => $mode === 'invalid' ? 'UNKNOWN-LOCAL-SCHOOL' : 'UNIDENTIFIED',
    'E2' => 'Synthetic local sender',
    'F2' => $mode === 'invalid' ? '=HYPERLINK("https://example.invalid")' : 'Local Group Import browser acceptance',
    'J2' => 'GROUP_QA_IMPORT_BANK',
    'K2' => $fundAccountName,
    'O2' => $reference,
    'P2' => 'Disposable local-only QA row',
] as $cell => $value) {
    $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
}
$sheet->setCellValue('M2', 75);
IOFactory::createWriter($workbook, 'Xlsx')->save($argv[1]);
$workbook->disconnectWorksheets();
