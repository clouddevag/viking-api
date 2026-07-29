<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared list-endpoint behaviour: sorting, pagination and simple equality
 * filters, all driven from allow-lists so a query string can never reach an
 * arbitrary column.
 */
trait HandlesApiQueries
{
    /**
     * Applies `?sort=` with a leading `-` for descending, e.g. `?sort=-created_at`.
     *
     * @param  array<int, string>  $allowed
     */
    protected function applySorting(
        Builder $query,
        Request $request,
        array $allowed,
        string $default = '-created_at',
    ): Builder {
        $sort = (string) $request->query('sort', $default);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-+');

        if (! in_array($column, $allowed, true)) {
            $column = ltrim($default, '-+');
            $direction = str_starts_with($default, '-') ? 'desc' : 'asc';
        }

        return $query->orderBy($column, $direction);
    }

    /**
     * Applies `?filter[column]=value` for allow-listed columns. An array value
     * becomes a WHERE IN, so `?filter[status][]=ready&filter[status][]=served`
     * works without extra endpoint code.
     *
     * @param  array<int, string>  $allowed
     */
    protected function applyFilters(Builder $query, Request $request, array $allowed): Builder
    {
        $filters = $request->query('filter');

        if (! is_array($filters)) {
            return $query;
        }

        foreach ($filters as $column => $value) {
            if (! in_array($column, $allowed, true) || $value === '' || $value === null) {
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($column, array_values($value));

                continue;
            }

            // Accept the JSON booleans a client naturally sends.
            if (in_array($value, ['true', 'false'], true)) {
                $query->where($column, $value === 'true');

                continue;
            }

            $query->where($column, $value);
        }

        return $query;
    }

    /**
     * Applies `?from=` / `?to=` against a date column, inclusive of both ends.
     */
    protected function applyDateRange(Builder $query, Request $request, string $column): Builder
    {
        if ($from = $request->query('from')) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate($column, '<=', $to);
        }

        return $query;
    }

    /** Per-page size, clamped so a client cannot request the whole table. */
    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        return max(1, min($max, (int) $request->query('per_page', (string) $default)));
    }
}
