<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\GspIctProfile;
use App\Models\IspAccount;
use App\Models\Office;
use App\Models\StakeholderProfile;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * The bell's to-do list: live counts of work waiting in the user's data scope, one entry per
 * kind the user holds the permission for. Entries with nothing waiting are left out.
 */
final class NotificationCenter
{
    public function __construct(private readonly EquipmentCostOutliers $costOutliers) {}

    /**
     * @return list<array{key: string, label: string, count: int, to: string}>
     */
    public function for(User $user): array
    {
        $equipment = fn () => Equipment::query()->visibleTo($user)->inCustody();
        $offices = $this->offices($user);
        $own = $user->dataScope() !== DataScope::All;
        $officePath = fn (string $base) => $own && $user->office_id !== null ? "{$base}/{$user->office_id}" : $base;

        $definitions = [
            ['awaiting_receipt', Permission::EquipmentTransactionsCreate, 'item(s) moved to your office are awaiting receipt',
                fn () => $equipment()->whereNotNull('awaiting_receipt_since')->count(), '/equipment?awaiting_receipt=1'],
            ['gsp_gaps', Permission::EquipmentEdit, 'computer(s) are left out of GSP Table 1 (no Used by, or condition Not Applicable)',
                fn () => $equipment()->gspTable1Gaps()->count(), '/equipment?gsp_gaps=1'],
            ['no_school_level', Permission::EquipmentEdit, 'item(s) have no school level',
                fn () => $equipment()->whereNull('school_level')->count(), '/equipment?school_level=none'],
            ['pending_property_no', Permission::EquipmentEdit, 'item(s) are still waiting for a property number',
                fn () => $equipment()->whereNull('property_no')->count(), '/equipment?pending=1'],
            ['duplicate_old_property_no', Permission::EquipmentEdit, 'item(s) share an old property number',
                fn () => $equipment()->withDuplicateOldPropertyNo($user, true)->count(), '/equipment?duplicates=1'],
            ['high_cost', Permission::EquipmentEdit, 'item(s) have a cost that looks too high',
                fn () => $equipment()->whereRaw(...$this->costOutliers->condition())->count(), '/equipment?high_cost=1'],
            ['warranty_ending', Permission::EquipmentView, 'warranty(ies) end within 30 days',
                fn () => $equipment()->warrantyEndingWithin(30)->count(), '/equipment?warranty_ending=1'],
            ['stakeholder_profile_incomplete', Permission::StakeholderProfileEdit, $own ? 'Your office profile is not finished' : 'office profile(s) are not finished',
                fn () => (clone $offices)->with('stakeholderProfile')->get()->filter(fn (Office $office) => StakeholderProfile::completionPercent($office->stakeholderProfile, $office) < 100)->count(),
                $officePath('/stakeholder-profiles')],
            ['connectivity_not_started', Permission::InternetConnectivityEdit, $own ? 'Your office connectivity survey is not started' : 'connectivity survey(s) are not started',
                fn () => (clone $offices)->whereDoesntHave('internetConnectivitySurvey')->count(),
                $officePath('/internet-connectivity-surveys')],
            ['gsp_profile_missing', Permission::GspProfilesCreate, 'school(s) have no GSP ICT profile for SY '.self::currentSchoolYear(),
                fn () => (clone $offices)->whereNotNull('school_id')->whereNotIn('id', GspIctProfile::query()->select('office_id')->where('school_year', self::currentSchoolYear()))->count(),
                $own ? '/gsp-profiles/new' : '/gsp-profiles'],
            ['isp_stale_speed_test', Permission::IspAccountsEdit, 'ISP account(s) have no speed test in 90 days',
                fn () => IspAccount::query()->visibleTo($user)->staleSpeedTest()->count(), '/isp-accounts?stale_speed_test=1'],
            ['users_without_office', Permission::UsersEdit, 'active user(s) have no office',
                fn () => User::query()->visibleTo($user)->where('is_active', true)->whereNull('office_id')->count(), '/users?office_id=none'],
            ['failed_sign_ins', Permission::SecuritySettingsManage, 'failed sign-in attempt(s) in the last 24 hours',
                fn () => Activity::query()->where('event', 'auth.login_failed')->where('created_at', '>=', now()->subDay())->count(), '/audit-log?event=auth.login_failed'],
        ];

        $items = [];

        foreach ($definitions as [$key, $permission, $label, $count, $to]) {
            if ($user->hasPermissionTo($permission)) {
                $items[] = $this->item($key, $label, $count, $to);
            }
        }

        if ($user->hasTwoFactorEnabled() && count($user->two_factor_recovery_codes ?? []) <= 2) {
            $items[] = ['key' => 'recovery_codes_low', 'label' => 'Your 2FA recovery codes are running low', 'count' => count($user->two_factor_recovery_codes ?? []), 'to' => '/profile'];
        }

        return array_values(array_filter($items, fn (?array $item) => $item !== null));
    }

    public static function currentSchoolYear(): string
    {
        $start = now()->month >= 6 ? now()->year : now()->year - 1;

        return $start.'-'.($start + 1);
    }

    /**
     * @param  Closure(): int  $count
     * @return array{key: string, label: string, count: int, to: string}|null
     */
    private function item(string $key, string $label, Closure $count, string $to): ?array
    {
        $total = $count();

        return $total > 0 ? ['key' => $key, 'label' => $label, 'count' => $total, 'to' => $to] : null;
    }

    /**
     * @return Builder<Office>
     */
    private function offices(User $user): Builder
    {
        return Office::query()
            ->active()
            ->when($user->dataScope() !== DataScope::All, fn (Builder $query) => $query->whereKey($user->office_id ?? 0));
    }
}
