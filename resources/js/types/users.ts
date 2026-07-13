export type UserRoleSummary = {
    id: number;
    name: string;
    label: string;
};

export type UserListItem = {
    id: number;
    name: string;
    email: string;
    roles: UserRoleSummary[];
    role_ids: number[];
    is_self: boolean;
};

export type RoleOption = {
    id: number;
    name: string;
    label: string;
};

export type UserFilters = {
    search: string | null;
    role: string | null;
};
