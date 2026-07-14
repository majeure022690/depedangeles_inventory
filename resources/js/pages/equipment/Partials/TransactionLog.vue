<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FieldHint from '@/components/FieldHint.vue';
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
                        <div class="flex items-center gap-1.5">
                            <Label for="transaction_type">Transaction type</Label>
                            <FieldHint
                                for="transaction_type"
                                label="Transaction type"
                                text="Select the appropriate Transaction Type from the dropdown list. For the initial transaction, choose 'Beginning Inventory.' For subsequent transactions, including movements, transfers, or updates, select the Transaction Type that corresponds to the specific activity."
                            />
                        </div>
                        <Select v-model="form.transaction_type">
                            <SelectTrigger id="transaction_type" class="w-full" aria-describedby="transaction_type-hint">
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
                        <div class="flex items-center gap-1.5">
                            <Label for="accountable_officer_id">Accountable officer</Label>
                            <FieldHint
                                for="accountable_officer_id"
                                label="Accountable officer"
                                text="Choose the employee accountable for the equipment from the dropdown."
                            />
                        </div>
                        <Select v-model="form.accountable_officer_id">
                            <SelectTrigger id="accountable_officer_id" class="w-full" aria-describedby="accountable_officer_id-hint">
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
                        <div class="flex items-center gap-1.5">
                            <Label for="date_assigned_accountable_officer">Date assigned</Label>
                            <FieldHint
                                for="date_assigned_accountable_officer"
                                label="Date assigned (accountable officer)"
                                text="Enter the date when the equipment was received by the Accountable Officer in the 'MM/DD/YYYY' format."
                            />
                        </div>
                        <Input
                            id="date_assigned_accountable_officer"
                            v-model="form.date_assigned_accountable_officer"
                            type="date"
                            aria-describedby="date_assigned_accountable_officer-hint"
                        />
                        <InputError :message="form.errors.date_assigned_accountable_officer" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center gap-1.5">
                            <Label for="end_user_id">End user</Label>
                            <FieldHint
                                for="end_user_id"
                                label="End user"
                                text="Select the Custodian or End User from the dropdown to whom the equipment is assigned."
                            />
                        </div>
                        <Select v-model="form.end_user_id">
                            <SelectTrigger id="end_user_id" class="w-full" aria-describedby="end_user_id-hint">
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
                        <div class="flex items-center gap-1.5">
                            <Label for="date_assigned_end_user">Date assigned</Label>
                            <FieldHint
                                for="date_assigned_end_user"
                                label="Date assigned (end user)"
                                text="Enter the date when the equipment was received by the Custodian/End User in the 'MM/DD/YYYY' format."
                            />
                        </div>
                        <Input
                            id="date_assigned_end_user"
                            v-model="form.date_assigned_end_user"
                            type="date"
                            aria-describedby="date_assigned_end_user-hint"
                        />
                        <InputError :message="form.errors.date_assigned_end_user" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center gap-1.5">
                            <Label for="received_by_id">Received by</Label>
                            <FieldHint
                                for="received_by_id"
                                label="Received by"
                                text="Choose the new Accountable Officer, Custodian, or End User from the dropdown for the newly assigned equipment."
                            />
                        </div>
                        <Select v-model="form.received_by_id">
                            <SelectTrigger id="received_by_id" class="w-full" aria-describedby="received_by_id-hint">
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
                        <div class="flex items-center gap-1.5">
                            <Label for="date_received_new_accountable">Date received</Label>
                            <FieldHint
                                for="date_received_new_accountable"
                                label="Date received"
                                text="Enter the date when the new Accountable Officer, Custodian, or End User received the equipment in the 'MM/DD/YYYY' format."
                            />
                        </div>
                        <Input
                            id="date_received_new_accountable"
                            v-model="form.date_received_new_accountable"
                            type="date"
                            aria-describedby="date_received_new_accountable-hint"
                        />
                        <InputError :message="form.errors.date_received_new_accountable" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center gap-1.5">
                            <Label for="supporting_documents1">Supporting document 1</Label>
                            <FieldHint
                                for="supporting_documents1"
                                label="Supporting document 1"
                                text="Select the appropriate document type from the dropdown to identify the transaction. This typically pertains to deliveries, inspection reports, and the initial setup of beginning inventory."
                            />
                        </div>
                        <Select v-model="form.supporting_documents1">
                            <SelectTrigger id="supporting_documents1" class="w-full" aria-describedby="supporting_documents1-hint">
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
                        <div class="flex items-center gap-1.5">
                            <Label for="or_si_dr_iar_no">OR/SI/DR/IAR no.</Label>
                            <FieldHint
                                for="or_si_dr_iar_no"
                                label="OR/SI/DR/IAR no."
                                text="Enter the document number for the transaction (e.g., delivery, inspection, or transfer) to ensure proper tracking and identification of the equipment."
                            />
                        </div>
                        <Input id="or_si_dr_iar_no" v-model="form.or_si_dr_iar_no" aria-describedby="or_si_dr_iar_no-hint" />
                        <InputError :message="form.errors.or_si_dr_iar_no" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center gap-1.5">
                            <Label for="supporting_documents2">Supporting document 2</Label>
                            <FieldHint
                                for="supporting_documents2"
                                label="Supporting document 2"
                                text="Select the appropriate document type from the dropdown to identify the transaction. This typically applies to Issuances, Transfers, Returns, Stock Reports, or Waste Material."
                            />
                        </div>
                        <Select v-model="form.supporting_documents2">
                            <SelectTrigger id="supporting_documents2" class="w-full" aria-describedby="supporting_documents2-hint">
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
                        <div class="flex items-center gap-1.5">
                            <Label for="par_ics_rrsp_rs_wmr_no">PAR/ICS/RRSP/RS/WMR no.</Label>
                            <FieldHint
                                for="par_ics_rrsp_rs_wmr_no"
                                label="PAR/ICS/RRSP/RS/WMR no."
                                text="Input the document number to track and properly identify the transaction."
                            />
                        </div>
                        <Input id="par_ics_rrsp_rs_wmr_no" v-model="form.par_ics_rrsp_rs_wmr_no" aria-describedby="par_ics_rrsp_rs_wmr_no-hint" />
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
