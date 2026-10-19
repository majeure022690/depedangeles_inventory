export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

/**
 * Mirrors Laravel's default LengthAwarePaginator JSON shape, as returned
 * directly from an Inertia prop (no API resource wrapping).
 */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
    first_page_url: string | null;
    last_page_url: string | null;
    prev_page_url: string | null;
    next_page_url: string | null;
    path: string;
};
