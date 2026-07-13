<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { usePermissions } from '@/composables/usePermissions';
import ispAccounts from '@/routes/isp-accounts';
import type { IspSubscriptionCost, IspSubscriptionCostFormData } from '@/types/isp-account';

const props = defineProps<{
    ispAccountId: number;
    subscriptionCosts: IspSubscriptionCost[];
}>();

const { can } = usePermissions();

const form = useForm<IspSubscriptionCostFormData>({
    contract_start_date: '',
    contract_end_date: '',
    total_amount_spent: '',
    contract_projected_start_date: '',
    contract_projected_end_date: '',
    total_projected_expenditure: '',
});

function submit() {
    form.post(ispAccounts.subscriptionCosts.store.url(props.ispAccountId), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

const currencyFormatter = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
});

function formatCurrency(value: number | null): string {
    return value !== null ? currencyFormatter.format(value) : '—';
}

function formatDate(value: string | null): string {
    return value ?? '—';
}
</script>

<template>
    <div class="space-y-8">
        <div v-if="can('isp_accounts.edit')" class="rounded-lg border p-4 sm:p-6">
            <h3 class="text-base font-medium">Log a subscription cost</h3>
            <p class="mb-4 text-sm text-muted-foreground">
                Record actual and/or projected spending for a budget period.
                A period can be logged with only actuals, only projections,
                or a mix of both.
            </p>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="contract_start_date">Contract start date</Label>
                        <Input id="contract_start_date" v-model="form.contract_start_date" type="date" />
                        <InputError :message="form.errors.contract_start_date" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="contract_end_date">Contract end date</Label>
                        <Input id="contract_end_date" v-model="form.contract_end_date" type="date" />
                        <InputError :message="form.errors.contract_end_date" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="total_amount_spent">Total amount spent</Label>
                        <Input
                            id="total_amount_spent"
                            v-model="form.total_amount_spent"
                            type="number"
                            step="0.01"
                            min="0"
                        />
                        <InputError :message="form.errors.total_amount_spent" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="contract_projected_start_date">Projected start date</Label>
                        <Input
                            id="contract_projected_start_date"
                            v-model="form.contract_projected_start_date"
                            type="date"
                        />
                        <InputError :message="form.errors.contract_projected_start_date" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="contract_projected_end_date">Projected end date</Label>
                        <Input
                            id="contract_projected_end_date"
                            v-model="form.contract_projected_end_date"
                            type="date"
                        />
                        <InputError :message="form.errors.contract_projected_end_date" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="total_projected_expenditure">Total projected expenditure</Label>
                        <Input
                            id="total_projected_expenditure"
                            v-model="form.total_projected_expenditure"
                            type="number"
                            step="0.01"
                            min="0"
                        />
                        <InputError :message="form.errors.total_projected_expenditure" />
                    </div>
                </div>

                <Button type="submit" :disabled="form.processing" data-test="log-subscription-cost-button">
                    <Spinner v-if="form.processing" />
                    Log subscription cost
                </Button>
            </form>
        </div>

        <div>
            <h3 class="mb-3 text-base font-medium">Subscription cost history</h3>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50 text-xs uppercase text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Contract period</th>
                            <th scope="col" class="px-4 py-3 font-medium">Amount spent</th>
                            <th scope="col" class="px-4 py-3 font-medium">Projected period</th>
                            <th scope="col" class="px-4 py-3 font-medium">Projected expenditure</th>
                            <th scope="col" class="px-4 py-3 font-medium">Logged</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-if="subscriptionCosts.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">
                                No subscription costs logged yet.
                            </td>
                        </tr>
                        <tr v-for="cost in subscriptionCosts" :key="cost.id">
                            <td class="px-4 py-3 text-xs text-muted-foreground">
                                {{ formatDate(cost.contract_start_date) }} to {{ formatDate(cost.contract_end_date) }}
                            </td>
                            <td class="px-4 py-3">{{ formatCurrency(cost.total_amount_spent) }}</td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">
                                {{ formatDate(cost.contract_projected_start_date) }} to
                                {{ formatDate(cost.contract_projected_end_date) }}
                            </td>
                            <td class="px-4 py-3">{{ formatCurrency(cost.total_projected_expenditure) }}</td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">
                                {{ cost.created_at ? new Date(cost.created_at).toLocaleString() : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
