<?php

namespace App\Modules\Reports\Services;

use App\Modules\ReportResult;

/**
 * Write the table of a report as CSV, readable by Excel (UTF-8 with a BOM, raw numbers).
 */
class ReportCsv
{
    public function write(ReportResult $result): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\u{FEFF}");

        fputcsv($handle, array_map(fn ($column) => $column->label, $result->columns), escape: '');
        foreach ($result->rows as $row) {
            fputcsv(
                $handle,
                array_map(fn ($column) => $this->safe($column->export($row[$column->key] ?? null)), $result->columns),
                escape: ''
            );
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * A text starting with = + - @ would be run as a formula by a spreadsheet (a name typed by an
     * employee ends up in the export): prefix it with a quote. Negative numbers are left as they are.
     */
    private function safe(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)) {
            return "'".$value;
        }

        return $value;
    }
}
