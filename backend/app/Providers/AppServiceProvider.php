<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\ConnectivityLibrary;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\EquipmentLibrary;
use App\Models\IspProvider;
use App\Models\ItemType;
use App\Models\PersonnelLibrary;
use App\Models\Position;
use App\Models\RoOffice;
use App\Models\SdoOffice;
use App\Models\StakeholderLibrary;
use App\Models\User;
use App\Observers\EquipmentObserver;
use App\Policies\ReferenceDataPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureObservers();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Wires App\Enums\Permission into the Gate so `$user->can(...)` works
     * for permission-string abilities that don't map to a single Policy
     * method (e.g. the cross-cutting `reference-data.manage` /
     * `users.manage` Gates, and `equipment.transactions.create`). Model-scoped abilities
     * ('view', 'update', 'delete', ...) are untouched here — those are
     * resolved by the model's Policy exactly as normal, which in turn
     * calls User::hasPermissionTo() itself. Either path bottoms out at
     * the same granular permission check — never a role-name comparison.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability) {
            $permission = Permission::tryFrom($ability);

            if ($permission === null) {
                return null; // Not a permission-string ability — fall through to normal Policy/Gate resolution.
            }

            $allowed = $user->hasPermissionTo($permission);

            if (! $allowed) {
                AuditLog::record('authorization.denied', $user, ['permission' => $permission->value]);
            }

            return $allowed;
        });

        $this->registerReferenceDataPolicies();
    }

    /**
     * Binds ReferenceDataPolicy (one shared Policy, see its own
     * doc-comment for why) to all 13 reference-data model classes from
     * the lookup-normalization ADR. Explicit registration is required
     * here — Laravel's naming-convention auto-discovery only resolves a
     * Policy named `{Model}Policy` (which is how Equipment/Personnel/
     * IspAccount/StakeholderProfile/InternetConnectivitySurvey already
     * work, unregistered), and a single shared class
     * deliberately doesn't follow that per-model naming.
     */
    protected function registerReferenceDataPolicies(): void
    {
        foreach ([
            // Tier 1 — dedicated entity tables.
            ItemType::class,
            Brand::class,
            EquipmentCategory::class,
            EquipmentClassification::class,
            EquipmentCondition::class,
            Position::class,
            RoOffice::class,
            SdoOffice::class,
            IspProvider::class,
            // Tier 2 — domain-grouped library tables.
            EquipmentLibrary::class,
            PersonnelLibrary::class,
            StakeholderLibrary::class,
            ConnectivityLibrary::class,
        ] as $modelClass) {
            Gate::policy($modelClass, ReferenceDataPolicy::class);
        }
    }

    /**
     * Wires model Observers. Equipment's qr_code is the only auto-derived
     * column in the app today — see App\Observers\EquipmentObserver.
     */
    protected function configureObservers(): void
    {
        Equipment::observe(EquipmentObserver::class);
    }
}
