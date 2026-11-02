<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PropertyItem;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * The School Property list as one flat, filterable sheet.
 */
class PropertyItemExport
{
    private const HEADINGS = [
        'A' => ['Office', 30], 'B' => ['Article', 26], 'C' => ['Description', 36], 'D' => ['Property No.', 18],
        'E' => ['Unit', 10], 'F' => ['Date Acquired', 13], 'G' => ['Unit Value', 14], 'H' => ['Qty (Card)', 10],
        'I' => ['On Hand', 10], 'J' => ['Total Value', 15], 'K' => ['Transferred / Donated By', 18], 'L' => ['Condition', 14],
        'M' => ['With MR/ARE', 11], 'N' => ['Accountable Officer', 24], 'O' => ['End User', 24], 'P' => ['Location', 22],
        'Q' => ['Remarks', 30],
    ];

    /**
     * @param  Collection<int, PropertyItem>  $items
     */
    public function generate(Collection $items): string
    {
        $spreadsheet = WorkbookStyle::workbook();
        $sheet = WorkbookStyle::sheet($spreadsheet, 'School Property', landscape: true);

        WorkbookStyle::banner($sheet, 'A1:Q1', 'SCHOOL PROPERTY', 13);
        WorkbookStyle::text($sheet, 'A2:Q2', 'Exported '.now()->format('F j, Y g:i A').' · '.$items->count().' item(s)', italic: true);

        foreach (self::HEADINGS as $column => [$heading, $width]) {
            $sheet->setCellValue("{$column}4", $heading);
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        WorkbookStyle::header($sheet, 'A4:Q4');

        $row = 5;
        foreach ($items as $item) {
            $sheet->fromArray([
                $item->office?->office_name,
                $item->article,
                $item->description,
                $item->property_no,
                $item->unit_of_measure,
                $item->date_acquired === null ? null : Date::PHPToExcel($item->date_acquired),
                $item->unit_value === null ? null : (float) $item->unit_value,
                $item->quantity,
                $item->on_hand_count,
                $item->totalValue(),
                $item->source?->label(),
                $item->condition === null ? null : ucfirst($item->condition),
                $item->with_are ? 'Yes' : 'No',
                $item->accountableOfficer === null ? null : "{$item->accountableOfficer->last_name}, {$item->accountableOfficer->first_name}",
                $item->endUser === null ? null : "{$item->endUser->last_name}, {$item->endUser->first_name}",
                $item->location,
                $item->remarks,
            ], null, "A{$row}", true);
            $row++;
        }

        $last = max($row - 1, 5);
        WorkbookStyle::borders($sheet, "A4:Q{$last}");
        $sheet->getStyle("F5:F{$last}")->getNumberFormat()->setFormatCode('m/d/yyyy');
        WorkbookStyle::money($sheet, "G5:G{$last}");
        WorkbookStyle::money($sheet, "J5:J{$last}");
        $sheet->setAutoFilter("A4:Q{$last}");
        $sheet->freezePane('A5');
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4, 4);

        return WorkbookStyle::save($spreadsheet);
    }
}
