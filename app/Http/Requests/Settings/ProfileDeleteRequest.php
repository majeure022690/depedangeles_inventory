<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Enums\Permission;
use App\Models\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Deny self-deletion for any account holding `users.manage`.
     *
     * This is deliberately narrower than the "last remaining admin"
     * lockout guard a future user-management UI will need — there's no
     * admin UI yet to fall back on if we get that wrong, so the simple,
     * safe rule for today is: nobody who can grant/revoke roles removes
     * their own account through this self-service page at all. Two
     * reasons this matters right now, not just hypothetically:
     *
     * 1. An account able to manage users/roles is exactly the account
     *    whose disappearance can leave the system unadministered (no one
     *    left to approve 'pending' registrations or fix a bad role
     *    assignment).
     * 2. Self-deletion is the concrete way an admin's own audit trail
     *    attribution gets erased (`audit_logs.actor_id` is nullOnDelete)
     *    — see AuditLog::record()'s actor_snapshot, which mitigates that
     *    but doesn't make self-deleting admins a good idea.
     *
     * Non-admin accounts (viewer/encoder/pending) are unaffected — they
     * can still delete their own account exactly as the Starter Kit
     * always allowed.
     */
    public function authorize(): bool
    {
        return ! $this->user()->hasPermissionTo(Permission::UsersManage);
    }

    /**
     * Audit the blocked attempt (a privileged account trying to remove
     * itself is security-relevant even when denied — see the Auth
     * agent's decision rule to log failed authorization attempts on
     * sensitive actions) and surface a clear reason to the user, rather
     * than the framework's generic "This action is unauthorized."
     */
    protected function failedAuthorization()
    {
        AuditLog::record('account.self_delete_blocked', $this->user(), [
            'reason' => 'actor holds '.Permission::UsersManage->value,
        ]);

        throw new AuthorizationException(
            'Accounts with user-management permissions can\'t delete themselves from this page. '.
            'Ask another Administrator to remove this account if it\'s no longer needed.'
        );
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }
}
