<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { OfficeFormData } from '@/types/office';

defineProps<{
    form: InertiaForm<OfficeFormData>;
}>();
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-2 items-start">
        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4 lg:col-span-2">
            <legend class="px-1 text-base font-medium">Identification</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="office_name">Office name</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="office_name" v-model="form.office_name" required />
                    <InputError :message="form.errors.office_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="office_type">Office type</Label>
                    <!--
                        Plain text input, not a fixed-vocabulary <Select>: the
                        seeded source data (offices.json) and Office's own
                        doc-comment confirm office_type has no closed
                        vocabulary ("Elementary School", "High School",
                        "Division Office", "Unit", or blank), and
                        OfficeStoreRequest/UpdateRequest validate it as a
                        plain nullable string rather than Rule::in(), unlike
                        the Tier 2 lookup fields elsewhere in this app.
                    -->
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="office_type" v-model="form.office_type" placeholder="e.g. Elementary School" />
                    <InputError :message="form.errors.office_type" />
                </div>
                <div class="grid gap-2">
                    <Label for="school_id">School ID</Label>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input
                        id="school_id"
                        v-model="form.school_id"
                        type="number"
                        min="0"
                        placeholder="DepEd-assigned School ID, if applicable"
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <InputError :message="form.errors.school_id" />
                </div>
                <div class="flex items-end gap-2 pb-2">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="is_active" v-model="form.is_active" />
                    <Label for="is_active">Active</Label>
                </div>
                <InputError :message="form.errors.is_active" />
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Location</legend>
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="address">Address</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="address" v-model="form.address" />
                    <InputError :message="form.errors.address" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="region">Region</Label>
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <Input id="region" v-model="form.region" />
                        <InputError :message="form.errors.region" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="division">Division</Label>
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <Input id="division" v-model="form.division" />
                        <InputError :message="form.errors.division" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="district">District</Label>
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <Input id="district" v-model="form.district" />
                        <InputError :message="form.errors.district" />
                    </div>
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Contact</legend>
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="contact_number">Contact number</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="contact_number" v-model="form.contact_number" />
                    <InputError :message="form.errors.contact_number" />
                </div>
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="email" v-model="form.email" type="email" />
                    <InputError :message="form.errors.email" />
                </div>
            </div>
        </fieldset>
    </div>
</template>
