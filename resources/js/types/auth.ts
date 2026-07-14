export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    office_id: number | null;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    /**
     * Flat permission-string list for the current user (e.g.
     * "equipment.delete"), sourced from App\Enums\Permission via
     * HandleInertiaRequests. Build a usePermissions() composable around
     * this — never re-derive authorization logic client-side.
     */
    permissions: string[];
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
