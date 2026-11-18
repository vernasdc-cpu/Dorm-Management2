<?php

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;

function reference_read_workbook(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('The file is not a valid XLSX workbook.');
    try {
        $size = 0;
        if ($zip->numFiles > 1000) throw new RuntimeException('The workbook contains too many files.');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            if ($entry === false) throw new RuntimeException('Cannot read the workbook.');
            $size += $entry['size'];
            if ($size > 30 * 1024 * 1024) throw new RuntimeException('The uncompressed workbook exceeds 30 MB.');
        }
    } finally {
        $zip->close();
    }

    $reader = new Xlsx();
    try {
        $info = $reader->listWorksheetInfo($path);
        if (!$info) throw new RuntimeException('The workbook has no worksheets.');
        if ($info[0]['totalRows'] > 10001 || $info[0]['totalColumns'] > 20) throw new RuntimeException('The first worksheet may contain at most 10,000 data rows and 20 columns.');
        $reader->setLoadSheetsOnly([$info[0]['worksheetName']]);
        $spreadsheet = $reader->load($path);
    } catch (\PhpOffice\PhpSpreadsheet\Exception $exception) {
        throw new RuntimeException('Cannot read this XLSX workbook. Check that it is not damaged or password-protected.', 0, $exception);
    } catch (RuntimeException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        throw new RuntimeException('Cannot read this XLSX workbook. Check that it is not damaged or password-protected.', 0, $exception);
    }

    try {
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        if ($highestRow > 10001 || $highestColumn > 20) throw new RuntimeException('The first worksheet may contain at most 10,000 data rows and 20 columns.');
        $expected = ['bank name', 'account name', 'account number', 'reference number', 'date'];
        $columns = [];
        for ($column = 1; $column <= $highestColumn; $column++) {
            $cell = $sheet->getCell([$column, 1]);
            if ($cell->getDataType() === DataType::TYPE_FORMULA) throw new RuntimeException('Column headings cannot contain formulas.');
            $heading = mb_strtolower(trim((string)$cell->getValue()));
            if ($heading === '') continue;
            if (!in_array($heading, $expected, true) || isset($columns[$heading])) throw new RuntimeException('Use exactly the five required column headings, without duplicates.');
            $columns[$heading] = $column;
        }
        if (count($columns) !== count($expected)) throw new RuntimeException('The first row must contain Bank Name, Account Name, Account Number, Reference Number, and Date.');
        $rows = [];
        for ($row = 2; $row <= $highestRow; $row++) {
            $cells = [];
            $nonempty = false;
            for ($column = 1; $column <= $highestColumn; $column++) {
                $cell = $sheet->getCell([$column, $row]);
                $value = $cell->getValue();
                if ($value !== null && trim((string)$value) !== '') $nonempty = true;
                if ($cell->getDataType() === DataType::TYPE_FORMULA) throw new RuntimeException("Row $row: formulas are not supported.");
                if (!in_array($column, $columns, true) && $value !== null && trim((string)$value) !== '') throw new RuntimeException("Row $row: unexpected data outside the five columns.");
            }
            if (!$nonempty) continue;
            foreach ($expected as $heading) $cells[$heading] = $sheet->getCell([$columns[$heading], $row]);
            $values = [];
            foreach (array_slice($expected, 0, 4) as $heading) {
                $cell = $cells[$heading];
                if (!in_array($cell->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_INLINE], true)) {
                    throw new RuntimeException("Row $row: $heading must be an Excel text cell; format it as Text and re-enter the value.");
                }
                $value = trim((string)$cell->getValue());
                $limit = in_array($heading, ['bank name', 'account name'], true) ? 150 : 100;
                if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit) throw new RuntimeException("Row $row: $heading is required and must be at most $limit characters.");
                $values[] = $value;
            }
            $dateCell = $cells['date'];
            $rawDate = $dateCell->getValue();
            if ($dateCell->getDataType() === DataType::TYPE_NUMERIC && Date::isDateTime($dateCell) && is_numeric($rawDate) && (float)$rawDate >= 1 && (float)$rawDate < 2958466 && floor((float)$rawDate) === (float)$rawDate && !($spreadsheet->getExcelCalendar() === Date::CALENDAR_WINDOWS_1900 && (float)$rawDate === 60.0)) {
                $date = Date::excelToDateTimeObject((float)$rawDate)->format('Y-m-d');
            } elseif (in_array($dateCell->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_INLINE], true)) {
                $date = trim((string)$rawDate);
            } else {
                throw new RuntimeException("Row $row: date must be an Excel date or text in YYYY-MM-DD format.");
            }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < '1000-01-01' || $date > '9999-12-31') throw new RuntimeException("Row $row: enter a valid date in YYYY-MM-DD format.");
            $values[] = $date;
            $rows[] = $values;
        }
        if (!$rows) throw new RuntimeException('The first worksheet has no data rows.');
        return $rows;
    } finally {
        $spreadsheet->disconnectWorksheets();
    }
}
