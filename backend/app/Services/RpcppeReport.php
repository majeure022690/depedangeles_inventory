<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PropertyCategory;
use App\Enums\PropertySource;
use App\Models\Equipment;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\PropertyItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Report on the Physical Count of Property, Plant and Equipment for one
 * office: IRIS equipment (one row per property number) plus School Property
 * items, one sheet per category, items under Equipment::PPE_THRESHOLD on a
 * separate semi-expendable sheet where the category has one. Ends with the
 * books inventory and the furniture-per-teacher attachment.
 *
 * @phpstan-type CountRow array{sheet: string, article: ?string, description: ?string, property_no: ?string, unit: ?string, date: ?\DateTimeInterface, unit_value: ?float, quantity: int, on_hand: int, source: ?PropertySource, functional: bool, unserviceable: bool, with_are: bool, other: ?string}
 */
class RpcppeReport
{
    /** IRIS item type => [category, article]. Unlisted types are IT equipment under their own name. */
    private const ARTICLES = [
        'Copier / Xerox Machine' => [PropertyCategory::OfficeEquipment, 'Photocopier'],
        'Fax Machine' => [PropertyCategory::OfficeEquipment, 'Fax Machine'],
        'Microphone' => [PropertyCategory::OfficeEquipment, 'Microphone'],
        'Digital Camera' => [PropertyCategory::OfficeEquipment, 'Camera'],
        'Voice Recorder' => [PropertyCategory::OfficeEquipment, 'Voice Recorder'],
        'PABX' => [PropertyCategory::FurnitureFixtures, 'PABX Phone System'],
        'Computer Table' => [PropertyCategory::FurnitureFixtures, 'Computer Table'],
        'Desktop (package)' => [PropertyCategory::ItEquipment, 'Desktop Computer'],
        'CPU' => [PropertyCategory::ItEquipment, 'Desktop Computer'],
        'Laptop' => [PropertyCategory::ItEquipment, 'Laptop Computer'],
        'Multifunction Printer' => [PropertyCategory::ItEquipment, 'Printer'],
        'Interactive Whiteboards / Smartboards' => [PropertyCategory::ItEquipment, 'Interactive Whiteboard'],
        'Speakers' => [PropertyCategory::ItEquipment, 'Multimedia Speaker'],
        'Modem / Router' => [PropertyCategory::ItEquipment, 'Network Hardware (Router, Switch etc.)'],
        'Access Point' => [PropertyCategory::ItEquipment, 'Network Hardware (Router, Switch etc.)'],
        'Network Switch' => [PropertyCategory::ItEquipment, 'Network Hardware (Router, Switch etc.)'],
        'Firewall Appliance' => [PropertyCategory::ItEquipment, 'Network Hardware (Router, Switch etc.)'],
        'Others' => [PropertyCategory::ItEquipment, 'Other'],
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

    /** No longer in the office's custody, so not counted. */
    private const RELINQUISHED = ['Disposed', 'Donated', 'Transferred'];

    private const MISSING = ['Lost', 'Stolen'];

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
        $properties = PropertyItem::query()
            ->visibleTo($user)
            ->where('office_id', $office->id)
            ->when($officer !== null, fn ($query) => $query->where('accountable_officer_id', $officer?->id))
            ->with('endUser:id,first_name,middle_name,last_name')
            ->get();

        $spreadsheet = WorkbookStyle::workbook();

        foreach (PropertyCategory::cases() as $category) {
            if ($category === PropertyCategory::Books) {
                continue;
            }

            foreach ($category->splitsSemiExpendable() ? [false, true] : [null] as $semi) {
                $title = $this->sheetTitle($category, $semi);
                $sheet = WorkbookStyle::sheet($spreadsheet, $this->sheetName($category, $semi), landscape: true);
                $this->physicalCountSheet($sheet, $title, $office, $officer, $input, $bySheet[$title] ?? []);
            }
        }

        $this->booksSheet(WorkbookStyle::sheet($spreadsheet, 'Books', landscape: true), $office, $input, $properties->where('category', PropertyCategory::Books)->values());
        $this->teacherAttachment(WorkbookStyle::sheet($spreadsheet, 'F&F per Teacher', landscape: true), $office, $input, $properties->where('category', PropertyCategory::FurnitureFixtures)->values());

        return WorkbookStyle::save($spreadsheet);
    }

    private function sheetTitle(PropertyCategory $category, ?bool $semi): string
    {
        return mb_strtoupper($category->label()).($semi === true ? ' (SEMI-EXPENDABLE)' : '');
    }

    private function sheetName(PropertyCategory $category, ?bool $semi): string
    {
        $short = match ($category) {
            PropertyCategory::ItEquipment => 'IT',
            PropertyCategory::OfficeEquipment => 'Office Eqpt',
            PropertyCategory::FurnitureFixtures => 'F&F',
            PropertyCategory::SchoolBuilding => 'School Bldg',
            PropertyCategory::OtherStructure => 'Other Structures',
            PropertyCategory::TleEquipment => 'TLE',
            PropertyCategory::ScienceMathEquipment => 'Sci & Math',
            PropertyCategory::SportsMusicEquipment => 'Sports-Music',
            PropertyCategory::Books => 'Books',
        };

        return $short.($semi === true ? ' Semi-Exp' : '');
    }

    /**
     * @return list<CountRow>
     */
    private function equipmentRows(Office $office, User $user, ?Personnel $officer): array
    {
        return array_values(Equipment::query()
            ->visibleTo($user)
            ->when($officer !== null, fn ($query) => $query->where('current_accountable_officer_id', $officer?->id), fn ($query) => $query->atOffice($office->id))
            ->whereNotIn('disposition_status', self::RELINQUISHED)
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
            ->where('category', '!=', PropertyCategory::Books->value)
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
        $category = self::ARTICLES[(string) $item->itemType?->name][0] ?? PropertyCategory::ItEquipment;
        $cost = $item->acquisition_cost === null ? null : (float) $item->acquisition_cost;
        $condition = $item->equipmentCondition?->name;
        $endUser = $item->currentEndUser;

        return [
            'sheet' => $this->sheetTitle($category, $category->splitsSemiExpendable() ? ($cost === null || $cost < Equipment::PPE_THRESHOLD) : null),
            'article' => self::ARTICLES[(string) $item->itemType?->name][1] ?? $item->itemType?->name,
            'description' => $this->description($item),
            'property_no' => $item->property_no,
            'unit' => $item->uom,
            'date' => $item->received_date,
            'unit_value' => $cost,
            'quantity' => 1,
            'on_hand' => in_array($item->disposition_status, self::MISSING, true) ? 0 : 1,
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
            'sheet' => $this->sheetTitle($item->category, $item->category->splitsSemiExpendable() ? $item->isSemiExpendable() : null),
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

    /**
     * @param  array<string, mixed>  $input
     * @param  Collection<int, PropertyItem>  $books
     */
    private function booksSheet(Worksheet $sheet, Office $office, array $input, Collection $books): void
    {
        WorkbookStyle::columns($sheet, ['A' => 24, 'B' => 14, 'C' => 44, 'D' => 18, 'E' => 14, 'F' => 14, 'G' => 12, 'H' => 32]);
        $this->documentHeading($sheet, 'A', 'H', 'BOOKS AND LEARNING RESOURCES', $input['school_year']);
        $sheet->setCellValue('A6', 'School:');
        WorkbookStyle::signature($sheet, 'B6:D6', 'B7:D7', $office->office_name, 'Name of School');

        $sheet->fromArray(['LEARNING AREA', 'GRADE LEVEL', 'TITLE / MATERIAL', 'PROPERTY NUMBER', 'RECEIPT (JUNE)', 'ACTUAL (MARCH)', 'VARIANCE', 'REMARKS'], null, 'A9');
        WorkbookStyle::header($sheet, 'A9:H9');

        $row = 10;
        foreach ($books->sortBy([['article', 'asc'], ['grade_level', 'asc']]) as $book) {
            $sheet->fromArray([$book->article, $book->grade_level, $book->description, $book->property_no, $book->quantity, $book->on_hand_count, "=F{$row}-E{$row}", $this->joined([$book->location, $book->remarks])], null, "A{$row}", true);
            $row++;
        }

        if ($books->isEmpty()) {
            $sheet->setCellValue("C{$row}", 'No books recorded in IRIS.');
            $sheet->getStyle("C{$row}")->getFont()->setItalic(true);
            $row++;
        }

        $sheet->fromArray(['TOTAL', null, null, null, '=SUM(E10:E'.($row - 1).')', '=SUM(F10:F'.($row - 1).')', '=SUM(G10:G'.($row - 1).')'], null, "A{$row}");
        WorkbookStyle::bold($sheet, "A{$row}:H{$row}");
        WorkbookStyle::borders($sheet, "A10:H{$row}");
        WorkbookStyle::center($sheet, "B10:B{$row}");
        WorkbookStyle::center($sheet, "E10:G{$row}");

        $row += 3;
        $sheet->setCellValue("A{$row}", 'Prepared by:');
        $sheet->setCellValue("E{$row}", 'Noted:');
        WorkbookStyle::signature($sheet, 'A'.($row + 2).':C'.($row + 2), 'A'.($row + 3).':C'.($row + 3), $input['prepared_by']?->printedName(), 'School Property Custodian');
        WorkbookStyle::signature($sheet, 'E'.($row + 2).':H'.($row + 2), 'E'.($row + 3).':H'.($row + 3), $input['school_head']?->printedName(), 'School Head');
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(9, 9);
    }

    /**
     * Furniture and fixtures per teacher, grouped by grade level, one column per article.
     *
     * @param  array<string, mixed>  $input
     * @param  Collection<int, PropertyItem>  $furniture
     */
    private function teacherAttachment(Worksheet $sheet, Office $office, array $input, Collection $furniture): void
    {
        $assigned = $furniture->filter(fn (PropertyItem $item) => $item->end_user_id !== null);
        $articles = $assigned->pluck('article')->unique()->sort()->values()->all();
        $lastColumn = Coordinate::stringFromColumnIndex(count($articles) + 2);
        $signatureColumn = $lastColumn;

        WorkbookStyle::columns($sheet, ['A' => 34]);
        foreach ($articles as $index => $article) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 2))->setWidth(12);
        }
        $sheet->getColumnDimension($signatureColumn)->setWidth(20);

        $this->documentHeading($sheet, 'A', $lastColumn, 'FURNITURE AND FIXTURES PER TEACHER', $input['school_year']);
        $sheet->setCellValue('A6', 'Name of School: '.$office->office_name);
        WorkbookStyle::bold($sheet, 'A6');

        $sheet->fromArray(['NAME OF TEACHER', ...$articles, 'SIGNATURE'], null, 'A8');
        WorkbookStyle::header($sheet, "A8:{$lastColumn}8");
        $sheet->getRowDimension(8)->setRowHeight(42);

        $row = 9;
        $groups = $assigned->groupBy(fn (PropertyItem $item) => $item->grade_level ?? 'Subject Teachers / Others')
            ->sortBy(fn (Collection $items, string $grade) => array_search($grade, PropertyItem::GRADE_LEVELS, true) === false ? 99 : array_search($grade, PropertyItem::GRADE_LEVELS, true));

        foreach ($groups as $grade => $items) {
            $sheet->setCellValue("A{$row}", $grade);
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            WorkbookStyle::bold($sheet, "A{$row}");
            WorkbookStyle::fill($sheet, "A{$row}:{$lastColumn}{$row}", WorkbookStyle::HEADER_FILL);
            $row++;

            foreach ($items->groupBy('end_user_id')->sortBy(fn (Collection $own) => $own->first()?->endUser?->last_name) as $own) {
                $teacher = $own->first()?->endUser;
                $sheet->setCellValue("A{$row}", $teacher === null ? null : trim("{$teacher->last_name}, {$teacher->first_name} ".($teacher->middle_name ? mb_substr($teacher->middle_name, 0, 1).'.' : '')));
                foreach ($articles as $index => $article) {
                    $sheet->setCellValue([$index + 2, $row], $own->where('article', $article)->sum('quantity'));
                }
                $row++;
            }
        }

        if ($assigned->isEmpty()) {
            $sheet->setCellValue("A{$row}", 'No furniture assigned to a teacher in IRIS. Set the End user / Teacher on Furniture and Fixtures items in School Property.');
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true);
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $row++;
        }

        WorkbookStyle::borders($sheet, "A9:{$lastColumn}".($row - 1));
        WorkbookStyle::center($sheet, "B9:{$lastColumn}".($row - 1));
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(8, 8);
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

    private function description(Equipment $item): string
    {
        $brand = $item->brand?->name;
        $model = trim((string) $item->model);
        $brand = in_array($brand, [null, 'Others'], true) || str_starts_with(strtolower($model), strtolower((string) $brand)) ? null : $brand;
        $name = trim(implode(' ', array_filter([$brand, $model])));
        $name = $name !== '' ? $name : (string) ($item->specifications ?: $item->itemType?->name);
        $serial = trim((string) $item->serial_number);

        return $serial !== '' && ! in_array(strtoupper($serial), ['N/A', 'NA', 'NONE', '-'], true) ? "{$name} (S/N: {$serial})" : $name;
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
