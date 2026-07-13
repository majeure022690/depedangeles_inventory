import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

type FilterValue = string | number | boolean | null | undefined;

/**
 * Drives a paginated index page's search/filter bar by pushing changes to
 * the server via `router.get` with `preserveState`, matching the pattern
 * used by both personnel/Index and equipment/Index. Search-like fields are
 * debounced so we don't fire a request per keystroke; filter dropdowns
 * apply immediately.
 */
export function useTableFilters<T extends Record<string, FilterValue>>(
    routeUrl: string,
    initial: T,
    options?: {
        debounceMs?: number;
        debounceKeys?: (keyof T)[];
    },
) {
    const filters = reactive({ ...initial }) as T;
    const debounceMs = options?.debounceMs ?? 350;
    const debounceKeys = new Set(options?.debounceKeys ?? []);
    let timeout: ReturnType<typeof setTimeout> | undefined;

    function apply() {
        const query: Record<string, string> = {};

        for (const [key, value] of Object.entries(filters)) {
            if (value !== null && value !== undefined && value !== '') {
                query[key] = String(value);
            }
        }

        router.get(routeUrl, query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function update<K extends keyof T>(key: K, value: T[K]) {
        filters[key] = value;

        if (debounceKeys.has(key)) {
            if (timeout) {
                clearTimeout(timeout);
            }

            timeout = setTimeout(apply, debounceMs);
        } else {
            apply();
        }
    }

    return { filters, update };
}
