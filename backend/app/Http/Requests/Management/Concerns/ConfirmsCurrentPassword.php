<?php

namespace App\Http\Requests\Management\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Log;

trait ConfirmsCurrentPassword
{
    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has('current_password')) {
            Log::channel('security')->warning('Password re-confirmation failed', [
                'user_id' => $this->user()?->id,
                'ip' => $this->ip(),
            ]);
        }

        parent::failedValidation($validator);
    }
}
