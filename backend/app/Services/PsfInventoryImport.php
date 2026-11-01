<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EquipmentLibraryType;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\EquipmentLibrary;
use App\Models\ItemType;
use App\Models\Office;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Equipment from the schools' PSF inventory workbooks (the IRIS template's
 * Equipment sheet). A school is only ever a PRIME-linked office, and every
 * person is looked up within that office, so no row can land in another
 * school's scope.
 *
 * @phpstan-type SchoolPlan array{file: string, school_id: string, office: ?Office, name_match: int, problem: ?string, rows: list<array<string, mixed>>, already_imported: bool}
 */
final class PsfInventoryImport
{
    public const string EVENT = 'equipment.psf_imported';

    private const int FIRST_ROW = 6;

    private const int BLANK_ROWS_TO_STOP = 50;

    private const int MIN_NAME_MATCH = 60;

    private const int SHARED_REFERENCE_ROWS = 5;

    private const int MAX_TEXT = 255;

    private const string REFERENCE_PATTERN = '/\b(iar|lot|batch|set|so|swpp?s|po|dr|si|dcp)\s*[#:\-]?\s*\d|\biar[-\s]/i';

    private const string NO_NUMBER_PATTERN = '/^(unknown|unidentified|not\s+indicated|no\s+property|none|tbd|to\s+follow)\b/i';

    private const array PLACEHOLDERS = ['', 'N/A', 'NA', 'NONE', 'NULL', '-', '--', 'NO AVAILABLE INFO', 'NOT AVAILABLE', 'NO DATA'];

    private const array ALIASES = [
        'source_fund' => [
            'program support funds (psf)' => 'Program Support Fund (PSF)',
            'school education fund (sef)' => 'Special Education Fund (SEF)',
            'mooe' => 'Maintenance and Other Operating Expenses (MOOE)',
        ],
        'dcp_package' => ['shs ict package' => 'JHS/SHS ICT Package'],
        'allotment_class' => ['mooe' => 'Maintenance and Other Operating Expenses (MOOE)'],
    ];

    public const array DEFAULTS = [
        'mode_acquisition' => 'DepEd Purchase',
        'source_acquisition' => 'Central Office',
        'source_fund' => 'Not Applicable',
        'equipment_classification' => 'Office, ICT Equipment, Furniture And Fixtures',
        'equipment_condition' => 'Not Applicable',
        'disposition_status' => 'Normal',
        'transaction_type' => 'Beginning Inventory',
    ];

    private const array SCHOOL_WORDS = [
        'elementary', 'elem', 'integrated', 'national', 'high', 'memorial', 'school',
        'es', 'is', 'nhs', 'hs', 'mhs', 'shs', 'sr', 'jr', 'of',
    ];

    /** @var array<string, array<string, int|string>> */
    private array $lookups = [];

    /** @var array<string, string> */
    private array $heads = [];

    public function __construct(private readonly PrimeDirectory $prime) {}

    /**
     * The newest workbook per resolved school, with every row mapped but nothing written.
     *
     * @param  array<string, string>  $assign  file name => school ID, for files whose own ID is wrong
     * @param  array<string, string>  $heads  school ID => employee no. of who receives officer-less rows there
     * @return list<SchoolPlan>
     */
    public function plan(string $folder, array $assign = [], array $heads = []): array
    {
        $this->heads = $heads;
        $this->loadLookups();
        $offices = $this->primeSchoolOffices();

        $byId = [];
        foreach ($this->workbooks($folder) as $path) {
            $schoolId = $assign[basename($path)] ?? $this->schoolIdOf($path, $folder);
            if ($schoolId !== null) {
                $byId[$schoolId][] = $path;
            }
        }
        ksort($byId);

        $plans = [];
        foreach ($byId as $schoolId => $paths) {
            $plans[] = $this->planSchool((string) $schoolId, $paths, $offices);
        }

        return $plans;
    }

    /**
     * Writes one school's rows as the given user, in a single transaction.
     *
     * @param  SchoolPlan  $plan
     */
    public function import(array $plan, ?User $importer, EquipmentPropertyNumberService $numbers, EquipmentAccountabilityService $accountability): int
    {
        if ($importer !== null) {
            Auth::setUser($importer);
        }

        return DB::transaction(function () use ($plan, $importer, $numbers, $accountability) {
            foreach ($plan['rows'] as $row) {
                $equipment = new Equipment($row['equipment']);
                $equipment->createdBy()->associate($importer);
                $numbers->saveNumbered($equipment);
                $accountability->recordTransaction($equipment, $row['transaction']);
            }

            activity()
                ->when($importer !== null, fn ($log) => $log->causedBy($importer))
                ->performedOn($plan['office'])
                ->event(self::EVENT)
                ->withProperties(['school_id' => $plan['school_id'], 'file' => basename($plan['file']), 'rows' => count($plan['rows'])])
                ->log(self::EVENT);

            return count($plan['rows']);
        });
    }

    /**
     * @return list<string>
     */
    private function workbooks(string $folder): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (strtolower($file->getExtension()) === 'xlsx' && ! str_starts_with($file->getFilename(), '~$')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The file name's own 6-digit ID wins over its folder's, since files were found filed under other schools.
     */
    private function schoolIdOf(string $path, string $folder): ?string
    {
        preg_match_all('/(?<!\d)(\d{6})(?!\d)/', pathinfo($path, PATHINFO_FILENAME), $inName);
        $ids = array_values(array_unique($inName[1]));
        if (count($ids) === 1) {
            return $ids[0];
        }

        $relative = ltrim(substr($path, strlen(rtrim($folder, '\\/'))), '\\/');
        preg_match('/^(\d{6})(?!\d)/', $relative, $inFolder);

        return $inFolder[1] ?? null;
    }

    /**
     * Offices linked to a live PRIME department, by PRIME's school ID (the office's own only when PRIME has none).
     *
     * @return array<string, list<Office>>
     */
    private function primeSchoolOffices(): array
    {
        $linked = Office::query()->whereNotNull('prime_department_id')->get();
        $departments = $this->prime->departments();

        $bySchoolId = [];
        foreach ($linked as $office) {
            $department = $departments->get((int) $office->prime_department_id);
            if ($department === null) {
                continue;
            }

            $primeId = trim((string) ($department->office_school_id ?? ''));
            $id = $primeId !== '' ? $primeId : trim((string) $office->school_id);

            if (preg_match('/^\d{6}$/', $id) === 1) {
                $bySchoolId[$id][] = $office;
            }
        }

        return $bySchoolId;
    }

    /**
     * The newest workbook named like the school that has equipment rows.
     *
     * @param  list<string>  $paths
     * @param  array<string, list<Office>>  $offices
     * @return SchoolPlan
     */
    private function planSchool(string $schoolId, array $paths, array $offices): array
    {
        usort($paths, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));
        $plan = ['file' => $paths[0], 'school_id' => $schoolId, 'office' => null, 'name_match' => 0, 'problem' => null, 'rows' => [], 'already_imported' => false];
        $candidates = $offices[$schoolId] ?? [];

        $office = $this->pickOffice($candidates, $paths);

        if ($office === null) {
            $plan['problem'] = $candidates === []
                ? 'No PRIME-linked office has this school ID'
                : 'Several PRIME-linked offices share this school ID: '.collect($candidates)->pluck('office_name')->implode(', ');

            return $plan;
        }

        $plan['office'] = $office;
        $plan['already_imported'] = DB::table('activity_log')
            ->where('event', self::EVENT)->where('subject_type', $office->getMorphClass())->where('subject_id', $office->id)->exists();

        $named = array_values(array_filter($paths, fn (string $path) => $this->nameMatch(pathinfo($path, PATHINFO_FILENAME), (string) $office->office_name) >= self::MIN_NAME_MATCH));
        if ($named === []) {
            $plan['name_match'] = max(array_map(fn (string $path) => $this->nameMatch(pathinfo($path, PATHINFO_FILENAME), (string) $office->office_name), $paths));
            $plan['problem'] = "No file name looks like {$office->office_name}";

            return $plan;
        }

        foreach ($named as $path) {
            try {
                $rows = $this->mapRows($path, $office, $schoolId);
            } catch (Throwable $e) {
                $plan['problem'] = basename($path).' is unreadable: '.$e->getMessage();

                continue;
            }

            if ($rows !== []) {
                $unassigned = count(array_filter($rows, fn (array $row) => $row['transaction']['accountable_officer_id'] === null));

                return [...$plan, 'file' => $path, 'rows' => $rows,
                    'problem' => $unassigned === 0 ? null : "{$unassigned} row(s) have no officer and the school has no Principal, Head Teacher or OIC in IRIS",
                    'name_match' => $this->nameMatch(pathinfo($path, PATHINFO_FILENAME), (string) $office->office_name)];
            }
        }

        $plan['problem'] ??= 'No equipment rows in its workbooks';

        return $plan;
    }

    private static function schoolLevel(string $name): ?string
    {
        return match (true) {
            preg_match('/elementary|\belem\b|\bES\b/i', $name) === 1 => 'elementary',
            preg_match('/integrated|\bIS\b/i', $name) === 1 => 'integrated',
            preg_match('/high|\bN?M?HS\b/i', $name) === 1 => 'high',
            default => null,
        };
    }

    /**
     * The only candidate, or the one whose name and level best fit the files (a school and its SHS share an ID).
     *
     * @param  list<Office>  $candidates
     * @param  list<string>  $paths
     */
    private function pickOffice(array $candidates, array $paths): ?Office
    {
        if (count($candidates) <= 1) {
            return $candidates[0] ?? null;
        }

        $mentionsShs = collect($paths)->contains(fn (string $path) => preg_match('/\bSHS\b|senior high/i', pathinfo($path, PATHINFO_FILENAME)) === 1);
        $fitting = array_values(array_filter($candidates, fn (Office $office) => (preg_match('/\bSHS\b|senior high/i', (string) $office->office_name) === 1) === $mentionsShs));

        $level = collect($paths)->map(fn (string $path) => self::schoolLevel(pathinfo($path, PATHINFO_FILENAME)))->filter()->unique();
        if ($level->count() === 1) {
            $sameLevel = array_values(array_filter($fitting, fn (Office $office) => self::schoolLevel((string) $office->office_name) === $level->first()));
            $fitting = $sameLevel === [] ? $fitting : $sameLevel;
        }

        if (count($fitting) === 1) {
            return $fitting[0];
        }

        $scores = collect($fitting)->mapWithKeys(fn (Office $office) => [$office->id => max(array_map(
            fn (string $path) => $this->nameMatch(pathinfo($path, PATHINFO_FILENAME), (string) $office->office_name),
            $paths,
        ))])->sortDesc();

        return $scores->count() > 1 && $scores->values()[0] > $scores->values()[1]
            ? collect($fitting)->firstWhere('id', $scores->keys()[0])
            : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapRows(string $path, Office $office, string $schoolId): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['Equipment']);
        $workbook = $reader->load($path);
        $sheet = $workbook->getSheetByName('Equipment');

        if ($sheet === null) {
            throw new \RuntimeException('no Equipment sheet');
        }

        $people = new PsfPersonnelMatcher($office, $this->heads[$schoolId] ?? null);
        $rows = [];
        $blank = 0;
        $last = $sheet->getHighestDataRow('E');

        for ($r = self::FIRST_ROW; $r <= $last; $r++) {
            $cell = fn (string $column) => $this->clean($sheet->getCell($column.$r)->getValue());
            if ($cell('E') === null) {
                if (++$blank >= self::BLANK_ROWS_TO_STOP) {
                    break;
                }

                continue;
            }
            $blank = 0;
            $rows[] = ['row' => $r, ...$this->mapRow($cell, $sheet, $r, $people)];
        }

        $workbook->disconnectWorksheets();

        $uses = array_count_values(array_filter(array_map(fn (array $row) => $row['equipment']['old_property_no'], $rows)));
        foreach ($rows as &$row) {
            $number = $row['equipment']['old_property_no'];
            if ($number !== null && ($uses[$number] > self::SHARED_REFERENCE_ROWS || ($uses[$number] > 1 && preg_match(self::REFERENCE_PATTERN, $number) === 1))) {
                $row = $this->asDeliveryReference($row, $number);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * A number for a whole delivery or batch is the delivery document's, not the item's.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function asDeliveryReference(array $row, string $number): array
    {
        $row['equipment']['old_property_no'] = null;

        if ($row['transaction']['or_si_dr_iar_no'] === null) {
            $row['transaction']['or_si_dr_iar_no'] = mb_substr($number, 0, 255);
        } else {
            $row['equipment']['remarks'] = trim(($row['equipment']['remarks'] ?? '')."\nDelivery reference: {$number}");
        }
        $row['notes'][] = "property no. \"{$number}\" is a delivery or batch reference: kept as the document no.";

        return $row;
    }

    /**
     * Words that stand in for a missing number ("unknown", an item name) are no number at all.
     */
    private function propertyNumber(?string $value): ?string
    {
        if ($value === null || preg_match(self::NO_NUMBER_PATTERN, $value) === 1 || isset($this->lookups['item_type'][mb_strtolower($value)])) {
            return null;
        }

        return preg_match('/\d/', $value) === 1 ? $value : null;
    }

    /**
     * @param  callable(string): ?string  $cell
     * @return array{equipment: array<string, mixed>, transaction: array<string, mixed>, notes: list<string>, officer: ?string, item: ?string}
     */
    private function mapRow(callable $cell, Worksheet $sheet, int $r, PsfPersonnelMatcher $people): array
    {
        $notes = [];
        $raw = fn (string $column) => $sheet->getCell($column.$r)->getValue();
        $real = fn (string $column) => $this->placeholder($cell($column)) ? null : $cell($column);

        [$itemTypeId, $itemOther] = $this->pickOrOther('item_type', $cell('E'));
        [$brandId, $brandOther] = $this->pickOrOther('brand', $cell('G'));
        $brandId ??= $this->lookups['brand'][mb_strtolower(Equipment::OTHERS)] ?? null;
        [$conditionId, $conditionOther] = $this->pickOrOther('equipment_condition', $cell('AP') ?? self::DEFAULTS['equipment_condition']);
        $dcpPackage = $cell('K');
        [$dcpValue, $dcpOther] = $dcpPackage === null ? [null, null] : $this->pickOrOther('dcp_package', $dcpPackage);
        [$fundValue, $fundOther] = $this->pickOrOther('source_fund', $cell('W') ?? self::DEFAULTS['source_fund']);

        $model = $real('H');
        $specifications = $cell('I');
        if ($model !== null && mb_strlen($model) > self::MAX_TEXT) {
            $specifications = trim(($specifications ?? '')."\n".$model);
        }

        $propertyNo = $this->propertyNumber($real('B'));
        $previousNo = $this->propertyNumber($real('C'));
        $remarks = array_filter([
            $cell('AR'),
            $previousNo !== null && $previousNo !== $propertyNo ? "Previous property no.: {$previousNo}" : null,
        ]);

        $officer = $people->resolve($cell('AC'), fallbackToHead: true);
        if ($officer['note'] !== null) {
            $notes[] = $officer['note'];
        }
        $endUser = $people->resolve($cell('AE'), fallbackToHead: false);
        $receivedBy = $people->resolve($cell('AG'), fallbackToHead: false);
        foreach ([['end user', $endUser], ['received by', $receivedBy]] as [$label, $match]) {
            if ($match['id'] === null && $match['given'] !== null) {
                $notes[] = "{$label} not found at this school: left blank";
            }
        }

        $equipment = [
            'old_property_no' => $this->fit($propertyNo),
            'serial_number' => $this->fit($real('D')),
            'item_type_id' => $itemTypeId,
            'item_type_other' => $itemOther,
            'uom' => $this->pick('uom', $cell('F')),
            'brand_id' => $brandId,
            'brand_other' => $brandOther,
            'model' => $this->fit($model),
            'specifications' => $specifications,
            'non_dcp' => $dcpPackage === null,
            'dcp_package' => $dcpValue,
            'dcp_package_other' => $dcpOther,
            'dcp_year' => $dcpPackage === null ? null : $this->year($cell('L')),
            'equipment_classification_id' => $this->lookups['equipment_classification'][mb_strtolower((string) $cell('N'))]
                ?? $this->lookups['equipment_classification'][mb_strtolower(self::DEFAULTS['equipment_classification'])],
            'uacs' => $this->fit($real('P')),
            'acquisition_cost' => $this->money($raw('Q')),
            'received_date' => $this->date($raw('R')),
            'estimated_useful_life' => $this->integer($cell('S')),
            'mode_acquisition' => $this->pick('mode_acquisition', $cell('T')) ?? self::DEFAULTS['mode_acquisition'],
            'source_acquisition' => $this->pick('source_acquisition', $cell('U')) ?? self::DEFAULTS['source_acquisition'],
            'donor' => $this->fit($real('V')),
            'source_fund' => $fundValue,
            'source_fund_other' => $fundOther,
            'allotment_class' => $this->pick('allotment_class', $cell('X')),
            'pm_plan' => $this->fit($real('Y')),
            'supplier' => $this->fit($real('AK')),
            'under_warranty' => $this->truthy($raw('AL')),
            'end_warranty_date' => $this->date($raw('AM')),
            'equipment_location' => $cell('AN'),
            'non_functional' => $this->truthy($raw('AO')),
            'equipment_condition_id' => $conditionId,
            'equipment_condition_other' => $conditionOther,
            'disposition_status' => $this->pick('disposition_status', $cell('AQ')) ?? self::DEFAULTS['disposition_status'],
            'remarks' => $remarks === [] ? null : implode("\n", $remarks),
        ];

        foreach (['item_type_other' => 'item', 'brand_other' => 'brand', 'dcp_package_other' => 'DCP package', 'source_fund_other' => 'source of funds', 'equipment_condition_other' => 'condition'] as $field => $label) {
            if ($equipment[$field] !== null) {
                $notes[] = "{$label} \"{$equipment[$field]}\" kept under Others";
            }
        }

        $transaction = [
            'transaction_type' => $this->pick('transaction_type', $cell('AB')) ?? self::DEFAULTS['transaction_type'],
            'accountable_officer_id' => $officer['id'],
            'end_user_id' => $endUser['id'],
            'received_by_id' => $receivedBy['id'],
            'date_assigned_accountable_officer' => $this->date($raw('AD')),
            'date_assigned_end_user' => $this->date($raw('AF')),
            'date_received_new_accountable' => $this->date($raw('AH')),
            'supporting_documents1' => $this->pick('supporting_document_type', $cell('Z')),
            'or_si_dr_iar_no' => $this->fit($real('AA')),
            'supporting_documents2' => $this->pick('supporting_document_type', $cell('AI')),
            'par_ics_rrsp_rs_wmr_no' => $this->fit($real('AJ')),
        ];

        return ['equipment' => $equipment, 'transaction' => $transaction, 'notes' => $notes, 'officer' => $officer['name'], 'item' => $cell('E')];
    }

    private function loadLookups(): void
    {
        $byName = fn (string $model) => $model::query()->where('is_active', true)->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id])->all();
        $library = fn (EquipmentLibraryType $type) => collect(EquipmentLibrary::activeValues($type))->mapWithKeys(fn (string $value) => [mb_strtolower($value) => $value])->all();

        $this->lookups = [
            'item_type' => $byName(ItemType::class),
            'brand' => $byName(Brand::class),
            'equipment_condition' => $byName(EquipmentCondition::class),
            'equipment_classification' => $byName(EquipmentClassification::class),
            'uom' => $library(EquipmentLibraryType::UnitOfMeasure),
            'dcp_package' => $library(EquipmentLibraryType::DcpPackage),
            'mode_acquisition' => $library(EquipmentLibraryType::ModeOfAcquisition),
            'source_acquisition' => $library(EquipmentLibraryType::SourceOfAcquisition),
            'source_fund' => $library(EquipmentLibraryType::SourceOfFunds),
            'allotment_class' => $library(EquipmentLibraryType::AllotmentClass),
            'disposition_status' => $library(EquipmentLibraryType::DispositionStatus),
            'transaction_type' => $library(EquipmentLibraryType::TransactionType),
            'supporting_document_type' => $library(EquipmentLibraryType::SupportingDocumentType),
        ];
    }

    private function pick(string $lookup, ?string $value): int|string|null
    {
        if ($value === null) {
            return null;
        }
        $key = mb_strtolower($value);

        return $this->lookups[$lookup][$key] ?? $this->lookups[$lookup][mb_strtolower(self::ALIASES[$lookup][$key] ?? '')] ?? null;
    }

    /**
     * The matching option, or Others plus the file's own text.
     *
     * @return array{0: int|string|null, 1: ?string}
     */
    private function pickOrOther(string $lookup, ?string $value): array
    {
        $picked = $this->pick($lookup, $value);
        if ($picked !== null || $value === null) {
            return [$picked, null];
        }

        return [$this->lookups[$lookup][mb_strtolower(Equipment::OTHERS)] ?? null, mb_substr($value, 0, 255)];
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && str_starts_with($value, '='))) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', str_replace('RL-MO', 'Mo', (string) $value)) ?? '');
        $value = trim($value, " \t`'\"");

        return $value === '' ? null : $value;
    }

    private function fit(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, self::MAX_TEXT);
    }

    private function placeholder(?string $value): bool
    {
        return $value === null || in_array(mb_strtoupper($value), self::PLACEHOLDERS, true) || preg_match('/^[\s0\-\/.]*$/u', $value) === 1;
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', '✓', 'x'], true);
    }

    private function money(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0 ? number_format((float) $value, 2, '.', '') : null;
        }
        $digits = preg_replace('/[^\d.]/', '', (string) $value);

        return $digits !== '' && is_numeric($digits) ? number_format((float) $digits, 2, '.', '') : null;
    }

    private function integer(?string $value): ?int
    {
        return $value !== null && preg_match('/\d+/', $value, $m) === 1 ? (int) $m[0] : null;
    }

    private function year(?string $value): ?int
    {
        return $value !== null && preg_match('/(?<!\d)(19|20)\d{2}(?!\d)/', $value, $m) === 1 ? (int) $m[0] : null;
    }

    private function date(mixed $value): ?string
    {
        try {
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $date = CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value));
            } elseif (is_string($value) && ! $this->placeholder($this->clean($value))) {
                $date = CarbonImmutable::parse($value);
            } else {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $date->year >= 1950 && $date->year <= now()->year + 10 ? $date->toDateString() : null;
    }

    private function nameMatch(string $fileName, string $officeName): int
    {
        $key = function (string $name): string {
            $name = mb_strtolower(preg_replace('/\d+|_|ict[- ]equipment[- ]inventory.*|school[- ]template|inventory|ict|equipment/iu', ' ', $name) ?? '');
            $words = preg_split('/[^a-z]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return implode('', array_diff($words, self::SCHOOL_WORDS));
        };
        $file = $key($fileName);
        $office = $key($officeName);

        if ($office === '' || $file === '') {
            return 0;
        }
        if (str_contains($file, $office) || str_contains($office, $file)) {
            return 100;
        }
        similar_text($file, $office, $percent);

        return (int) round($percent);
    }
}
