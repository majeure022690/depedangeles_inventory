<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\RestrictsScopedAssignments;
use App\Http\Requests\Management\Concerns\ValidatesGspIctProfileFields;
use App\Models\GspIctProfile;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * The office of an existing profile is fixed; create a new profile for another office.
 */
class UpdateGspIctProfileRequest extends FormRequest
{
    use RestrictsScopedAssignments, ValidatesGspIctProfileFields;

    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->profile());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->gspIctProfileRules($this->profile()->office_id, $this->profile()->id);
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [$this->requireOfficeIspAccounts($this->profile()->office_id)];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->gspIctProfileAttributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->gspIctProfileMessages();
    }

    private function profile(): GspIctProfile
    {
        /** @var GspIctProfile */
        return $this->route('gspIctProfile');
    }
}
