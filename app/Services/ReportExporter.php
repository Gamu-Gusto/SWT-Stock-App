<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV (Excel-compatible, UTF-8 with BOM) and XLSX exports of report results.
 */
class ReportExporter
{
    public function csv(array $report): StreamedResponse
    {
        $filename = $this->filename($report, 'csv');

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$report['title'].' – generated '.now()->format('d/m/Y H:i')]);
            fputcsv($out, ['Period', $this->date($report['filters']['date_from'] ?? null).' – '.$this->date($report['filters']['date_to'] ?? null)]);
            fputcsv($out, []);
            fputcsv($out, array_column($report['columns'], 'label'));
            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map(fn ($c) => $this->cell($row[$c['key']] ?? null, $c['type'], false), $report['columns']));
            }
            if ($report['summary']) {
                fputcsv($out, []);
                foreach ($report['summary'] as $label => $value) {
                    fputcsv($out, [$label, $value]);
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function xlsx(array $report): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $report['title']), 0, 31));
        $sheet->setCellValue('A1', $report['title']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Generated '.now()->format('d/m/Y H:i').' · Period '.$this->date($report['filters']['date_from'] ?? null).' – '.$this->date($report['filters']['date_to'] ?? null));

        $headerRow = 4;
        foreach ($report['columns'] as $i => $col) {
            $sheet->setCellValue([$i + 1, $headerRow], $col['label']);
        }
        $lastCol = count($report['columns']);
        $sheet->getStyle([1, $headerRow, $lastCol, $headerRow])->getFont()->setBold(true);
        $r = $headerRow + 1;
        foreach ($report['rows'] as $row) {
            foreach ($report['columns'] as $i => $col) {
                $value = $this->cell($row[$col['key']] ?? null, $col['type'], true);
                $sheet->setCellValue([$i + 1, $r], $value);
                if ($col['type'] === 'money') {
                    $sheet->getStyle([$i + 1, $r])->getNumberFormat()->setFormatCode('"R" #,##0.00');
                }
            }
            $r++;
        }
        if ($report['summary']) {
            $r++;
            foreach ($report['summary'] as $label => $value) {
                $sheet->setCellValue([1, $r], $label);
                $sheet->setCellValue([2, $r], $value);
                $sheet->getStyle([1, $r])->getFont()->setBold(true);
                $r++;
            }
        }
        for ($i = 1; $i <= $lastCol; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        $sheet->freezePane([1, $headerRow + 1]);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $this->filename($report, 'xlsx'), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    protected function cell(mixed $value, string $type, bool $native): mixed
    {
        if ($value === null) {
            return '';
        }

        return match ($type) {
            'money' => $native ? (float) $value : (string) $value,
            'int', 'signed' => (int) $value,
            'date' => $this->date($value),
            'datetime' => CarbonImmutable::parse($value)->format('d/m/Y H:i'),
            default => (string) $value,
        };
    }

    protected function date(mixed $value): string
    {
        return $value ? CarbonImmutable::parse($value)->format('d/m/Y') : '';
    }

    protected function filename(array $report, string $ext): string
    {
        return 'swt-'.str_replace('_', '-', $report['key']).'-'.now()->format('Ymd-His').'.'.$ext;
    }
}
