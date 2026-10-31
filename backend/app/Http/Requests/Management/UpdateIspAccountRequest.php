<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\RestrictsScopedAssignments;
use App\Http\Requests\Management\Concerns\ValidatesIspAccountFields;
use App\Models\IspAccount;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateIspAccountRequest extends FormRequest
{
    use RestrictsScopedAssignments, ValidatesIspAccountFields;

    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->ispAccount());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->ispAccountRules(),
            'office_id' => ['nullable', 'integer', $this->activeOfficeRule($this->ispAccount()->office_id)],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->lockForScopedActors(['office_id' => [$this->ispAccount()->office_id]]),
        ];
    }

    private function ispAccount(): IspAccount
    {
        $ispAccount = $this->route('ispAccount');

        return $ispAccount instanceof IspAccount ? $ispAccount : throw new NotFoundHttpException;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->ispAccountAttributes();
    }
}
