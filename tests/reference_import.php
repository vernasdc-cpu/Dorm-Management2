<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/reference_import.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$path = tempnam(sys_get_temp_dir(), 'reference-test-');
if ($path === false) throw new RuntimeException('Unable to create a temporary workbook.');
$book = new Spreadsheet();
try {
    $sheet = $book->getActiveSheet();
    $sheet->fromArray(['Bank Name', 'Account Name', 'Account Number', 'Reference Number', 'Date'], null, 'A1');
    $sheet->fromArray(['Bank', 'Person', null, null, null], null, 'A2');
    $sheet->setCellValueExplicit('C2', '00123456789012345678', DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('D2', '0000999', DataType::TYPE_STRING);
    $sheet->setCellValue('E2', Date::PHPToExcel(new DateTimeImmutable('2025-03-04')));
    $sheet->getStyle('E2')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    $save = static function () use ($book, $path): void { (new Xlsx($book))->save($path); };
    $save();
    $expected = [['Bank', 'Person', '00123456789012345678', '0000999', '2025-03-04']];
    if (reference_read_workbook($path) !== $expected) throw new RuntimeException('Excel date or text identifiers were not preserved.');
    $sheet->setCellValueExplicit('E2', '2025-03-04', DataType::TYPE_STRING);
    $save();
    if (reference_read_workbook($path) !== $expected) throw new RuntimeException('Text date was not imported.');

    $reject = static function (string $message) use ($save, $path): void {
        $save();
        try {
            reference_read_workbook($path);
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), $message)) return;
            throw $exception;
        }
        throw new RuntimeException('Invalid workbook was accepted: ' . $message);
    };
    $sheet->setCellValue('E2', '2025-02-30');
    $reject('valid date');
    $sheet->setCellValue('E2', '2025-03-04');
    $sheet->setCellValue('C2', 12345);
    $reject('account number must be an Excel text cell');
    $sheet->setCellValueExplicit('C2', '001234', DataType::TYPE_STRING);
    $sheet->setCellValue('D2', '=123');
    $reject('formulas are not supported');
    $sheet->setCellValueExplicit('D2', '0000999', DataType::TYPE_STRING);
    $sheet->setCellValue('B1', 'Wrong');
    $reject('five required column headings');
    echo "Reference workbook tests passed.\n";
} finally {
    $book->disconnectWorksheets();
    unlink($path);
}
