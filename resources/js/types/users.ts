export type UserRoleSummary = {
    id: number;
    name: string;
    label: string;
};

export type UserOfficeSummary = {
    id: number;
    name: string;
    school_id: number | null;
};

export type UserListItem = {
    id: number;
    name: string;
    email: string;
    office: UserOfficeSummary | null;
    roles: UserRoleSummary[];
    role_ids: number[];
    is_self: boolean;
    created_at: string | null;
    updated_at: string | null;
};

export type RoleOption = {
    id: number;
    name: string;
    label: string;
};

export type OfficeOption = {
    value: number;
    label: string;
};

export type UserFilters = {
    search: string | null;
    role: string | null;
};
