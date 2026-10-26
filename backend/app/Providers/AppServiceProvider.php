<?php

namespace App\Providers;

use App\Http\Controllers\Api\Auth\TwoFactorController;
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
use App\Models\Role;
use App\Models\RoOffice;
use App\Models\SdoOffice;
use App\Models\StakeholderLibrary;
use App\Models\User;
use App\Observers\EquipmentObserver;
use App\Policies\ReferenceDataPolicy;
use App\Policies\RolePolicy;
use App\Services\SecuritySettings;
use App\Services\SlowQueryLog;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

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
        $this->configureRateLimiting();
        $this->configureAuthorization();
        $this->configureAuditTrail();
        $this->configureObservers();
        $this->configureSystemLogs();
    }

    /**
     * The app-side slow query log (DB_SLOW_QUERY_MS > 0; 0 turns it off).
     */
    protected function configureSystemLogs(): void
    {
        if (SlowQueryLog::enabled()) {
            DB::listen(SlowQueryLog::record(...));
        }
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

        $this->configureTrustedProxies();

        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());
    }

    /**
     * Lives here rather than in bootstrap/app.php's trustProxies() because
     * that callback runs before config and .env are loaded.
     */
    public function configureTrustedProxies(): void
    {
        $proxies = config('app.trusted_proxies');

        if (! is_string($proxies) || trim($proxies) === '') {
            return;
        }

        TrustProxies::at(trim($proxies) === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        TrustProxies::withHeaders(self::trustedProxyHeaders((string) config('app.trusted_proxy_headers', 'for')));
    }

    public static function trustedProxyHeaders(string $names): int
    {
        $map = [
            'for' => Request::HEADER_X_FORWARDED_FOR,
            'host' => Request::HEADER_X_FORWARDED_HOST,
            'port' => Request::HEADER_X_FORWARDED_PORT,
            'proto' => Request::HEADER_X_FORWARDED_PROTO,
            'prefix' => Request::HEADER_X_FORWARDED_PREFIX,
        ];

        $headers = 0;

        foreach (explode(',', strtolower($names)) as $name) {
            $headers |= $map[trim($name)] ?? 0;
        }

        // Zero would make TrustProxies fall back to trusting every header.
        return $headers ?: Request::HEADER_X_FORWARDED_FOR;
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $perAccount = SecuritySettings::get('login_rate_limit_per_minute');

            return [
                Limit::perMinute($perAccount)->by('account:'.strtolower((string) $request->input('email')).'|'.$request->ip()),
                Limit::perMinute(max(10, $perAccount * 4))->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('password-confirm', function (Request $request) {
            return Limit::perMinute(6)->by('user:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('user-update', function (Request $request) {
            $target = $request->route('user');
            $targetId = $target instanceof User ? $target->id : (int) $target;

            return $request->user()?->id === $targetId && $request->filled('current_password')
                ? Limit::perMinute(6)->by('user:'.$targetId)
                : Limit::none();
        });

        RateLimiter::for('authenticated-api', function (Request $request) {
            return Limit::perMinute(600)->by('user:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('two-factor-verify', function (Request $request) {
            $token = $request->input('two_fa_token') ?? $request->input('setup_token');
            $userId = TwoFactorController::pendingUserIdForToken(is_string($token) ? $token : null);

            $perMinute = Limit::perMinute(SecuritySettings::get('two_factor_verify_rate_limit_per_minute'))
                ->by($userId ? "user:{$userId}" : 'ip:'.$request->ip());

            // Hourly cap is per account, so logging in again for a fresh
            // token doesn't reset it.
            return $userId
                ? [$perMinute, Limit::perHour(20)->by("user-hour:{$userId}")]
                : $perMinute;
        });
    }

    /**
     * Permission-string abilities (`$user->can('equipment.view')`, the
     * `permission:` route middleware) resolve through Spatie's own Gate
     * hook (`permission.register_permission_check_method`). Model
     * abilities resolve through each model's Policy, which checks a
     * permission itself. Either way it bottoms out at a granular
     * permission, never a role name.
     */
    protected function configureAuthorization(): void
    {
        // Role extends Spatie's model, so policy auto-discovery can't be
        // relied on; register it explicitly.
        Gate::policy(Role::class, RolePolicy::class);

        $this->registerReferenceDataPolicies();
    }

    /**
     * Binds ReferenceDataPolicy (one shared Policy, see its own
     * doc-comment for why) to all 13 reference-data model classes from
     * the lookup-normalization ADR. Explicit registration is required
     * because a single shared class doesn't follow the per-model
     * `{Model}Policy` naming auto-discovery relies on.
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
     * Every activity row carries the actor's name/email as written, so
     * attribution survives the account being deleted later
     * (activity_log.causer_id has no FK). This is the one place it's set.
     */
    protected function configureAuditTrail(): void
    {
        Activity::creating(function (Activity $activity) {
            if ($activity->causer_id === null || $activity->causer_type !== (new User)->getMorphClass()) {
                return;
            }

            $properties = collect($activity->properties);

            if ($properties->has('actor_snapshot')) {
                return;
            }

            $causer = $activity->causer;

            if ($causer instanceof User) {
                $activity->properties = $properties->put('actor_snapshot', [
                    'name' => $causer->name,
                    'email' => $causer->email,
                ]);
            }
        });
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
