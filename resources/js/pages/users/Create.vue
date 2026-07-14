<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import CheckboxGroupField from '@/components/CheckboxGroupField.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import users from '@/routes/users';
import type { OfficeOption, RoleOption } from '@/types/users';

const props = defineProps<{
    roles: RoleOption[];
    offices: OfficeOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: users.index() },
            { title: 'Add user', href: users.create() },
        ],
    },
});

const roleOptions = props.roles.map((role) => ({ value: role.id, label: role.label }));

const form = useForm<{
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    office_id: number | null;
    role_ids: number[];
}>({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    office_id: null,
    role_ids: [],
});

/** Reka's Select forbids an empty-string/null item value, so a clearable
 * dropdown needs a distinct sentinel translated back to null. */
const NONE = '__none__';

const officeModel = computed<number | typeof NONE>({
    get: () => form.office_id ?? NONE,
    set: (value: number | typeof NONE) => {
        form.office_id = value === NONE ? null : Number(value);
    },
});

function submit() {
    form.post(users.store.url());
}
</script>

<template>
    <Head title="Add user" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Add user"
            description="Provision a new account directly instead of waiting on self-registration."
        />

        <form class="space-y-8" @submit.prevent="submit">
            <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
                <legend class="px-1 text-base font-medium">Account details</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <Input id="name" v-model="form.name" required autocomplete="name" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="email">Email</Label>
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <Input id="email" v-model="form.email" type="email" required autocomplete="email" />
                        <InputError :message="form.errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="password">Password</Label>
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <PasswordInput id="password" v-model="form.password" required autocomplete="new-password" />
                        <InputError :message="form.errors.password" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="password_confirmation">Confirm password</Label>
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <PasswordInput
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            required
                            autocomplete="new-password"
                        />
                        <InputError :message="form.errors.password_confirmation" />
                    </div>
                </div>
            </fieldset>

            <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
                <legend class="px-1 text-base font-medium">School / Office</legend>
                <div class="grid gap-2 sm:max-w-sm">
                    <Label for="office_id">School / office</Label>
                    <Select v-model="officeModel">
                        <SelectTrigger id="office_id" class="w-full">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">None</SelectItem>
                            <SelectItem
                                v-for="opt in offices"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.office_id" />
                </div>
            </fieldset>

            <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
                <legend class="px-1 text-base font-medium">Roles</legend>
                <CheckboxGroupField
                    :form="form"
                    field="role_ids"
                    :options="roleOptions"
                    id-prefix="role"
                />
                <InputError :message="form.errors.role_ids" />
            </fieldset>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing" data-test="save-user-button">
                    <Spinner v-if="form.processing" />
                    Save user
                </Button>
                <Link :href="users.index()">
                    <Button type="button" variant="secondary">Cancel</Button>
                </Link>
            </div>
        </form>
    </div>
</template>
