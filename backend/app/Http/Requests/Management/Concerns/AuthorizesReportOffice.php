<?php

declare(strict_types=1);

namespace App\Http\Requests\Management\Concerns;

use App\Enums\Permission;
use App\Models\Office;
use App\Models\User;
use Illuminate\Auth\Access\Response;

trait AuthorizesReportOffice
{
    protected function authorizeReportOffice(Permission $permission): Response
    {
        /** @var User $user */
        $user = $this->user();
        $office = $this->route('office');

        if (! $user->hasPermissionTo($permission)) {
            return Response::deny();
        }

        return $office instanceof Office && $user->reachesOffice($office->id) ? Response::allow() : Response::denyAsNotFound();
    }

    protected function optionalString(string $key): ?string
    {
        return $this->filled($key) ? $this->string($key)->trim()->toString() : null;
    }
}
