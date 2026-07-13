export type RoleListItem = {
    id: number;
    name: string;
    label: string;
    description: string | null;
    user_count: number;
    permission_count: number;
    is_protected: boolean;
};

export type PermissionOption = {
    value: string;
    label: string;
};

/**
 * Shape of the `role` prop on roles/Edit.vue — RoleController::roleProps().
 * `permissions` is the flat list of permission-string names this role
 * currently grants, used to seed the edit form's checkbox state.
 */
export type RoleFormRole = {
    id: number;
    name: string;
    label: string;
    description: string | null;
    user_count: number;
    is_protected: boolean;
    permissions: string[];
};

/**
 * Shape of the reactive object handed to Inertia's useForm() on both
 * roles/Create.vue and roles/Edit.vue — mirrors RoleStoreRequest/
 * RoleUpdateRequest.
 */
export type RoleFormData = {
    name: string;
    label: string;
    description: string;
    permissions: string[];
};
