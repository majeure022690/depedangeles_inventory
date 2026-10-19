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
import ispAccounts from '@/routes/isp-accounts';
import type {
    IspSpeedTest,
    IspSpeedTestFormData,
    IspSpeedTestFormOptions,
} from '@/types/isp-account';

const props = defineProps<{
    ispAccountId: number;
    speedTests: IspSpeedTest[];
    speedTestOptions: IspSpeedTestFormOptions;
}>();

const { can } = usePermissions();

const form = useForm<IspSpeedTestFormData>({
    download_speed: '',
    upload_speed: '',
    ping: '',
    tested_at: '',
    signal_quality: '',
    rate_isp_service: '',
});

function submit() {
    form.post(ispAccounts.speedTests.store.url(props.ispAccountId), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}
</script>

<template>
    <div class="space-y-8">
        <div v-if="can('isp_accounts.edit')" class="rounded-lg border p-4 sm:p-6">
            <h3 class="text-base font-medium">Log a speed test</h3>
            <p class="mb-4 text-sm text-muted-foreground">
                Record a connection speed test result for this account. Every
                test is kept as an append-only history entry.
            </p>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="download_speed">Download speed (Mbps)</Label>
                        <Input
                            id="download_speed"
                            v-model="form.download_speed"
                            type="number"
                            step="0.01"
                            min="0"
                        />
                        <InputError :message="form.errors.download_speed" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="upload_speed">Upload speed (Mbps)</Label>
                        <Input
                            id="upload_speed"
                            v-model="form.upload_speed"
                            type="number"
                            step="0.01"
                            min="0"
                        />
                        <InputError :message="form.errors.upload_speed" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ping">Ping (ms)</Label>
                        <Input id="ping" v-model="form.ping" type="number" min="0" />
                        <InputError :message="form.errors.ping" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="tested_at">Tested at</Label>
                        <Input id="tested_at" v-model="form.tested_at" type="datetime-local" />
                        <InputError :message="form.errors.tested_at" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="signal_quality">Signal quality</Label>
                        <Select v-model="form.signal_quality">
                            <SelectTrigger id="signal_quality" class="w-full">
                                <SelectValue placeholder="Select a signal quality" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opt in speedTestOptions.signal_quality"
                                    :key="opt.value"
                                    :value="opt.value"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.signal_quality" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="speed_test_rate_isp_service">ISP service rating (1–5)</Label>
                        <Input
                            id="speed_test_rate_isp_service"
                            v-model="form.rate_isp_service"
                            type="number"
                            min="1"
                            max="5"
                        />
                        <InputError :message="form.errors.rate_isp_service" />
                    </div>
                </div>

                <Button type="submit" :disabled="form.processing" data-test="log-speed-test-button">
                    <Spinner v-if="form.processing" />
                    Log speed test
                </Button>
            </form>
        </div>

        <div>
            <h3 class="mb-3 text-base font-medium">Speed test history</h3>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50 text-xs uppercase text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Download</th>
                            <th scope="col" class="px-4 py-3 font-medium">Upload</th>
                            <th scope="col" class="px-4 py-3 font-medium">Ping</th>
                            <th scope="col" class="px-4 py-3 font-medium">Signal quality</th>
                            <th scope="col" class="px-4 py-3 font-medium">Tested at</th>
                            <th scope="col" class="px-4 py-3 font-medium">Logged</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-if="speedTests.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                                No speed tests logged yet.
                            </td>
                        </tr>
                        <tr v-for="test in speedTests" :key="test.id">
                            <td class="px-4 py-3">{{ test.download_speed }} Mbps</td>
                            <td class="px-4 py-3">{{ test.upload_speed }} Mbps</td>
                            <td class="px-4 py-3">{{ test.ping ?? '—' }}</td>
                            <td class="px-4 py-3">{{ test.signal_quality }}</td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">
                                {{ formatDateTime(test.tested_at) }}
                            </td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">
                                {{ formatDateTime(test.created_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
