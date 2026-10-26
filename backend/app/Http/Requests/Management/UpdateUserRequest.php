<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\Permission;
use App\Http\Requests\Management\Concerns\RestrictsScopedAssignments;
use App\Models\Personnel;
use App\Models\User;
use App\Services\UserRoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    use RestrictsScopedAssignments;

    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && $this->user()->can('update', $target);
    }

    /**
     * `role_id` replaces the user's role (a user holds exactly one role).
     * No password field: a user who lost theirs uses the forgot-password flow.
     * Name and email are managed by PRIME and cannot be edited here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->route('user');
        $currentOffice = $target instanceof User ? $target->office_id : null;

        return [
            'office_id' => ['nullable', 'integer', $this->activeOfficeRule($currentOffice)],
            'is_active' => ['sometimes', 'boolean'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'personnel_id' => [
                'sometimes', 'nullable', 'integer',
                ...($this->mayLinkPersonnel() ? [Rule::exists('personnel', 'id')->withoutTrashed()] : []),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $target = $this->route('user');

        $validator->after(function (Validator $validator) {
            if ($this->isSelfEdit() && $this->has('is_active') && ! $this->boolean('is_active')) {
                $validator->errors()->add('is_active', __("You can't deactivate your own account."));
            }
        });

        $validator->after($this->lockForScopedActors([
            'office_id' => [$this->user()->office_id, $target instanceof User ? $target->office_id : null],
        ]));

        $validator->after(function (Validator $validator) use ($target) {
            if ($validator->errors()->has('personnel_id') || ! $this->exists('personnel_id')) {
                return;
            }

            if (! $this->mayLinkPersonnel()) {
                $validator->errors()->add('personnel_id', __('Only users with access to all records can change this.'));

                return;
            }

            if (! $this->filled('personnel_id')) {
                return;
            }

            $linkedUserId = Personnel::query()->whereKey($this->integer('personnel_id'))->value('user_id');

            if ($linkedUserId !== null && (! $target instanceof User || (int) $linkedUserId !== $target->id)) {
                $validator->errors()->add('personnel_id', __('This personnel record is already linked to another user.'));
            }
        });
    }

    private function mayLinkPersonnel(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        return $target instanceof User
            && $actor->hasPermissionTo(Permission::UsersEdit)
            && $actor->hasPermissionTo(Permission::PersonnelView)
            && $this->actorHasAllScope()
            && app(UserRoleService::class)->canManage($actor, $target);
    }

    private function isSelfEdit(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && $this->user()->is($target);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...(new StoreUserRequest)->attributes(), 'personnel_id' => 'personnel record'];
    }
}
