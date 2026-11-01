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
        'A' => ['Office', 30], 'B' => ['Category', 22], 'C' => ['Article', 26], 'D' => ['Description', 36],
        'E' => ['Property No.', 18], 'F' => ['Unit', 10], 'G' => ['Date Acquired', 13], 'H' => ['Unit Value', 14],
        'I' => ['Qty (Card)', 10], 'J' => ['On Hand', 10], 'K' => ['Total Value', 15], 'L' => ['Grade Level', 13],
        'M' => ['Transferred / Donated By', 18], 'N' => ['Condition', 14], 'O' => ['With MR/ARE', 11],
        'P' => ['Accountable Officer', 24], 'Q' => ['End User / Teacher', 24], 'R' => ['Location', 22], 'S' => ['Remarks', 30],
    ];

    /**
     * @param  Collection<int, PropertyItem>  $items
     */
    public function generate(Collection $items): string
    {
        $spreadsheet = WorkbookStyle::workbook();
        $sheet = WorkbookStyle::sheet($spreadsheet, 'School Property', landscape: true);

        WorkbookStyle::banner($sheet, 'A1:S1', 'SCHOOL PROPERTY', 13);
        WorkbookStyle::text($sheet, 'A2:S2', 'Exported '.now()->format('F j, Y g:i A').' · '.$items->count().' item(s)', italic: true);

        foreach (self::HEADINGS as $column => [$heading, $width]) {
            $sheet->setCellValue("{$column}4", $heading);
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        WorkbookStyle::header($sheet, 'A4:S4');

        $row = 5;
        foreach ($items as $item) {
            $sheet->fromArray([
                $item->office?->office_name,
                $item->category->label(),
                $item->article,
                $item->description,
                $item->property_no,
                $item->unit_of_measure,
                $item->date_acquired === null ? null : Date::PHPToExcel($item->date_acquired),
                $item->unit_value === null ? null : (float) $item->unit_value,
                $item->quantity,
                $item->on_hand_count,
                $item->totalValue(),
                $item->grade_level,
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
        WorkbookStyle::borders($sheet, "A4:S{$last}");
        $sheet->getStyle("G5:G{$last}")->getNumberFormat()->setFormatCode('m/d/yyyy');
        WorkbookStyle::money($sheet, "H5:H{$last}");
        WorkbookStyle::money($sheet, "K5:K{$last}");
        $sheet->setAutoFilter("A4:S{$last}");
        $sheet->freezePane('A5');
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4, 4);

        return WorkbookStyle::save($spreadsheet);
    }
}
