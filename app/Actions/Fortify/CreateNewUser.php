<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // Deny by default: this is an internal DepEd Division Office
        // system whose Personnel records are real employee PII (names,
        // mobile numbers, personal emails). Fortify's `verified`
        // middleware only proves the registrant controls the email
        // address they typed in — it is not vetting, so it must never be
        // treated as authorization. Every new self-registration starts
        // with ZERO permissions (the 'pending' role) until a Division
        // ICT Admin reviews the account and assigns a real role
        // (viewer/encoder/division-ict-admin). Silently skipped if the
        // role isn't seeded yet (e.g. a fresh install that hasn't run
        // the database seeders) — registration itself must never fail
        // because of it, though in that state the account also can't be
        // approved until seeding happens, which is the safe direction to
        // fail in.
        // Role::PENDING is the single source of truth for this role's
        // machine name — see its doc-comment for the invariants
        // RoleController/RoleService enforce to keep it always existing
        // and always zero-permission.
        if ($pendingRole = Role::where('name', Role::PENDING)->first()) {
            $user->assignRole($pendingRole);
        }

        return $user;
    }
}
