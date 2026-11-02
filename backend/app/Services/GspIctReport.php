<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ConnectivityLibrary;
use App\Models\Equipment;
use App\Models\GspIctProfile;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspAccount;
use App\Models\IspProvider;
use App\Models\IspSpeedTest;
use App\Models\ItemType;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Government School Profile, Part VI (ICT in Schools), built from the
 * office's IRIS records. Counts cover functional equipment only.
 */
class GspIctReport
{
    public const LEVELS = ['elementary' => 'Elementary', 'junior_high' => 'Junior High School', 'senior_high' => 'Senior High School'];

    private const FUNDS = ['dcp' => 'DepEd DCP', 'deped_non_dcp' => 'DepEd Non-DCP', 'lgu_sef' => 'LGU/SEF', 'pta_private' => 'PTA/Private Donations', 'other_government' => 'Other Government Agencies', 'others' => 'Others'];

    private const FUND_COLUMNS = ['dcp' => 'B', 'deped_non_dcp' => 'C', 'lgu_sef' => 'D', 'pta_private' => 'E', 'other_government' => 'F', 'others' => 'G'];

    private const COMPUTER_TYPES = [
        'desktop' => ['Desktop/All-in-one', ItemType::BUNDLE_PARENT_NAMES],
        'laptop' => ['Laptop', ['Laptop']],
        'tablet' => ['Tablet', ['Tablet']],
        'tablet_pc' => ['Tablet PC', ['2-in-1 Tablet']],
    ];

    private const TABLE_2_ROWS = [
        'LED TV' => ['Standard TV'],
        'SMART TV' => ['Smart TV'],
        'Printer' => ['Printer', 'Multifunction Printer'],
        'Projector' => ['Projector'],
        'External Hard Drive' => ['External Hard Drive'],
        'Lapel' => ['Lapel'],
        'Charging Carts' => ['Charging Cart'],
    ];

    private const TABLE_3_ROWS = ['Laptop' => ['Laptop'], 'SMART TV' => ['Smart TV'], 'Charging Carts' => ['Charging Cart'], 'External Hard Drive' => ['External Hard Drive']];

    private const E_CART_PACKAGE = 'eLearning Cart Package';

    private const LISTED_ISPS = ['GLOBE', 'SKYCABLE', 'EASTERN COMMUNICATIONS', 'SMART', 'CONVERGE', 'DITO TELECOMMUNITY', 'PLDT', 'STARLINK'];

    /** Survey coverage-area value => label printed in Box 1. */
    private const COVERAGE_AREAS = [
        'School-wide' => 'School-wide', 'Elementary School' => 'Elementary School Area', 'ICT Room/Laboratory' => 'ICT Room/Laboratory',
        'Faculty area' => 'Faculty area', 'Junior High School Area' => 'Junior High School (JHS) Area', 'Library' => 'Library',
        'Principal office' => "Principal's office", 'Senior High School Area' => 'Senior High School (SHS) Area',
    ];

    private const CONNECTION_TYPES = [
        'Fiber' => 'Fiber Optic', 'DSL' => 'DSL', 'Cable' => 'Cable', 'Satellite' => 'Satellite',
        'Wireless broadband' => 'FWA', 'Mobile Data' => 'Mobile Data', 'Point-to-point' => 'P2P', 'Leased Line' => 'P2P',
    ];

    private const PURPOSES = [
        'For administrative use' => 'Administrative',
        'For classroom instruction use' => 'Classroom Instruction',
        'For both administrative and classroom instruction use' => 'Both',
    ];

    private const COVERAGE_LEVELS = ['ES' => 'Elementary', 'JHS' => 'Junior High School', 'SHS' => 'Senior High School'];

    private const DEPED_SOURCES = ['Central Office', 'Regional Office', 'School Division Office', 'School'];

    private const PTA_PRIVATE_SOURCES = ["Parent Teacher's Association", 'Private Corporation', 'Non-Governmental Organization (NGO)'];

    private const OTHER_GOVERNMENT_SOURCES = ['DICT', 'Other National Government Agency'];

    private const CHECKED = '☑';

    private const UNCHECKED = '☐';

    /** @var array<string, mixed> */
    private array $text = [];

    private string $period = '';

    /**
     * Equipment and connectivity counts are read live; the profile supplies what IRIS doesn't track.
     */
    public function generate(GspIctProfile $profile, Office $office, User $user): string
    {
        $this->text = require resource_path('reports/gsp-ict-text.php');
        $schoolYear = str_replace('-', '–', $profile->school_year);
        $this->period = "SY {$schoolYear}, as of ".$profile->as_of->format('F j, Y');
        $equipment = $this->functionalEquipment($office, $user);

        $spreadsheet = WorkbookStyle::workbook();
        $this->cover(WorkbookStyle::sheet($spreadsheet, 'Cover'), $office, $schoolYear, $profile);
        $this->table1(WorkbookStyle::sheet($spreadsheet, 'Table 1'), $equipment, $profile->level);
        $this->definitions(WorkbookStyle::sheet($spreadsheet, 'Table 1 Definitions'), 'Table 1. NUMBER OF FUNCTIONAL COMPUTERS IN THE SCHOOL BY FUNDING SOURCE & LEVEL', 'Definitions for Table 1', $this->text['table_1_definitions']);
        $this->tables2To4(WorkbookStyle::sheet($spreadsheet, 'Tables 2 to 4'), $equipment, (int) substr($profile->school_year, 0, 4), $profile);
        $this->box1(WorkbookStyle::sheet($spreadsheet, 'Box 1', landscape: true), $office, $user, $profile);
        $this->definitions(WorkbookStyle::sheet($spreadsheet, 'Box 1 Definitions'), 'Box 1. INTERNET CONNECTIVITY BY SERVICE PROVIDER(S)', 'Definitions, Notes, and Reminders for Box 1', $this->text['box_1_definitions']);

        return WorkbookStyle::save($spreadsheet);
    }

    public static function defaultLevel(Office $office): string
    {
        $type = strtolower((string) $office->office_type);

        return match (true) {
            str_contains($type, 'senior high') => 'senior_high',
            str_contains($type, 'high school') => 'junior_high',
            default => 'elementary',
        };
    }

    /**
     * Everything the workbook reads from IRIS records, for showing beside the profile.
     *
     * @return array{
     *     table_1: array{funds: list<array{key: string, label: string}>, types: list<array{key: string, label: string}>, counts: array<string, array<string, array<string, array<string, int>>>>},
     *     table_2: list<array{label: string, dcp: int, deped_non_dcp: int, others: int}>,
     *     table_3: array{years: list<int>, rows: list<array{label: string, counts: array<int, int>}>},
     *     box_1: array{available_isps: list<array{label: string, checked: bool}>, coverage: list<array{label: string, checked: bool}>, subscriptions: list<array<string, mixed>>, has_survey: bool}
     * }
     */
    public function irisData(Office $office, User $user, int $startYear, string $level): array
    {
        $equipment = $this->functionalEquipment($office, $user);
        $survey = InternetConnectivitySurvey::query()->where('office_id', $office->id)->first();
        $years = self::eCartYears($startYear);
        $columns = ['provider', 'connection_type', 'level', 'purpose', 'monthly_expense', 'funding_source', 'subscribed_download', 'subscribed_upload', 'ping', 'download', 'upload', 'service_quality'];

        return [
            'table_1' => [
                'funds' => array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label], array_keys(self::FUNDS), self::FUNDS),
                'types' => array_map(fn (string $key, array $type) => ['key' => $key, 'label' => $type[0]], array_keys(self::COMPUTER_TYPES), self::COMPUTER_TYPES),
                'counts' => $this->table1Counts($equipment, $level),
            ],
            'table_2' => $this->table2Rows($equipment),
            'table_3' => ['years' => $years, 'rows' => $this->table3Rows($equipment, $years)],
            'box_1' => [
                'available_isps' => self::checkList($this->availableIspOptions($survey)),
                'coverage' => self::checkList($this->coverageOptions($survey)),
                'subscriptions' => array_values($this->subscriptions($office, $user)
                    ->map(fn (IspAccount $account) => ['account_id' => $account->id, ...array_combine($columns, array_slice($this->ispRow($account, null), 0, 12))])
                    ->all()),
                'has_survey' => $survey !== null,
            ],
        ];
    }

    /**
     * @param  list<array{0: string, 1: bool}>  $options
     * @return list<array{label: string, checked: bool}>
     */
    private static function checkList(array $options): array
    {
        return array_map(fn (array $option) => ['label' => $option[0], 'checked' => $option[1]], $options);
    }

    /**
     * The four DCP batch years ending with the school year's start.
     *
     * @return list<int>
     */
    private static function eCartYears(int $startYear): array
    {
        return range($startYear - 3, $startYear);
    }

    /**
     * @param  Collection<int, Equipment>  $equipment
     * @return list<array{label: string, dcp: int, deped_non_dcp: int, others: int}>
     */
    private function table2Rows(Collection $equipment): array
    {
        $rows = [];

        foreach (self::TABLE_2_ROWS as $label => $itemTypes) {
            $counts = ['dcp' => 0, 'deped_non_dcp' => 0, 'others' => 0];

            foreach ($equipment as $item) {
                if (in_array($item->itemType?->name, $itemTypes, true)) {
                    $counts[$this->table2FundingSource($item)]++;
                }
            }

            $rows[] = ['label' => $label, 'dcp' => $counts['dcp'], 'deped_non_dcp' => $counts['deped_non_dcp'], 'others' => $counts['others']];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Equipment>  $equipment
     * @param  list<int>  $years
     * @return list<array{label: string, counts: array<int, int>}>
     */
    private function table3Rows(Collection $equipment, array $years): array
    {
        $eCart = $equipment->filter(fn (Equipment $item) => ! $item->non_dcp && $item->dcp_package === self::E_CART_PACKAGE);
        $rows = [];

        foreach (self::TABLE_3_ROWS as $label => $itemTypes) {
            $counts = [];

            foreach ($years as $year) {
                $counts[$year] = $eCart->filter(fn (Equipment $item) => $item->dcp_year === $year && in_array($item->itemType?->name, $itemTypes, true))->count();
            }

            $rows[] = ['label' => $label, 'counts' => $counts];
        }

        return $rows;
    }

    /**
     * @return list<array{0: string, 1: bool}>
     */
    private function availableIspOptions(?InternetConnectivitySurvey $survey): array
    {
        $available = $survey === null ? collect() : IspProvider::query()->whereKey($survey->available_isps ?? [])->pluck('name')->map(fn (string $name) => strtoupper($name));
        $hasLocal = $survey !== null && ($available->diff(self::LISTED_ISPS)->isNotEmpty() || filled($survey->available_isps_other));

        return [
            ...array_map(fn (string $name) => [$name, $available->contains($name)], self::LISTED_ISPS),
            ['LOCAL PROVIDER'.($survey !== null && filled($survey->available_isps_other) ? " ({$survey->available_isps_other})" : ''), $hasLocal],
        ];
    }

    /**
     * @return list<array{0: string, 1: bool}>
     */
    private function coverageOptions(?InternetConnectivitySurvey $survey): array
    {
        $areas = $survey === null ? collect() : ConnectivityLibrary::query()->whereKey($survey->coverage_areas ?? [])->pluck('value');
        $otherArea = $survey !== null && ($areas->diff(array_keys(self::COVERAGE_AREAS))->isNotEmpty() || filled($survey->coverage_areas_other));

        return [
            ...array_map(fn (string $value, string $label) => [$label, $areas->contains($value)], array_keys(self::COVERAGE_AREAS), self::COVERAGE_AREAS),
            ['Other area/s'.($survey !== null && filled($survey->coverage_areas_other) ? " ({$survey->coverage_areas_other})" : ''), $otherArea],
        ];
    }

    /**
     * @return Collection<int, IspAccount>
     */
    private function subscriptions(Office $office, User $user): Collection
    {
        return IspAccount::query()
            ->visibleTo($user)
            ->where('office_id', $office->id)
            ->where('inactive_contract', false)
            ->with(['ispProvider:id,name', 'speedTests' => fn ($query) => $query->latest('tested_at')->limit(1)])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Equipment>
     */
    private function functionalEquipment(Office $office, User $user): Collection
    {
        return Equipment::query()
            ->visibleTo($user)
            ->atOffice($office->id)
            ->functional()
            ->with('itemType:id,name')
            ->get(['id', 'item_type_id', 'non_dcp', 'dcp_package', 'dcp_year', 'source_acquisition', 'mode_acquisition', 'source_fund', 'used_by', 'school_level', 'parent_equipment_id']);
    }

    private function cover(Worksheet $sheet, Office $office, string $schoolYear, GspIctProfile $profile): void
    {
        WorkbookStyle::columns($sheet, ['A' => 12, 'B' => 70, 'C' => 4]);
        WorkbookStyle::banner($sheet, 'A1:C1', "GOVERNMENT SCHOOL PROFILE, SY {$schoolYear}", 16);
        WorkbookStyle::signature($sheet, 'A3:C3', 'A4:C4', $office->school_id ? "{$office->office_name} - {$office->school_id}" : $office->office_name, 'School Name - School ID');
        WorkbookStyle::title($sheet, 'A6:C6', 'PART VI. INFORMATION AND COMMUNICATION TECHNOLOGY IN SCHOOLS', 12, center: true);
        WorkbookStyle::title($sheet, 'A8:C8', 'TABLE OF CONTENTS', 12, center: true);

        $sheet->fromArray(['No.', 'Title'], null, 'A9');
        WorkbookStyle::header($sheet, 'A9:B9');
        $row = 10;
        foreach ($this->text['contents'] as [$number, $title]) {
            $sheet->setCellValue("A{$row}", $number);
            $sheet->setCellValue("B{$row}", str_replace(':period', $this->period, $title));
            $sheet->getStyle("B{$row}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(14 * (int) ceil(mb_strlen($sheet->getCell("B{$row}")->getValue()) / 62));
            $row++;
        }
        WorkbookStyle::borders($sheet, 'A10:B'.($row - 1));
        WorkbookStyle::center($sheet, 'A10:A'.($row - 1));

        $row += 2;
        foreach ([
            ['Certified True and Correct by School Head:', $profile->schoolHead, null],
            ['Validated by Information Technology Officer:', $profile->ito, 'Information Technology Officer'],
            ['Validated by Planning Officer III (PRS-SGOD):', $profile->planningOfficer, 'Planning Officer III'],
        ] as [$label, $person, $fallbackPosition]) {
            $sheet->setCellValue("B{$row}", $label);
            WorkbookStyle::signature($sheet, 'B'.($row + 2), 'B'.($row + 3), $person?->printedName(), 'Signature over Printed Name / Date');
            $sheet->setCellValue('B'.($row + 4), 'Position Title: '.($person?->personnelPosition->name ?? $fallbackPosition ?? ''));
            $row += 7;
        }
    }

    /**
     * @param  Collection<int, Equipment>  $equipment
     */
    private function table1(Worksheet $sheet, Collection $equipment, string $level): void
    {
        $counts = $this->table1Counts($equipment, $level);

        WorkbookStyle::columns($sheet, ['A' => 30, 'B' => 12, 'C' => 12, 'D' => 12, 'E' => 14, 'F' => 14, 'G' => 11, 'H' => 11]);
        WorkbookStyle::title($sheet, 'A1:H1', 'Table 1. NUMBER OF FUNCTIONAL COMPUTERS IN THE SCHOOL BY FUNDING SOURCE & LEVEL');
        WorkbookStyle::text($sheet, 'A2:H2', $this->period);
        WorkbookStyle::text($sheet, 'A3:H3', 'Provide appropriate answers.', italic: true);

        $row = 5;
        foreach ([
            'learner' => ['Number of Functional Computers Used by Learners', array_keys(self::LEVELS)],
            'teacher' => ['Number of Functional Computers Used by Teachers', array_keys(self::LEVELS)],
            'personnel' => ['Number of Functional Computers Used by Teaching-Related and Non-Teaching Personnel', [null]],
        ] as $group => [$heading, $levels]) {
            $sheet->setCellValue("A{$row}", 'TYPES OF COMPUTER');
            $sheet->setCellValue("B{$row}", 'FUNDING SOURCE');
            $sheet->mergeCells("B{$row}:H{$row}");
            $sheet->mergeCells("A{$row}:A".($row + 1));
            $sheet->fromArray([...array_values(self::FUNDS), 'TOTAL'], null, 'B'.($row + 1));
            WorkbookStyle::header($sheet, "A{$row}:H".($row + 1));
            $sheet->getRowDimension($row + 1)->setRowHeight(42);
            $row += 2;

            WorkbookStyle::title($sheet, "A{$row}:H{$row}", $heading, 10, center: true);
            WorkbookStyle::fill($sheet, "A{$row}:H{$row}", WorkbookStyle::HEADER_FILL);
            WorkbookStyle::borders($sheet, "A{$row}:H{$row}");
            $row++;

            foreach ($levels as $levelKey) {
                if ($levelKey !== null) {
                    WorkbookStyle::title($sheet, "A{$row}:H{$row}", self::LEVELS[$levelKey], 10);
                    $sheet->getStyle("A{$row}")->getFont()->setItalic(true);
                    WorkbookStyle::borders($sheet, "A{$row}:H{$row}");
                    $row++;
                }

                $first = $row;
                foreach (self::COMPUTER_TYPES as $type => [$label]) {
                    $sheet->setCellValue("A{$row}", '   '.$label);
                    foreach (self::FUND_COLUMNS as $fund => $column) {
                        $sheet->setCellValue("{$column}{$row}", $counts[$group][$levelKey ?? 'all'][$type][$fund] ?? 0);
                    }
                    $sheet->setCellValue("H{$row}", "=SUM(B{$row}:G{$row})");
                    $row++;
                }

                $sheet->setCellValue("A{$row}", 'TOTAL');
                foreach ([...array_values(self::FUND_COLUMNS), 'H'] as $column) {
                    $sheet->setCellValue("{$column}{$row}", "=SUM({$column}{$first}:{$column}".($row - 1).')');
                }
                WorkbookStyle::bold($sheet, "A{$row}:H{$row}");
                WorkbookStyle::borders($sheet, "A{$first}:H{$row}");
                WorkbookStyle::center($sheet, "B{$first}:H{$row}");
                $row++;
            }

            $row++;
        }

        WorkbookStyle::paragraphs($sheet, $row, 'A', 'H', $this->text['table_1_note'], 105);
        $sheet->getPageSetup()->setPrintArea('A1:H'.($row + 2));
    }

    /**
     * @param  Collection<int, Equipment>  $equipment
     * @return array<string, array<string, array<string, array<string, int>>>>
     */
    private function table1Counts(Collection $equipment, string $level): array
    {
        $counts = [];

        foreach ($equipment as $item) {
            $type = $this->computerType($item);
            [$group, $levelKey] = match ($item->used_by) {
                'Learner' => ['learner', $item->school_level ?? $level],
                'Teacher' => ['teacher', $item->school_level ?? $level],
                'Teaching-Related', 'Non-Teaching' => ['personnel', 'all'],
                default => [null, null],
            };

            if ($type === null || $group === null) {
                continue;
            }

            $fund = $this->table1FundingSource($item);
            $counts[$group][$levelKey][$type][$fund] = ($counts[$group][$levelKey][$type][$fund] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, Equipment>  $equipment
     */
    private function tables2To4(Worksheet $sheet, Collection $equipment, int $startYear, GspIctProfile $profile): void
    {
        WorkbookStyle::columns($sheet, ['A' => 30, 'B' => 14, 'C' => 14, 'D' => 14, 'E' => 14, 'F' => 14]);

        WorkbookStyle::title($sheet, 'A1:F1', 'Table 2. NUMBER OF FUNCTIONAL ICT EQUIPMENT IN THE SCHOOL BY FUNDING SOURCE');
        WorkbookStyle::text($sheet, 'A2:F2', $this->period);
        $sheet->fromArray(['TYPES OF ICT EQUIPMENT', 'DepEd DCP', 'DepEd Non-DCP', 'Others', 'TOTAL'], null, 'A4');
        WorkbookStyle::header($sheet, 'A4:E4');
        $row = 5;
        foreach ($this->table2Rows($equipment) as $line) {
            $sheet->fromArray([$line['label'], $line['dcp'], $line['deped_non_dcp'], $line['others'], "=SUM(B{$row}:D{$row})"], null, "A{$row}", true);
            $row++;
        }
        WorkbookStyle::borders($sheet, 'A5:E'.($row - 1));
        WorkbookStyle::center($sheet, 'B5:E'.($row - 1));
        $row = WorkbookStyle::paragraphs($sheet, $row + 1, 'A', 'F', $this->text['table_2_definitions'], 100) + 1;

        WorkbookStyle::title($sheet, "A{$row}:F{$row}", 'Table 3. NUMBER OF FUNCTIONAL E-CART COMPONENTS IN THE SCHOOL UNDER DEPED COMPUTERIZATION PROGRAM (DCP)');
        $sheet->getRowDimension($row)->setRowHeight(28);
        WorkbookStyle::text($sheet, 'A'.($row + 1).':F'.($row + 1), $this->period);
        $row += 3;
        $years = self::eCartYears($startYear);
        $sheet->fromArray(['TYPES OF E-CART COMPONENT', ...array_map(fn (int $year) => "{$year}-01", $years)], null, "A{$row}");
        WorkbookStyle::header($sheet, "A{$row}:E{$row}");
        $first = $row + 1;
        foreach ($this->table3Rows($equipment, $years) as $line) {
            $row++;
            $sheet->fromArray([$line['label'], ...array_values($line['counts'])], null, "A{$row}", true);
        }
        WorkbookStyle::borders($sheet, "A{$first}:E{$row}");
        WorkbookStyle::center($sheet, "B{$first}:E{$row}");
        $row = WorkbookStyle::paragraphs($sheet, $row + 2, 'A', 'F', $this->text['table_3_definitions'], 100) + 1;

        WorkbookStyle::title($sheet, "A{$row}:F{$row}", 'Table 4. NUMBER OF TEACHERS USING RADIO FOR INSTRUCTIONAL USE');
        WorkbookStyle::text($sheet, 'A'.($row + 1).':F'.($row + 1), $this->period);
        $row += 3;
        $sheet->fromArray(['GRADE LEVEL', 'Literacy', 'Numeracy', 'Subtotal'], null, "A{$row}");
        WorkbookStyle::header($sheet, "A{$row}:D{$row}");
        $first = $row + 1;
        foreach (range(1, 6) as $grade) {
            $row++;
            $counts = $profile->radio_teachers["grade_{$grade}"] ?? [];
            $sheet->fromArray(["Grade {$grade}", (int) ($counts['literacy'] ?? 0), (int) ($counts['numeracy'] ?? 0), "=SUM(B{$row}:C{$row})"], null, "A{$row}", true);
        }
        $row++;
        $sheet->fromArray(['TOTAL', '=SUM(B'.$first.':B'.($row - 1).')', '=SUM(C'.$first.':C'.($row - 1).')', '=SUM(D'.$first.':D'.($row - 1).')'], null, "A{$row}");
        WorkbookStyle::bold($sheet, "A{$row}:D{$row}");
        WorkbookStyle::borders($sheet, "A{$first}:D{$row}");
        WorkbookStyle::center($sheet, "B{$first}:D{$row}");
        WorkbookStyle::paragraphs($sheet, $row + 2, 'A', 'F', $this->text['table_4_definitions'], 100);
    }

    private function box1(Worksheet $sheet, Office $office, User $user, GspIctProfile $profile): void
    {
        $survey = InternetConnectivitySurvey::query()->where('office_id', $office->id)->first();
        WorkbookStyle::columns($sheet, ['A' => 20, 'B' => 14, 'C' => 15, 'D' => 15, 'E' => 12, 'F' => 15, 'G' => 11, 'H' => 11, 'I' => 9, 'J' => 11, 'K' => 11, 'L' => 12, 'M' => 13]);

        WorkbookStyle::title($sheet, 'A1:M1', 'Box 1. INTERNET CONNECTIVITY BY SERVICE PROVIDER(S)');
        WorkbookStyle::text($sheet, 'A2:M2', $this->period);

        $sheet->setCellValue('A4', '1. What are the Internet Service Providers (ISPs) available in the area?');
        WorkbookStyle::bold($sheet, 'A4');
        $this->checkboxGrid($sheet, 5, $this->availableIspOptions($survey));

        $sheet->setCellValue('A9', '2. What internet service provider(s) (ISP/s) is/are the school currently subscribed to?');
        WorkbookStyle::bold($sheet, 'A9');
        $sheet->fromArray(['Internet Service Provider', 'Types of Internet Connection', 'Level of Education', 'Purpose', 'Average Monthly Expense', 'Funding Source', 'Subscriber Speed (Mbps)', null, 'Speed Test (Mbps)', null, null, 'Service Quality', 'Service Reliability'], null, 'A10');
        $sheet->fromArray(['Download', 'Upload', 'Ping', 'Download', 'Upload'], null, 'G11');
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'L', 'M'] as $column) {
            $sheet->mergeCells("{$column}10:{$column}11");
        }
        $sheet->mergeCells('G10:H10');
        $sheet->mergeCells('I10:K10');
        WorkbookStyle::header($sheet, 'A10:M11');
        $sheet->getRowDimension(10)->setRowHeight(30);

        $row = 12;
        foreach ($this->subscriptions($office, $user) as $account) {
            $sheet->fromArray($this->ispRow($account, $profile->isp_reliability[$account->id] ?? null), null, "A{$row}", true);
            $row++;
        }
        $last = max($row, 15) - 1;
        WorkbookStyle::borders($sheet, "A12:M{$last}");
        WorkbookStyle::center($sheet, "A12:M{$last}");
        WorkbookStyle::money($sheet, "E12:E{$last}");

        $row = $last + 2;
        $sheet->setCellValue("A{$row}", 'Instructions: The following columns take one of these options.');
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true);
        foreach ($this->text['box_1_instructions'] as [$label, $options]) {
            $row++;
            $sheet->setCellValue("B{$row}", $label);
            $sheet->setCellValue("D{$row}", $options);
            WorkbookStyle::bold($sheet, "B{$row}");
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", '3. What is the coverage area of your internet subscription? (Check all applicable.)');
        WorkbookStyle::bold($sheet, "A{$row}");
        $row = $this->checkboxGrid($sheet, $row + 1, $this->coverageOptions($survey));
        $sheet->setCellValue("B{$row}", 'Reminder: If School-wide is selected, no other options should be selected.');
        $sheet->getStyle("B{$row}")->getFont()->setItalic(true)->setSize(9);

        $row += 2;
        $sheet->setCellValue("A{$row}", '4. Are you a recipient of DICT Free WiFi, and is the internet connection operational?');
        WorkbookStyle::bold($sheet, "A{$row}");
        $sheet->setCellValue('B'.($row + 1), ($profile->dict_free_wifi === 'with_internet' ? self::CHECKED : self::UNCHECKED).'  Yes, with internet connection');
        $sheet->setCellValue('B'.($row + 2), ($profile->dict_free_wifi === 'without_internet' ? self::CHECKED : self::UNCHECKED).'  Yes, but without internet connection');
        $sheet->setCellValue('B'.($row + 3), 'If yes, please indicate the Date of Installation/Activation (Format: Month/Year, e.g., October 2023): '.($profile->dict_free_wifi_installed ?: '____________________'));
        $sheet->getPageSetup()->setPrintArea('A1:M'.($row + 3));
    }

    /**
     * Three options per row, as Box 1 lays them out.
     *
     * @param  list<array{0: string, 1: bool}>  $options
     */
    private function checkboxGrid(Worksheet $sheet, int $row, array $options): int
    {
        foreach (array_chunk($options, 3) as $chunk) {
            foreach ($chunk as $index => [$label, $checked]) {
                $sheet->setCellValue([2 + $index * 4, $row], ($checked ? self::CHECKED : self::UNCHECKED).'  '.$label);
            }
            $row++;
        }

        return $row;
    }

    /**
     * @return list<string|float|int|null>
     */
    private function ispRow(IspAccount $account, ?string $reliability): array
    {
        /** @var IspSpeedTest|null $test */
        $test = $account->speedTests->first();
        $subscribedDownload = $account->subscriber_speed_download ?? $account->max_speed;

        return [
            $account->ispProvider?->name,
            self::CONNECTION_TYPES[$account->isp_connection_type] ?? null,
            $this->coverageLevel($account->school_area_coverage),
            self::PURPOSES[$account->purpose_of_subscription] ?? null,
            (float) $account->cost_per_month,
            $this->internetFundingSource($account),
            $subscribedDownload === null ? null : (float) $subscribedDownload,
            $account->subscriber_speed_upload === null ? null : (float) $account->subscriber_speed_upload,
            $test?->ping,
            $test === null ? null : (float) $test->download_speed,
            $test === null ? null : (float) $test->upload_speed,
            $test === null || $subscribedDownload === null ? null : $this->serviceQuality((float) $test->download_speed, (float) $subscribedDownload, $test->ping),
            $reliability,
        ];
    }

    /**
     * @param  list<string|array{0: string, 1: int}>  $lines
     */
    private function definitions(Worksheet $sheet, string $title, string $heading, array $lines): void
    {
        WorkbookStyle::columns($sheet, ['A' => 14, 'B' => 14, 'C' => 14, 'D' => 14, 'E' => 14, 'F' => 14, 'G' => 14]);
        WorkbookStyle::title($sheet, 'A1:G1', $title);
        WorkbookStyle::text($sheet, 'A2:G2', $this->period);
        WorkbookStyle::title($sheet, 'A4:G4', $heading, 11);
        WorkbookStyle::paragraphs($sheet, 6, 'A', 'G', $lines, 105);
    }

    /**
     * Table 1 funding source: DepEd DCP, DepEd Non-DCP, LGU/SEF, PTA/Private Donations, Other Government Agencies, Others.
     */
    private function table1FundingSource(Equipment $item): string
    {
        return match (true) {
            ! $item->non_dcp => 'dcp',
            $item->source_acquisition === 'Local Government Unit (LGU)', $item->source_fund === 'Special Education Fund (SEF)' => 'lgu_sef',
            in_array($item->source_acquisition, self::PTA_PRIVATE_SOURCES, true) => 'pta_private',
            in_array($item->source_acquisition, self::OTHER_GOVERNMENT_SOURCES, true) => 'other_government',
            $this->isDepEdFunded($item->source_acquisition, $item->mode_acquisition) => 'deped_non_dcp',
            default => 'others',
        };
    }

    /**
     * Table 2 funding source: DepEd DCP, DepEd Non-DCP, Others.
     */
    private function table2FundingSource(Equipment $item): string
    {
        return match ($this->table1FundingSource($item)) {
            'dcp' => 'dcp',
            'deped_non_dcp' => 'deped_non_dcp',
            default => 'others',
        };
    }

    /**
     * One of the Box 1 funding-source options.
     */
    private function internetFundingSource(IspAccount $account): string
    {
        return match (true) {
            $account->fund_source === 'Maintenance and Other Operating Expenses (MOOE)' => 'MOOE',
            $account->fund_source === 'Special Education Fund (SEF)', $account->source_of_acquisition === 'Local Government Unit (LGU)' => 'LGU/SEF',
            $account->source_of_acquisition === "Parent Teacher's Association" => 'PTA',
            in_array($account->source_of_acquisition, ['Private Corporation', 'Non-Governmental Organization (NGO)'], true) => 'Private Donations',
            in_array($account->source_of_acquisition, self::OTHER_GOVERNMENT_SOURCES, true) => 'Other Government Agencies',
            $account->source_of_acquisition === 'School' => 'School Funds',
            $this->isDepEdFunded($account->source_of_acquisition, $account->mode_of_acquisition) => 'DepEd DICP',
            default => 'Others',
        };
    }

    private function isDepEdFunded(?string $source, ?string $mode): bool
    {
        return in_array($source, self::DEPED_SOURCES, true) || ($source === null && $mode === 'DepEd Purchase');
    }

    private function computerType(Equipment $item): ?string
    {
        if ($item->parent_equipment_id !== null) {
            return null;
        }

        foreach (self::COMPUTER_TYPES as $type => [, $names]) {
            if (in_array($item->itemType?->name, $names, true)) {
                return $type;
            }
        }

        return null;
    }

    private function coverageLevel(?string $coverage): ?string
    {
        return match (true) {
            $coverage === null, $coverage === 'Not Applicable' => null,
            isset(self::COVERAGE_LEVELS[$coverage]) => self::COVERAGE_LEVELS[$coverage],
            default => 'Shared',
        };
    }

    /**
     * Box 1 definition 9: the lower of the speed rating (actual vs subscribed download) and the ping rating.
     */
    private function serviceQuality(float $download, float $subscribed, ?int $ping): ?string
    {
        if ($subscribed <= 0) {
            return null;
        }

        $ratio = $download / $subscribed;
        $speed = match (true) {
            $ratio >= 0.9 => 3,
            $ratio >= 0.6 => 2,
            $ratio >= 0.3 => 1,
            default => 0,
        };
        $latency = match (true) {
            $ping === null, $ping <= 20 => 3,
            $ping <= 50 => 2,
            $ping <= 100 => 1,
            default => 0,
        };

        return ['Poor', 'Fair', 'Good', 'Excellent'][min($speed, $latency)];
    }
}
