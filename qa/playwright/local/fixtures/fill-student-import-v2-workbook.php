<?php

declare(strict_types=1);

require dirname(__DIR__, 4).'/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php fill-student-import-v2-workbook.php <downloaded-template.xlsx>\n");
    exit(2);
}

$path = $argv[1];
$workbook = IOFactory::load($path);
try {
    $sheet = $workbook->getSheetByName('Import');
    $classSheet = $workbook->getSheetByName('Class Sections');
    $classSection = trim((string) $classSheet?->getCell('A2')->getValue());
    if ($sheet === null || $classSection === '') {
        throw new RuntimeException('The downloaded workbook is missing its current School Class Section lookup.');
    }

    for ($row = 2; $row <= 11; $row++) {
        $studentNumber = $row - 1;
        $values = [
            'A' => sprintf('QA-IMPORT-%03d', $studentNumber),
            'B' => sprintf('QA Student %03d', $studentNumber),
            'C' => $classSection,
            'D' => 'Weekday',
            'E' => sprintf('Import Guardian %03d', $studentNumber),
            'F' => sprintf('091234%04d', $studentNumber),
            'G' => '',
            'H' => '',
            'I' => '',
            'J' => date('Y-m-d'),
            'K' => 'Active',
            'L' => 'Disposable BOWEN_QA Student Import V2 browser acceptance.',
        ];
        foreach ($values as $column => $value) {
            $sheet->setCellValueExplicit($column.$row, $value, DataType::TYPE_STRING);
        }
    }

    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($workbook))->save($path);
} finally {
    $workbook->disconnectWorksheets();
}
