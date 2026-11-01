<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConnectivityLibraryType;
use App\Enums\EquipmentLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Models\ConnectivityLibrary;
use App\Models\EquipmentLibrary;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspAccount;
use App\Models\IspProvider;
use App\Models\Office;
use App\Models\PsgcBarangay;
use App\Models\PsgcMunicipality;
use App\Models\PsgcProvince;
use App\Models\StakeholderLibrary;
use App\Models\StakeholderProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * The Stakeholder Profile, Internet Connectivity and ISP sheets of the schools'
 * PSF workbooks. Schools resolve exactly as in PsfInventoryImport; profile and
 * survey answers only fill fields the school has left blank in IRIS.
 *
 * @phpstan-type DataPlan array{school_id: string, office: ?Office, file: string, problem: ?string, already_imported: bool, profile: array<string, mixed>, survey: array<string, mixed>, accounts: list<array<string, mixed>>, speed_tests: list<array<string, mixed>>, costs: list<array<string, mixed>>, notes: list<string>}
 */
final class PsfSchoolDataImport
{
    public const string EVENT = 'school_data.psf_imported';

    private const array SHEETS = ['Stakeholder Profile', 'Internet Connectivity', 'ISP Account', 'ISP Speed Test Results', 'ISP Subscription Cost'];

    private const int FIRST_TABLE_ROW = 4;

    private const int BLANK_ROWS_TO_STOP = 20;

    private const array TICKS = ['✓', '✔', '☑', 'true', '1', 'yes', 'y', 'x'];

    public const array ISP_DEFAULTS = [
        'school_area_coverage' => 'Not Applicable',
        'subscription_type' => 'Postpaid',
        'isp_connection_type' => 'Others',
        'purpose_of_subscription' => 'Others',
        'mode_of_acquisition' => 'DepEd Purchase',
        'source_of_acquisition' => 'School',
        'fund_source' => 'Not Applicable',
        'overall_signal_quality' => 'Intermittent',
    ];

    private const array FUND_ALIASES = [
        'program support funds (psf)' => 'Program Support Fund (PSF)',
        'school education fund (sef)' => 'Special Education Fund (SEF)',
        'mooe' => 'Maintenance and Other Operating Expenses (MOOE)',
    ];

    /** @var array<string, array<string, int|string>> */
    private array $lookups = [];

    public function __construct(private readonly PsfInventoryImport $schools) {}

    /**
     * @param  array<string, string>  $assign
     * @return list<DataPlan>
     */
    public function plan(string $folder, array $assign = []): array
    {
        $this->loadLookups();

        return array_map(fn (array $school) => $this->planSchool($school), $this->schools->schools($folder, $assign));
    }

    /**
     * @param  DataPlan  $plan
     * @return array{profile: int, survey: int, accounts: int, speed_tests: int, costs: int}
     */
    public function import(array $plan, ?User $importer): array
    {
        return DB::transaction(function () use ($plan, $importer) {
            $office = $plan['office'];
            $counts = [
                'profile' => $this->fillBlanks(StakeholderProfile::query()->firstOrCreate(['office_id' => $office->id]), $plan['profile']),
                'survey' => $this->fillBlanks(InternetConnectivitySurvey::query()->firstOrCreate(['office_id' => $office->id]), $plan['survey']),
                'accounts' => 0, 'speed_tests' => 0, 'costs' => 0,
            ];

            $accounts = IspAccount::query()->where('office_id', $office->id)->get()->keyBy(fn (IspAccount $account) => self::key($account->isp_billing_account_no));
            foreach ($plan['accounts'] as $data) {
                if (! $accounts->has(self::key($data['isp_billing_account_no']))) {
                    $accounts->put(self::key($data['isp_billing_account_no']), IspAccount::query()->create([...$data, 'office_id' => $office->id]));
                    $counts['accounts']++;
                }
            }

            foreach (['speed_tests' => 'speedTests', 'costs' => 'subscriptionCosts'] as $kind => $relation) {
                foreach ($plan[$kind] as $data) {
                    $account = $this->accountFor($accounts->all(), $data['_billing'], $data['_isp_provider_id']);
                    if ($account === null) {
                        continue;
                    }
                    $attributes = array_diff_key($data, array_flip(['_billing', '_isp_provider_id', '_row']));
                    if (! $account->{$relation}()->where($attributes)->exists()) {
                        $account->{$relation}()->create([...$attributes, 'recorded_by_user_id' => $importer?->id]);
                        $counts[$kind]++;
                    }
                }
            }

            activity()
                ->when($importer !== null, fn ($log) => $log->causedBy($importer))
                ->performedOn($office)
                ->event(self::EVENT)
                ->withProperties(['school_id' => $plan['school_id'], 'file' => basename($plan['file']), ...$counts])
                ->log(self::EVENT);

            return $counts;
        });
    }

    /**
     * @param  array{school_id: string, office: ?Office, problem: ?string, file: string, paths: list<string>}  $school
     * @return DataPlan
     */
    private function planSchool(array $school): array
    {
        $plan = ['school_id' => $school['school_id'], 'office' => $school['office'], 'file' => $school['file'], 'problem' => $school['problem'],
            'already_imported' => false, 'profile' => [], 'survey' => [], 'accounts' => [], 'speed_tests' => [], 'costs' => [], 'notes' => []];

        if ($school['problem'] !== null) {
            return $plan;
        }
        $plan['already_imported'] = PsfInventoryImport::alreadyImported(self::EVENT, $school['office']);

        foreach ($school['paths'] as $path) {
            try {
                $read = $this->read($path, $school['school_id']);
            } catch (Throwable $e) {
                $plan['problem'] = basename($path).' is unreadable: '.$e->getMessage();

                continue;
            }

            if ($read['profile'] !== [] || $read['survey'] !== [] || $read['accounts'] !== []) {
                return [...$plan, ...$read, 'file' => $path, 'problem' => null];
            }
        }

        $plan['problem'] ??= 'No school data in its workbooks';

        return $plan;
    }

    /**
     * @return array{profile: array<string, mixed>, survey: array<string, mixed>, accounts: list<array<string, mixed>>, speed_tests: list<array<string, mixed>>, costs: list<array<string, mixed>>, notes: list<string>}
     */
    private function read(string $path, string $schoolId): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(self::SHEETS);
        $workbook = $reader->load($path);
        $notes = [];

        $profile = ($sheet = $workbook->getSheetByName('Stakeholder Profile')) ? $this->profile($sheet, $schoolId, $notes) : [];
        $survey = ($sheet = $workbook->getSheetByName('Internet Connectivity')) ? $this->survey($sheet) : [];
        $accounts = ($sheet = $workbook->getSheetByName('ISP Account')) ? $this->accounts($sheet, $notes) : [];
        $speedTests = ($sheet = $workbook->getSheetByName('ISP Speed Test Results')) ? $this->speedTests($sheet, $accounts, $notes) : [];
        $costs = ($sheet = $workbook->getSheetByName('ISP Subscription Cost')) ? $this->costs($sheet, $accounts, $notes) : [];

        $workbook->disconnectWorksheets();

        return ['profile' => $profile, 'survey' => $survey, 'accounts' => $accounts, 'speed_tests' => $speedTests, 'costs' => $costs, 'notes' => $notes];
    }

    /**
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    private function profile(Worksheet $sheet, string $schoolId, array &$notes): array
    {
        $data = [];
        $lists = ['nearby_institutions' => [], 'access_paths' => [], 'transportation_options' => [], 'community_engagement' => []];
        $list = null;
        $person = null;
        $place = [];

        for ($r = 3; $r <= min($sheet->getHighestDataRow(), 150); $r++) {
            $label = mb_strtolower($this->text($sheet->getCell('B'.$r)->getValue().' '.$sheet->getCell('C'.$r)->getValue()) ?? '');
            $raw = $sheet->getCell('D'.$r)->getValue();
            $value = $this->text($raw);
            if ($label === '') {
                continue;
            }

            $list = match (true) {
                str_contains($label, 'nearby institutions') => 'nearby_institutions',
                str_contains($label, 'access paths') => 'access_paths',
                str_contains($label, 'transportation options') => 'transportation_options',
                str_contains($label, 'community engagement') => 'community_engagement',
                str_starts_with($label, 'travel time'), str_contains($label, 'transportation to school is difficult'), str_starts_with($label, 'remarks on community') => null,
                default => $list,
            };
            if (str_contains($label, 'chief/school head')) {
                $person = 'chief';
            } elseif (str_contains($label, 'administrative staff')) {
                $person = 'admin_staff';
            } elseif (str_contains($label, 'network administrator') || str_starts_with($label, 'support')) {
                $person = null;
            }

            if ($list !== null && ! str_contains($label, '(check all')) {
                if (str_starts_with($label, 'others')) {
                    $other = ['nearby_institutions' => 'nearby_institutions_other', 'transportation_options' => 'transportation_other'][$list] ?? null;
                    if ($other !== null && $value !== null && ! $this->ticked($raw)) {
                        $data[$other] = mb_substr($value, 0, 255);
                    }
                } elseif ($this->ticked($raw) && ($id = $this->libraryId($list, $label)) !== null) {
                    $lists[$list][] = $id;
                }

                continue;
            }

            match (true) {
                str_starts_with($label, 'governance level') => $data['governance_level'] = $value,
                preg_match('/^ro:?$/', $label) === 1 => $data['ro'] = $value,
                str_starts_with($label, 'sdo') => $data['sdo'] = $value,
                str_starts_with($label, 'school district') => $data['school_district'] = $value,
                str_starts_with($label, 'school name') => $data['school_name'] = $value,
                str_starts_with($label, 'school id') => $data['school_id'] = $schoolId,
                str_starts_with($label, 'province') => $place['province'] = $value,
                str_starts_with($label, 'city/municipality') => $place['municipality'] = $value,
                str_starts_with($label, 'legislative district') => $data['legislative_district'] = $value,
                str_starts_with($label, 'barangay') => $place['barangay'] = $value,
                str_starts_with($label, 'street') => $data['street'] = $value === null ? null : mb_substr($value, 0, 255),
                str_contains($label, 'necessary corrections') => $data['notes_corrections'] = $value,
                str_contains($label, 'recent development') => $data['notes_recent_development'] = $value,
                str_starts_with($label, 'mobile no. 1') => $data['mobile_1'] = $this->phone($raw),
                str_starts_with($label, 'mobile no. 2') => $data['mobile_2'] = $this->phone($raw),
                str_starts_with($label, 'landline') => $data['landline'] = $this->phone($raw),
                str_contains($label, 'chief/school head') => $data['chief_name'] = $value,
                str_contains($label, 'administrative staff') => $data['admin_staff_name'] = $value,
                str_contains($label, 'network administrator') => $data['network_administrator_name'] = $value,
                $person !== null && (str_starts_with($label, 'position') || str_starts_with($label, 'postion')) => $data[$person.'_position'] = $value,
                $person !== null && str_starts_with($label, 'email') => $data[$person.'_email'] = filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null,
                $person !== null && str_starts_with($label, 'mobile no') => $data[$person.'_mobile'] = $this->phone($raw),
                str_starts_with($label, 'longitude') => $data['longitude'] = $this->coordinate($raw, 180),
                str_starts_with($label, 'latitude') => $data['latitude'] = $this->coordinate($raw, 90),
                str_starts_with($label, 'travel time') => $data['travel_time_to_center_minutes'] = $this->integer($raw),
                str_contains($label, 'transportation to school is difficult') => $data['transportation_difficult'] = $this->ticked($raw) ?: null,
                str_starts_with($label, 'considered very remote') => $data['considered_very_remote'] = $this->ticked($raw) ?: null,
                str_contains($label, 'if school very remote') => $data['remote_context_notes'] = $value,
                str_starts_with($label, 'gidca') => $data['gidca'] = $this->ticked($raw) ?: null,
                str_starts_with($label, 'lms') => $data['lms'] = $this->ticked($raw) ?: null,
                str_starts_with($label, 'remarks on community') => $data['community_context_remarks'] = $value,
                str_starts_with($label, 'date & time submitted') => $data['submitted_at'] = $this->dateTime($raw),
                str_starts_with($label, 'type of transaction') => $data['transaction_type'] = $this->pick('transaction_type', $value),
                default => null,
            };
        }

        foreach ($lists as $field => $ids) {
            $data[$field] = $ids === [] ? null : array_values(array_unique($ids));
        }

        return array_filter([...$data, ...$this->psgc($place, $notes)], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function survey(Worksheet $sheet): array
    {
        $data = [];
        $lists = ['available_isps' => [], 'mobile_signal_types' => [], 'subscribed_isps' => [], 'coverage_areas' => [], 'electricity_sources' => []];
        $question = null;

        for ($r = 4; $r <= min($sheet->getHighestDataRow(), 120); $r++) {
            $text = mb_strtolower($this->text($sheet->getCell('C'.$r)->getValue()) ?? '');
            $option = $this->text($sheet->getCell('D'.$r)->getValue());
            $raw = $sheet->getCell('E'.$r)->getValue();
            $answer = $this->text($raw);

            if ($text !== '') {
                $question = match (true) {
                    str_contains($text, 'what internet service provider') => 'available_isps',
                    str_contains($text, 'internet service provider') && str_contains($text, 'available in the area') => 'has_isp_in_area',
                    str_contains($text, 'mobile network signal') => 'mobile_signal_types',
                    str_starts_with($text, 'area has mobile data') => 'has_mobile_data_connectivity',
                    str_contains($text, 'quality of mobile data') => 'mobile_data_quality',
                    str_contains($text, 'do you subscribe') => 'subscribes_to_isp',
                    str_contains($text, 'specify your internet service provider') => 'subscribed_isps',
                    str_contains($text, 'for other use') => 'rooms_other_use',
                    str_contains($text, 'bandwidth insufficient') => 'insufficient_bandwidth_explanation',
                    str_contains($text, 'coverage area') => 'coverage_areas',
                    str_contains($text, 'recipient of the dict') => 'dict_free_wifi_recipient',
                    str_contains($text, 'rate dict') => 'dict_free_wifi_rating',
                    str_contains($text, 'sufficient bandwidth for internet') => 'has_sufficient_bandwidth',
                    str_contains($text, 'if no subscription') => 'no_subscription_reason',
                    str_contains($text, 'specify the source') => 'electricity_sources',
                    str_contains($text, 'source of electricity') => 'has_electricity_source',
                    str_contains($text, 'solar energy') => 'primarily_solar_powered',
                    str_contains($text, 'brownout') => 'frequent_brownouts',
                    default => null,
                };

                match ($question) {
                    'has_isp_in_area', 'has_mobile_data_connectivity', 'subscribes_to_isp', 'dict_free_wifi_recipient',
                    'has_sufficient_bandwidth', 'has_electricity_source', 'primarily_solar_powered', 'frequent_brownouts' => $data[$question] = $this->ticked($raw) ?: null,
                    'mobile_data_quality' => $data[$question] = $this->pick('signal_quality', $answer),
                    'rooms_other_use' => $data[$question] = $this->integer($raw),
                    'insufficient_bandwidth_explanation', 'no_subscription_reason' => $data[$question] = $this->ticked($raw) ? null : $answer,
                    'dict_free_wifi_rating' => $data[$question] = $this->rating($raw),
                    default => null,
                };

                continue;
            }

            if ($option === null || ! isset($lists[$question])) {
                continue;
            }

            if (str_starts_with(mb_strtolower($option), 'other')) {
                $other = ['available_isps' => 'available_isps_other', 'coverage_areas' => 'coverage_areas_other'][$question] ?? null;
                if ($other !== null && $answer !== null && ! $this->ticked($raw)) {
                    $data[$other] = mb_substr($answer, 0, 255);
                }

                continue;
            }

            if ($this->ticked($raw)) {
                $lookup = ['available_isps' => 'isp_provider', 'subscribed_isps' => 'isp_provider', 'mobile_signal_types' => 'mobile_signal_types',
                    'coverage_areas' => 'coverage_areas', 'electricity_sources' => 'electricity_sources'][$question];
                if (($id = $this->libraryId($lookup, $option)) !== null) {
                    $lists[$question][] = $id;
                }
            }
        }

        foreach ($lists as $field => $ids) {
            $data[$field] = $ids === [] ? null : array_values(array_unique($ids));
        }

        return array_filter($data, fn ($value) => $value !== null);
    }

    /**
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function accounts(Worksheet $sheet, array &$notes): array
    {
        $accounts = [];
        foreach ($this->table($sheet, ['isp', 'cost_per_month', 'isp_billing_account_no', 'package_purchased_inclusion', 'school_area_coverage', 'min_speed', 'max_speed', 'subscription_type', 'isp_connection_types', 'contract_start_date', 'contract_end_date', 'inactive_contract', 'purpose_of_subscription', 'mode_of_acquisition', 'source_of_acquisition', 'donor', 'fund_source', 'number_access_points_linked', 'locations_access_points', 'number_admin_area_covered', 'rate_connectivity_admin_areas', 'number_classrooms_covered', 'rate_connectivity_classroom', 'overall_signal_quality', 'rate_isp_service'], fn (array $row) => $row['isp'] !== null || $row['isp_billing_account_no'] !== null) as $n => $row) {
            [$providerId, $provider] = $this->provider($row['isp']);
            $billing = $row['isp_billing_account_no'] ?? "Not provided ({$provider}, row {$n})";
            $classroom = $this->pick('signal_quality', $row['rate_connectivity_classroom']);
            $admin = $this->pick('signal_quality', $row['rate_connectivity_admin_areas']);

            $accounts[] = [
                'isp_provider_id' => $providerId,
                'cost_per_month' => $this->money($row['_raw']['cost_per_month']) ?? '0.00',
                'isp_billing_account_no' => mb_substr($billing, 0, 255),
                'package_purchased_inclusion' => $row['package_purchased_inclusion'] ?? 'Not provided',
                'school_area_coverage' => $this->pick('school_area_coverage', $row['school_area_coverage']) ?? self::ISP_DEFAULTS['school_area_coverage'],
                'min_speed' => $this->number($row['_raw']['min_speed']),
                'max_speed' => $this->number($row['_raw']['max_speed']),
                'subscription_type' => $this->pick('subscription_type', $row['subscription_type']) ?? self::ISP_DEFAULTS['subscription_type'],
                'isp_connection_type' => $this->pick('isp_connection_type', $row['isp_connection_types']) ?? self::ISP_DEFAULTS['isp_connection_type'],
                'contract_start_date' => $this->date($row['_raw']['contract_start_date']),
                'contract_end_date' => $this->date($row['_raw']['contract_end_date']),
                'inactive_contract' => $this->ticked($row['_raw']['inactive_contract']),
                'purpose_of_subscription' => $this->pick('purpose_of_subscription', $row['purpose_of_subscription']) ?? self::ISP_DEFAULTS['purpose_of_subscription'],
                'mode_of_acquisition' => $this->pick('mode_of_acquisition', $row['mode_of_acquisition']) ?? self::ISP_DEFAULTS['mode_of_acquisition'],
                'source_of_acquisition' => $this->pick('source_of_acquisition', $row['source_of_acquisition']) ?? self::ISP_DEFAULTS['source_of_acquisition'],
                'donor' => $row['donor'] === null ? null : mb_substr($row['donor'], 0, 255),
                'fund_source' => $this->pick('fund_source', $row['fund_source']) ?? self::ISP_DEFAULTS['fund_source'],
                'number_access_points_linked' => $this->integer($row['_raw']['number_access_points_linked']),
                'locations_access_points' => $row['locations_access_points'],
                'number_admin_area_covered' => $this->integer($row['_raw']['number_admin_area_covered']),
                'rate_connectivity_admin_areas' => $admin,
                'number_classrooms_covered' => $this->integer($row['_raw']['number_classrooms_covered']),
                'rate_connectivity_classroom' => $classroom,
                'overall_signal_quality' => $this->pick('signal_quality', $row['overall_signal_quality']) ?? $classroom ?? $admin ?? self::ISP_DEFAULTS['overall_signal_quality'],
                'rate_isp_service' => $this->rating($row['_raw']['rate_isp_service']),
            ];

            if ($row['isp_billing_account_no'] === null) {
                $notes[] = "ISP account on row {$n} has no billing account no.";
            }
        }

        return $accounts;
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function speedTests(Worksheet $sheet, array $accounts, array &$notes): array
    {
        $tests = [];
        foreach ($this->table($sheet, ['isp', 'isp_billing_account_number', 'download_speed', 'upload_speed', 'ping', 'timestamp', 'signal_quality', 'rate_isp_service'], fn (array $row) => $row['download_speed'] !== null || $row['upload_speed'] !== null) as $n => $row) {
            $testedAt = $this->dateTime($row['_raw']['timestamp']);
            if ($testedAt === null) {
                $notes[] = "speed test on row {$n} has no valid date: skipped";

                continue;
            }

            $tests[] = [
                '_row' => $n,
                '_billing' => $row['isp_billing_account_number'],
                '_isp_provider_id' => $row['isp'] === null ? null : $this->provider($row['isp'])[0],
                'download_speed' => $this->number($row['_raw']['download_speed']) ?? '0.00',
                'upload_speed' => $this->number($row['_raw']['upload_speed']) ?? '0.00',
                'ping' => $this->integer($row['_raw']['ping']),
                'tested_at' => $testedAt,
                'signal_quality' => $this->pick('signal_quality', $row['signal_quality']) ?? ($accounts[0]['overall_signal_quality'] ?? self::ISP_DEFAULTS['overall_signal_quality']),
                'rate_isp_service' => $this->rating($row['_raw']['rate_isp_service']),
            ];
        }

        return $this->attachable($tests, $accounts, 'speed test', $notes);
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function costs(Worksheet $sheet, array $accounts, array &$notes): array
    {
        $costs = [];
        foreach ($this->table($sheet, ['isp', 'isp_billing_account_number', 'contract_start_date', 'contract_end_date', 'total_amount_spent', 'contract_projected_start_date', 'contract_projected_end_date', 'total_projected_expenditure'], fn (array $row) => $row['total_amount_spent'] !== null || $row['total_projected_expenditure'] !== null) as $n => $row) {
            $costs[] = [
                '_row' => $n,
                '_billing' => $row['isp_billing_account_number'],
                '_isp_provider_id' => $row['isp'] === null ? null : $this->provider($row['isp'])[0],
                'contract_start_date' => $this->date($row['_raw']['contract_start_date']),
                'contract_end_date' => $this->date($row['_raw']['contract_end_date']),
                'total_amount_spent' => $this->money($row['_raw']['total_amount_spent']),
                'contract_projected_start_date' => $this->date($row['_raw']['contract_projected_start_date']),
                'contract_projected_end_date' => $this->date($row['_raw']['contract_projected_end_date']),
                'total_projected_expenditure' => $this->money($row['_raw']['total_projected_expenditure']),
            ];
        }

        return $this->attachable($costs, $accounts, 'subscription cost', $notes);
    }

    /**
     * Rows that belong to one of the school's ISP accounts in the file or already in IRIS.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $accounts
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function attachable(array $rows, array $accounts, string $label, array &$notes): array
    {
        $byBilling = collect($accounts)->keyBy(fn (array $account) => self::key($account['isp_billing_account_no']))->all();

        return array_values(array_filter($rows, function (array $row) use ($byBilling, $label, &$notes) {
            if ($this->accountFor($byBilling, $row['_billing'], $row['_isp_provider_id']) !== null || $byBilling === []) {
                if ($byBilling === []) {
                    $notes[] = "{$label} on row {$row['_row']} kept for an ISP account already in IRIS, if one matches";
                }

                return true;
            }
            $notes[] = "{$label} on row {$row['_row']} matches no ISP account: skipped";

            return false;
        }));
    }

    /**
     * The account with this billing no., else the only one with this ISP, else the school's only account.
     *
     * @template T
     *
     * @param  array<string, T>  $accounts
     * @return T|null
     */
    private function accountFor(array $accounts, ?string $billing, ?int $providerId): mixed
    {
        if ($billing !== null && isset($accounts[self::key($billing)])) {
            return $accounts[self::key($billing)];
        }

        $sameIsp = array_filter($accounts, fn ($account) => $providerId !== null && (int) data_get($account, 'isp_provider_id') === $providerId);
        if (count($sameIsp) === 1) {
            return reset($sameIsp);
        }

        return count($accounts) === 1 ? reset($accounts) : null;
    }

    /**
     * Rows of a header-keyed table (row 1 holds column keys), from row 4 down; a key missing from the sheet reads as blank.
     *
     * @param  list<string>  $expected
     * @param  callable(array<string, mixed>): bool  $hasData
     * @return array<int, array<string, mixed>>
     */
    private function table(Worksheet $sheet, array $expected, callable $hasData): array
    {
        $keys = [];
        foreach ($sheet->getRowIterator(1, 1)->current()->getCellIterator('A', $sheet->getHighestDataColumn()) as $cell) {
            if (($key = $this->text($cell->getValue())) !== null) {
                $keys[$cell->getColumn()] = $key;
            }
        }
        $blankRow = ['_raw' => array_fill_keys($expected, null), ...array_fill_keys($expected, null)];

        $rows = [];
        $blank = 0;
        for ($r = self::FIRST_TABLE_ROW; $r <= min($sheet->getHighestDataRow(), 2000); $r++) {
            $row = $blankRow;
            foreach ($keys as $column => $key) {
                $cell = $sheet->getCell($column.$r);
                $raw = $cell->getValue();
                $raw = is_string($raw) && str_starts_with($raw, '=') ? $cell->getOldCalculatedValue() : $raw;
                $row['_raw'][$key] = $raw === '' ? null : $raw;
                $row[$key] = $this->text($row['_raw'][$key]);
            }

            if (! $hasData($row)) {
                if (++$blank >= self::BLANK_ROWS_TO_STOP) {
                    break;
                }

                continue;
            }
            $blank = 0;
            $rows[$r] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, ?string>  $place
     * @param  list<string>  $notes
     * @return array<string, int|null>
     */
    private function psgc(array $place, array &$notes): array
    {
        $province = ($place['province'] ?? null) === null ? null
            : PsgcProvince::query()->whereRaw('upper(name) = ?', [mb_strtoupper($place['province'])])->first();
        $municipality = $province === null || ($place['municipality'] ?? null) === null ? null
            : self::closestPlace(PsgcMunicipality::query()->where('province_id', $province->id)->get()->all(), $place['municipality']);
        $barangay = $municipality === null || ($place['barangay'] ?? null) === null ? null
            : self::closestPlace(PsgcBarangay::query()->where('municipality_id', $municipality->id)->get()->all(), $place['barangay']);

        foreach (['province' => $province, 'municipality' => $municipality, 'barangay' => $barangay] as $level => $found) {
            if ($found === null && ($place[$level] ?? null) !== null) {
                $notes[] = "{$level} \"{$place[$level]}\" not found in the address list";
            }
        }

        return ['province_id' => $province?->id, 'municipality_id' => $municipality?->id, 'barangay_id' => $barangay?->id];
    }

    /**
     * Fills only what is still blank; true ticks may set a still-false flag.
     *
     * @param  array<string, mixed>  $data
     */
    private function fillBlanks(StakeholderProfile|InternetConnectivitySurvey $model, array $data): int
    {
        $filled = 0;
        foreach ($data as $field => $value) {
            $current = $model->getAttribute($field);
            if ($current === null || $current === '' || $current === [] || ($current === false && $value === true)) {
                $model->setAttribute($field, $value);
                $filled++;
            }
        }
        $model->save();

        return $filled;
    }

    private function loadLookups(): void
    {
        $stakeholder = fn (StakeholderLibraryType $type) => StakeholderLibrary::query()->where('type', $type->value)->where('is_active', true)->pluck('id', 'value')->mapWithKeys(fn ($id, $value) => [self::optionKey($value) => $id])->all();
        $connectivityIds = fn (ConnectivityLibraryType $type) => ConnectivityLibrary::query()->where('type', $type->value)->where('is_active', true)->pluck('id', 'value')->mapWithKeys(fn ($id, $value) => [self::optionKey($value) => $id])->all();
        $connectivity = fn (ConnectivityLibraryType $type) => collect(ConnectivityLibrary::activeValues($type))->mapWithKeys(fn (string $value) => [self::optionKey($value) => $value])->all();
        $equipment = fn (EquipmentLibraryType $type) => collect(EquipmentLibrary::activeValues($type))->mapWithKeys(fn (string $value) => [self::optionKey($value) => $value])->all();

        $this->lookups = [
            'nearby_institutions' => $stakeholder(StakeholderLibraryType::NearbyInstitution),
            'access_paths' => $stakeholder(StakeholderLibraryType::TypeOfAccessRoad),
            'transportation_options' => $stakeholder(StakeholderLibraryType::ByTransportation),
            'community_engagement' => $stakeholder(StakeholderLibraryType::CommunityEngagement),
            'electricity_sources' => $stakeholder(StakeholderLibraryType::SourceOfElectricity),
            'mobile_signal_types' => $connectivityIds(ConnectivityLibraryType::MobileNetworkSignal),
            'coverage_areas' => $connectivityIds(ConnectivityLibraryType::CoverageArea),
            'isp_provider' => IspProvider::query()->where('is_active', true)->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [self::optionKey($name) => $id])->all(),
            'signal_quality' => $connectivity(ConnectivityLibraryType::SignalQuality),
            'school_area_coverage' => $connectivity(ConnectivityLibraryType::SchoolLevelCoverage),
            'subscription_type' => $connectivity(ConnectivityLibraryType::SubscriptionType),
            'isp_connection_type' => $connectivity(ConnectivityLibraryType::IspConnectionType),
            'purpose_of_subscription' => $connectivity(ConnectivityLibraryType::PurposeOfSubscription),
            'transaction_type' => $equipment(EquipmentLibraryType::TransactionType),
            'mode_of_acquisition' => $equipment(EquipmentLibraryType::ModeOfAcquisition),
            'source_of_acquisition' => $equipment(EquipmentLibraryType::SourceOfAcquisition),
            'fund_source' => $equipment(EquipmentLibraryType::SourceOfFunds),
        ];
    }

    private function libraryId(string $lookup, string $label): ?int
    {
        $id = $this->lookups[$lookup][self::optionKey($label)] ?? null;

        return $id === null ? null : (int) $id;
    }

    private function pick(string $lookup, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $key = self::optionKey($value);
        $alias = $lookup === 'fund_source' ? (self::FUND_ALIASES[mb_strtolower($value)] ?? null) : null;

        $picked = $this->lookups[$lookup][$key] ?? ($alias === null ? null : $this->lookups[$lookup][self::optionKey($alias)] ?? null);

        return $picked === null ? null : (string) $picked;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function provider(?string $name): array
    {
        $id = $name === null ? null : ($this->lookups['isp_provider'][self::optionKey($name)] ?? null);

        return [(int) ($id ?? $this->lookups['isp_provider'][self::optionKey('Others')]), $name ?? 'unknown ISP'];
    }

    private static function optionKey(string $value): string
    {
        $value = mb_strtolower((string) preg_replace('/\([^)]*\)/', ' ', $value));

        return (string) preg_replace('/[^a-z0-9]+/', '', $value);
    }

    /**
     * The same name, then without a trailing number ("Capaya II"), then the one name contained in it
     * ("San Ignacio Subd. Pandan"), then a near spelling ("Loudes Sur East"); a tie matches nothing.
     *
     * @template T of PsgcMunicipality|PsgcBarangay
     *
     * @param  list<T>  $places
     * @return T|null
     */
    private static function closestPlace(array $places, string $given): mixed
    {
        $key = self::placeKey($given);
        $numberless = self::placeKey((string) preg_replace('/\s+(\d+|[ivx]+)\.?$/i', '', trim($given)));
        $keyed = array_map(fn ($place) => [$place, self::placeKey($place->name)], $places);

        foreach ([fn (string $k) => $k === $key, fn (string $k) => $k === $numberless, fn (string $k) => strlen($k) >= 4 && str_contains($key, $k)] as $test) {
            $found = array_values(array_filter($keyed, fn (array $pair) => $test($pair[1])));
            if (count($found) === 1) {
                return $found[0][0];
            }
        }

        $scores = array_map(function (array $pair) use ($key) {
            similar_text($key, $pair[1], $percent);

            return $percent;
        }, $keyed);
        arsort($scores);
        $best = array_key_first($scores);
        $runnerUp = array_values($scores)[1] ?? 0;

        return $best !== null && $scores[$best] >= 88 && $scores[$best] > $runnerUp ? $keyed[$best][0] : null;
    }

    private static function placeKey(string $name): string
    {
        $name = mb_strtolower(str_replace(['ñ', 'Ñ'], 'n', $name));
        $name = (string) preg_replace(['/^city of\s+|\s+city$|\(.*?\)/', '/^(barangay|brgy\.?)\s+/', '/\bsanta\b/', '/\bsanto\b/'], ['', '', 'sta', 'sto'], trim($name));

        return (string) preg_replace('/[^a-z0-9]+/', '', $name);
    }

    private static function key(?string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $value));
    }

    private function text(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && str_starts_with($value, '='))) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' || preg_match('/^-{3,}$/', $value) === 1 ? null : $value;
    }

    private function ticked(mixed $value): bool
    {
        return $value === true || in_array(mb_strtolower(trim((string) $value)), self::TICKS, true);
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0 ? (int) round($value) : null;
        }

        return is_string($value) && ! str_starts_with($value, '=') && preg_match('/\d+/', $value, $m) === 1 ? (int) $m[0] : null;
    }

    private function number(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0 ? number_format((float) $value, 2, '.', '') : null;
        }
        $digits = preg_replace('/[^\d.]/', '', (string) $value);

        return $digits !== '' && is_numeric($digits) ? number_format((float) $digits, 2, '.', '') : null;
    }

    private function money(mixed $value): ?string
    {
        return $this->number($value);
    }

    private function rating(mixed $value): ?int
    {
        $stars = mb_substr_count((string) $value, '⭐') + mb_substr_count((string) $value, '★');
        $rating = $stars > 0 ? $stars : $this->integer($value);

        return $rating !== null && $rating >= 1 && $rating <= 5 ? $rating : null;
    }

    private function coordinate(mixed $value, int $limit): ?string
    {
        return is_numeric($value) && abs((float) $value) <= $limit ? number_format((float) $value, 7, '.', '') : null;
    }

    private function phone(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', is_float($value) ? number_format($value, 0, '', '') : (string) $value);
        if ($digits === null || $digits === '' || preg_match('/^0+$/', $digits) === 1) {
            return null;
        }

        return mb_substr(strlen($digits) === 10 && $digits[0] === '9' ? '0'.$digits : $digits, 0, 20);
    }

    private function date(mixed $value): ?string
    {
        return $this->parsed($value)?->toDateString();
    }

    private function dateTime(mixed $value): ?string
    {
        return $this->parsed($value)?->toDateTimeString();
    }

    private function parsed(mixed $value): ?CarbonImmutable
    {
        try {
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $date = CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value));
            } elseif (is_string($value) && ($text = $this->text($value)) !== null) {
                $date = null;
                if (preg_match('/\b(1[3-9]|2[0-3]):\d{2}/', $text) === 1) {
                    $text = trim((string) preg_replace('/\s*[AP]M$/i', '', $text));
                }
                foreach (['!m/d/Y h:iA', '!m/d/Y h:i A', '!m/d/Y h:i:s A', '!m/d/Y H:i:s', '!m/d/Y H:i', '!m/d/Y'] as $format) {
                    try {
                        $date = CarbonImmutable::createFromFormat($format, $text) ?: null;
                    } catch (Throwable) {
                        $date = null;
                    }
                    if ($date !== null) {
                        break;
                    }
                }
                $date ??= CarbonImmutable::parse($text);
            } else {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $date->year >= 1990 && $date->year <= now()->year + 10 ? $date : null;
    }
}
