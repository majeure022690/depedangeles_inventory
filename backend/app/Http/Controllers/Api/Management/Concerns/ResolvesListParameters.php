<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management\Concerns;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * The paging/sorting query parameters every management index endpoint
 * shares (api-conventions: `per_page` default 20, clamped to 1..100;
 * `sort` + `direction`, sort keys whitelisted per endpoint, never passed
 * straight into orderBy()).
 */
trait ResolvesListParameters
{
    protected function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', 20), 100));
    }

    /**
     * Applies `?sort=<key>&direction=asc|desc` when `<key>` is one of the
     * whitelisted keys. Returns false (query untouched) otherwise, so the
     * caller can apply its default order.
     *
     * @param  array<string, string>  $sortable  public sort key => column
     */
    protected function applySort(Builder $query, Request $request, array $sortable): bool
    {
        $key = $request->string('sort')->toString();

        if (! array_key_exists($key, $sortable)) {
            return false;
        }

        $query->orderBy($sortable[$key], $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc');

        return true;
    }
}
