<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
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
import { usePermissions } from '@/composables/usePermissions';
import equipment from '@/routes/equipment';
import type {
    EquipmentTransaction,
    EquipmentTransactionFormData,
    EquipmentTransactionFormOptions,
} from '@/types/equipment';
import type { LookupOption } from '@/types/lookup';

const props = defineProps<{
    equipmentId: number;
    transactions: EquipmentTransaction[];
    transactionOptions: EquipmentTransactionFormOptions;
    personnelOptions: LookupOption[];
}>();

const { can } = usePermissions();

const form = useForm<EquipmentTransactionFormData>({
    transaction_type: '',
    accountable_officer_id: '',
    end_user_id: '',
    received_by_id: '',
    date_assigned_accountable_officer: '',
    date_assigned_end_user: '',
    date_received_new_accountable: '',
    supporting_documents1: '',
    or_si_dr_iar_no: '',
    supporting_documents2: '',
    par_ics_rrsp_rs_wmr_no: '',
});

function submit() {
    form.post(equipment.transactions.store.url(props.equipmentId), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function formatDate(value: string | null): string {
    return value ?? '—';
}
</script>

<template>
    <div class="space-y-8">
        <div v-if="can('equipment.transactions.create')" class="rounded-lg border p-4 sm:p-6">
            <h3 class="text-base font-medium">Log a transaction</h3>
            <p class="mb-4 text-sm text-muted-foreground">
                Every change of accountability or end user is recorded as an
                append-only transaction. Log one now to assign or update the
                accountable officer and/or end user for this item.
            </p>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="transaction_type">Transaction type</Label>
                        <Select v-model="form.transaction_type">
                            <SelectTrigger id="transaction_type" class="w-full">
                                <SelectValue placeholder="Select a transaction type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opt in transactionOptions.transaction_type"
                                    :key="opt.value"
                                    :value="opt.value"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.transaction_type" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="accountable_officer_id">Accountable officer</Label>
                        <Select v-model="form.accountable_officer_id">
                            <SelectTrigger id="accountable_officer_id" class="w-full">
                                <SelectValue placeholder="Select personnel" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opt in personnelOptions"
                                    :key="opt.value"
                                    :value="String(opt.value)"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.accountable_officer_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="date_assigned_accountable_officer">Date assigned</Label>
                        <Input
                            id="date_assigned_accountable_officer"
                            v-model="form.date_assigned_accountable_officer"
                            type="date"
                        />
                        <InputError :message="form.errors.date_assigned_accountable_officer" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="end_user_id">End user</Label>
                        <Select v-model="form.end_user_id">
                            <SelectTrigger id="end_user_id" class="w-full">
                                <SelectValue placeholder="Select personnel" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opt in personnelOptions"
                                    :key="opt.value"
                                    :value="String(opt.value)"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.end_user_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="date_assigned_end_user">Date assigned</Label>
                        <Input
                            id="date_assigned_end_user"
                            v-model="form.date_assigned_end_user"
                            type="date"
                        />
                        <InputError :message="form.errors.date_assigned_end_user" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="received_by_id">Received by</Label>
                        <Select v-model="form.received_by_id">
                            <SelectTrigger id="received_by_id" class="w-full">
                                <SelectValue placeholder="Select personnel" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opt in personnelOptions"
                                    :key="opt.value"
                                    :value="String(opt.value)"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.received_by_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="date_received_new_accountable">Date received</Label>
                        <Input
                            id="date_received_new_accountable"
                            v-model="form.date_received_new_accountable"
                            type="date"
                        />
                        <InputError :message="form.errors.date_received_new_accountable" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="supporting_documents1">Supporting document 1</Label>
                        <Select v-model="form.supporting_documents1">
                            <SelectTrigger id="supporting_documents1" class="w-full">
                                <SelectValue placeholder="Select a document type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opt in transactionOptions.supporting_document_type"
                                    :key="opt.value"
                                    :value="opt.value"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.supporting_documents1" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="or_si_dr_iar_no">OR/SI/DR/IAR no.</Label>
                        <Input id="or_si_dr_iar_no" v-model="form.or_si_dr_iar_no" />
                        <InputError :message="form.errors.or_si_dr_iar_no" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="supporting_documents2">Supporting document 2</Label>
                        <Select v-model="form.supporting_documents2">
                            <SelectTrigger id="supporting_documents2" class="w-full">
                                <SelectValue placeholder="Select a document type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opt in transactionOptions.supporting_document_type"
                                    :key="opt.value"
                                    :value="opt.value"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.supporting_documents2" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="par_ics_rrsp_rs_wmr_no">PAR/ICS/RRSP/RS/WMR no.</Label>
                        <Input id="par_ics_rrsp_rs_wmr_no" v-model="form.par_ics_rrsp_rs_wmr_no" />
                        <InputError :message="form.errors.par_ics_rrsp_rs_wmr_no" />
                    </div>
                </div>

                <Button type="submit" :disabled="form.processing" data-test="log-transaction-button">
                    <Spinner v-if="form.processing" />
                    Log transaction
                </Button>
            </form>
        </div>

        <div>
            <h3 class="mb-3 text-base font-medium">Transaction history</h3>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50 text-xs uppercase text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Type</th>
                            <th scope="col" class="px-4 py-3 font-medium">Accountable officer</th>
                            <th scope="col" class="px-4 py-3 font-medium">End user</th>
                            <th scope="col" class="px-4 py-3 font-medium">Received by</th>
                            <th scope="col" class="px-4 py-3 font-medium">Logged</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-if="transactions.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">
                                No transactions logged yet.
                            </td>
                        </tr>
                        <tr v-for="transaction in transactions" :key="transaction.id">
                            <td class="px-4 py-3 font-medium">{{ transaction.transaction_type }}</td>
                            <td class="px-4 py-3">
                                {{ transaction.accountable_officer?.full_name ?? '—' }}
                                <div class="text-xs text-muted-foreground">
                                    {{ formatDate(transaction.date_assigned_accountable_officer) }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                {{ transaction.end_user?.full_name ?? '—' }}
                                <div class="text-xs text-muted-foreground">
                                    {{ formatDate(transaction.date_assigned_end_user) }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                {{ transaction.received_by?.full_name ?? '—' }}
                                <div class="text-xs text-muted-foreground">
                                    {{ formatDate(transaction.date_received_new_accountable) }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">
                                {{ transaction.created_at ? new Date(transaction.created_at).toLocaleString() : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
