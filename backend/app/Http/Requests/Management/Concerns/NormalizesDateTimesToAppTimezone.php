<?php

declare(strict_types=1);

namespace App\Http\Requests\Management\Concerns;

use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Datetime columns store app-timezone (Asia/Manila) wall-clock time, but
 * the SPA sends UTC ISO-8601 (`...Z`). Eloquent's datetime cast formats a
 * Carbon in its own timezone on save, so an offset-bearing input must be
 * converted before it reaches the model.
 */
trait NormalizesDateTimesToAppTimezone
{
    protected function normalizeDateTimesToAppTimezone(string ...$keys): void
    {
        $normalized = [];

        foreach ($keys as $key) {
            $value = $this->input($key);

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                $normalized[$key] = Date::parse($value)
                    ->setTimezone(config('app.timezone'))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable) {
                // Leave unparseable input for the `date` rule to reject.
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
