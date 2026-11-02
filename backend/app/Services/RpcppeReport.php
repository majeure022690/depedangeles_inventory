<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PropertySource;
use App\Models\Equipment;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\PropertyItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Report on the Physical Count of Property, Plant and Equipment for one
 * office: IRIS equipment (one row per property number) plus School Property
 * items on the IT equipment sheet, items under Equipment::PPE_THRESHOLD on a
 * separate semi-expendable sheet.
 *
 * @phpstan-type CountRow array{sheet: string, article: ?string, description: ?string, property_no: ?string, unit: ?string, date: ?\DateTimeInterface, unit_value: ?float, quantity: int, on_hand: int, source: ?PropertySource, functional: bool, unserviceable: bool, with_are: bool, other: ?string}
 */
class RpcppeReport
{
    /** IRIS item type => article. Unlisted types go under their own name. */
    private const ARTICLES = [
        'Copier / Xerox Machine' => 'Photocopier',
        'Fax Machine' => 'Fax Machine',
        'Microphone' => 'Microphone',
        'Digital Camera' => 'Camera',
        'Voice Recorder' => 'Voice Recorder',
        'PABX' => 'PABX Phone System',
        'Computer Table' => 'Computer Table',
        'Desktop (package)' => 'Desktop Computer',
        'CPU' => 'Desktop Computer',
        'Laptop' => 'Laptop Computer',
        'Multifunction Printer' => 'Printer',
        'Interactive Whiteboards / Smartboards' => 'Interactive Whiteboard',
        'Speakers' => 'Multimedia Speaker',
        'Modem / Router' => 'Network Hardware (Router, Switch etc.)',
        'Access Point' => 'Network Hardware (Router, Switch etc.)',
        'Network Switch' => 'Network Hardware (Router, Switch etc.)',
        'Firewall Appliance' => 'Network Hardware (Router, Switch etc.)',
        'Others' => 'Other',
    ];

    private const EQUIPMENT_SOURCES = [
        'School Division Office' => PropertySource::Division,
        'Central Office' => PropertySource::RegionalCentral,
        'Regional Office' => PropertySource::RegionalCentral,
        'Local Government Unit (LGU)' => PropertySource::Lgu,
        "Parent Teacher's Association" => PropertySource::PtaOthers,
        'Private Corporation' => PropertySource::PtaOthers,
        'Non-Governmental Organization (NGO)' => PropertySource::PtaOthers,
        'DICT' => PropertySource::PtaOthers,
        'Other National Government Agency' => PropertySource::PtaOthers,
    ];

    private const SOURCE_COLUMNS = ['division' => 'L', 'regional_central' => 'M', 'lgu' => 'N', 'pta_others' => 'O'];

    private const FIRST_ROW = 11;

    private const CHECK = '✓';

    /**
     * @param  array{school_year: string, accountable_officer_id: ?int, assumed_on: ?string, prepared_by: ?Personnel, school_head: ?Personnel, supply_officer: ?Personnel}  $input
     */
    public function generate(Office $office, User $user, array $input): string
    {
        $officer = $input['accountable_officer_id'] !== null
            ? Personnel::query()->with('personnelPosition:id,name')->find($input['accountable_officer_id'])
            : null;

        $bySheet = [];
        foreach ([...$this->equipmentRows($office, $user, $officer), ...$this->propertyRows($office, $user, $officer)] as $row) {
            $bySheet[$row['sheet']][] = $row;
        }

        $spreadsheet = WorkbookStyle::workbook();

        foreach ([false, true] as $semi) {
            $title = $this->sheetTitle($semi);
            $sheet = WorkbookStyle::sheet($spreadsheet, $semi ? 'IT Semi-Exp' : 'IT', landscape: true);
            $this->physicalCountSheet($sheet, $title, $office, $officer, $input, $bySheet[$title] ?? []);
        }

        return WorkbookStyle::save($spreadsheet);
    }

    private function sheetTitle(bool $semi): string
    {
        return 'IT EQUIPMENT'.($semi ? ' (SEMI-EXPENDABLE)' : '');
    }

    /**
     * @return list<CountRow>
     */
    private function equipmentRows(Office $office, User $user, ?Personnel $officer): array
    {
        return array_values(Equipment::query()
            ->visibleTo($user)
            ->when($officer !== null, fn ($query) => $query->where('current_accountable_officer_id', $officer?->id), fn ($query) => $query->atOffice($office->id))
            ->inCustody()
            ->with(['itemType:id,name', 'brand:id,name', 'equipmentCondition:id,name', 'currentEndUser:id,first_name,middle_name,last_name,suffix'])
            ->orderBy('received_date')
            ->orderBy('property_no')
            ->get()
            ->map(fn (Equipment $item): array => $this->equipmentRow($item))
            ->all());
    }

    /**
     * @return list<CountRow>
     */
    private function propertyRows(Office $office, User $user, ?Personnel $officer): array
    {
        return array_values(PropertyItem::query()
            ->visibleTo($user)
            ->where('office_id', $office->id)
            ->when($officer !== null, fn ($query) => $query->where('accountable_officer_id', $officer?->id))
            ->with('endUser:id,first_name,middle_name,last_name,suffix')
            ->orderBy('date_acquired')
            ->orderBy('article')
            ->get()
            ->map(fn (PropertyItem $item): array => $this->propertyRow($item))
            ->all());
    }

    /**
     * @return CountRow
     */
    private function equipmentRow(Equipment $item): array
    {
        $cost = $item->acquisition_cost === null ? null : (float) $item->acquisition_cost;
        $condition = $item->equipmentCondition?->name;
        $endUser = $item->currentEndUser;

        return [
            'sheet' => $this->sheetTitle($cost === null || $cost < Equipment::PPE_THRESHOLD),
            'article' => self::ARTICLES[(string) $item->itemType?->name] ?? $item->specified('item_type_other', $item->itemType?->name),
            'description' => $item->propertyDescription(),
            'property_no' => $item->property_no,
            'unit' => $item->uom,
            'date' => $item->received_date,
            'unit_value' => $cost,
            'quantity' => 1,
            'on_hand' => in_array($item->disposition_status, Equipment::MISSING, true) ? 0 : 1,
            'source' => self::EQUIPMENT_SOURCES[$item->source_acquisition] ?? null,
            'functional' => ! $item->non_functional && $condition === 'Serviceable',
            'unserviceable' => $item->non_functional || in_array($condition, ['Unserviceable', 'For Repair'], true),
            'with_are' => $item->current_accountable_officer_id !== null,
            'other' => $this->joined([
                $item->equipment_location,
                $endUser !== null && $item->current_end_user_id !== $item->current_accountable_officer_id ? 'End user: '.$endUser->printedName() : null,
                $item->disposition_status !== 'Normal' ? $item->disposition_status : null,
            ]),
        ];
    }

    /**
     * @return CountRow
     */
    private function propertyRow(PropertyItem $item): array
    {
        return [
            'sheet' => $this->sheetTitle($item->isSemiExpendable()),
            'article' => $item->article,
            'description' => $item->description,
            'property_no' => $item->property_no,
            'unit' => $item->unit_of_measure,
            'date' => $item->date_acquired,
            'unit_value' => $item->unit_value === null ? null : (float) $item->unit_value,
            'quantity' => $item->quantity,
            'on_hand' => $item->on_hand_count,
            'source' => $item->source,
            'functional' => $item->condition === 'functional',
            'unserviceable' => $item->condition === 'unserviceable',
            'with_are' => $item->with_are,
            'other' => $this->joined([
                $item->location,
                $item->endUser === null ? null : 'End user: '.$item->endUser->printedName(),
                $item->remarks,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<CountRow>  $rows
     */
    private function physicalCountSheet(Worksheet $sheet, string $title, Office $office, ?Personnel $officer, array $input, array $rows): void
    {
        WorkbookStyle::columns($sheet, [
            'A' => 22, 'B' => 38, 'C' => 20, 'D' => 9, 'E' => 11, 'F' => 13, 'G' => 12, 'H' => 9, 'I' => 9,
            'J' => 7, 'K' => 11, 'L' => 9, 'M' => 9, 'N' => 8, 'O' => 9, 'P' => 10, 'Q' => 11, 'R' => 9, 'S' => 30,
        ]);
        $this->documentHeading($sheet, 'A', 'S', $title, $input['school_year']);
        $this->accountabilityLine($sheet, $office, $officer, $input['assumed_on']);

        $sheet->fromArray(['ARTICLE', 'DESCRIPTION', 'PROPERTY NUMBER', 'UNIT OF MEASURE', 'DATE ACQUIRED', 'ACQUISITION COST', 'UNIT VALUE', 'BALANCE PER CARD (Qty)', 'ON HAND PER COUNT (Qty)', 'SHORTAGE / OVERAGE', null, 'TRANSFERRED / DONATIONS BY', null, null, null, 'REMARKS'], null, 'A9');
        $sheet->fromArray(['Qty', 'Value', 'Division', 'Regional / Central', 'LGU', 'PTA / Others', 'Functional', 'Unserviceable', 'With MR / ARE', 'Other Information / Status'], null, 'J10');
        foreach (range('A', 'I') as $column) {
            $sheet->mergeCells("{$column}9:{$column}10");
        }
        $sheet->mergeCells('J9:K9');
        $sheet->mergeCells('L9:O9');
        $sheet->mergeCells('P9:S9');
        WorkbookStyle::header($sheet, 'A9:S10');
        $sheet->getRowDimension(9)->setRowHeight(24);
        $sheet->getRowDimension(10)->setRowHeight(54);

        $row = self::FIRST_ROW;
        foreach ($rows as $item) {
            $this->countRow($sheet, $row, $item);
            $row++;
        }

        if ($rows === []) {
            $sheet->setCellValue("B{$row}", 'No items recorded in IRIS.');
            $sheet->getStyle("B{$row}")->getFont()->setItalic(true);
            $row++;
        }

        $last = $row - 1;
        $sheet->setCellValue("E{$row}", 'TOTAL:');
        $sheet->setCellValue("F{$row}", '=SUM(F'.self::FIRST_ROW.":F{$last})");
        $sheet->setCellValue("K{$row}", '=SUM(K'.self::FIRST_ROW.":K{$last})");
        WorkbookStyle::bold($sheet, "A{$row}:S{$row}");
        WorkbookStyle::borders($sheet, 'A'.self::FIRST_ROW.":S{$row}");
        WorkbookStyle::money($sheet, 'F'.self::FIRST_ROW.":G{$row}");
        WorkbookStyle::money($sheet, 'K'.self::FIRST_ROW.":K{$row}");
        WorkbookStyle::center($sheet, 'C'.self::FIRST_ROW.":E{$row}");
        WorkbookStyle::center($sheet, 'H'.self::FIRST_ROW.":R{$row}");
        $sheet->getStyle('A'.self::FIRST_ROW.":S{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);

        $row += 3;
        $sheet->setCellValue("B{$row}", 'Prepared by:');
        $sheet->setCellValue("F{$row}", 'Noted:');
        $sheet->setCellValue("O{$row}", 'Received / Verified by:');
        WorkbookStyle::signature($sheet, 'B'.($row + 2), 'B'.($row + 3), $input['prepared_by']?->printedName(), 'Property Custodian');
        WorkbookStyle::signature($sheet, 'F'.($row + 2).':K'.($row + 2), 'F'.($row + 3).':K'.($row + 3), $input['school_head']?->printedName(), 'School Head');
        WorkbookStyle::signature($sheet, 'O'.($row + 2).':S'.($row + 2), 'O'.($row + 3).':S'.($row + 3), $input['supply_officer']?->printedName(), 'Supply Officer');

        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(9, 10)->setPrintArea('A1:S'.($row + 3));
        $sheet->freezePane('A11');
    }

    /**
     * @param  CountRow  $item
     */
    private function countRow(Worksheet $sheet, int $row, array $item): void
    {
        $sheet->setCellValue("A{$row}", $item['article']);
        $sheet->setCellValue("B{$row}", $item['description']);
        $sheet->setCellValueExplicit("C{$row}", (string) $item['property_no'], 's');
        $sheet->setCellValue("D{$row}", $item['unit']);
        $sheet->setCellValue("G{$row}", $item['unit_value']);
        $sheet->setCellValue("H{$row}", $item['quantity']);
        $sheet->setCellValue("I{$row}", $item['on_hand']);
        $sheet->setCellValue("F{$row}", $item['unit_value'] === null ? null : "=G{$row}*H{$row}");
        $sheet->setCellValue("J{$row}", "=I{$row}-H{$row}");
        $sheet->setCellValue("K{$row}", $item['unit_value'] === null ? null : "=J{$row}*G{$row}");
        $sheet->setCellValue("P{$row}", $item['functional'] ? self::CHECK : null);
        $sheet->setCellValue("Q{$row}", $item['unserviceable'] ? self::CHECK : null);
        $sheet->setCellValue("R{$row}", $item['with_are'] ? self::CHECK : null);
        $sheet->setCellValue("S{$row}", $item['other']);

        if ($item['source'] instanceof PropertySource) {
            $sheet->setCellValue(self::SOURCE_COLUMNS[$item['source']->value].$row, self::CHECK);
        }

        if ($item['date'] !== null) {
            $sheet->setCellValue("E{$row}", Date::PHPToExcel($item['date']));
            $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode('m/d/yyyy');
        }
    }

    private function documentHeading(Worksheet $sheet, string $from, string $to, string $title, string $schoolYear): void
    {
        WorkbookStyle::banner($sheet, "{$from}1:{$to}1", 'REPORT ON THE PHYSICAL COUNT OF PROPERTY, PLANT AND EQUIPMENT', 13);
        WorkbookStyle::title($sheet, "{$from}2:{$to}2", $title, 12, center: true);
        WorkbookStyle::text($sheet, "{$from}3:{$to}3", '(Type of Property, Plant and Equipment)', italic: true);
        WorkbookStyle::title($sheet, "{$from}4:{$to}4", 'S.Y. '.$schoolYear, 11, center: true);
        WorkbookStyle::center($sheet, "{$from}3:{$to}3");
    }

    private function accountabilityLine(Worksheet $sheet, Office $office, ?Personnel $officer, ?string $assumedOn): void
    {
        $sheet->setCellValue('A6', 'For which');
        WorkbookStyle::signature($sheet, 'B6:C6', 'B7:C7', $officer === null ? null : $officer->printedName(), 'Name of Accountable Officer');
        WorkbookStyle::signature($sheet, 'D6:F6', 'D7:F7', $officer?->personnelPosition?->name, 'Official Designation');
        WorkbookStyle::signature($sheet, 'G6:I6', 'G7:I7', $office->office_name, 'Agency / Office');
        $sheet->setCellValue('J6', ', is accountable, having assumed such accountability on');
        $sheet->mergeCells('J6:O6');
        WorkbookStyle::signature($sheet, 'P6:S6', 'P7:S7', $assumedOn === null ? null : Carbon::parse($assumedOn)->format('F j, Y'), 'Date of Assumption');
    }

    /**
     * @param  list<?string>  $parts
     */
    private function joined(array $parts): ?string
    {
        $parts = array_filter($parts, fn (?string $part) => filled($part));

        return $parts === [] ? null : implode('; ', $parts);
    }
}
