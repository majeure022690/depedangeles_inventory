<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { HardDrive, ShieldCheck, Users, Wifi } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard, login } from '@/routes';
import { register } from '@/routes';

const features = [
    {
        icon: HardDrive,
        title: 'ICT Equipment',
        description: 'Track assets, accountability, and condition across every office.',
    },
    {
        icon: Users,
        title: 'Personnel',
        description: 'Keep personnel records and assignments organized and current.',
    },
    {
        icon: Wifi,
        title: 'Connectivity',
        description: 'Monitor ISP accounts and internet connectivity across sites.',
    },
    {
        icon: ShieldCheck,
        title: 'Access control',
        description: 'Role-based permissions keep sensitive records in the right hands.',
    },
];
</script>

<template>
    <Head title="Welcome" />
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="w-full">
            <nav class="mx-auto flex max-w-5xl items-center justify-between px-6 py-6">
                <div class="flex items-center gap-3">
                    <AppLogoIcon class="size-9" />
                    <span class="font-semibold">DICTIS</span>
                </div>
                <div class="flex items-center gap-4 text-sm">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                        class="rounded-md border border-input px-4 py-1.5 hover:bg-muted"
                    >
                        Dashboard
                    </Link>
                    <template v-else>
                        <Link :href="login()" class="rounded-md px-4 py-1.5 hover:bg-muted">
                            Log in
                        </Link>
                        <Link
                            :href="register()"
                            class="rounded-md border border-input px-4 py-1.5 hover:bg-muted"
                        >
                            Register
                        </Link>
                    </template>
                </div>
            </nav>
        </header>

        <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col items-center justify-center px-6 py-16 text-center">
            <AppLogoIcon class="mb-6 size-20" />
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                DepEd Division ICT Inventory System
            </h1>
            <p class="mt-3 max-w-2xl text-muted-foreground">
                Schools Division Office of Angeles City — a single system of record for ICT
                equipment, personnel, and internet connectivity across the division.
            </p>

            <div class="mt-8 flex items-center gap-4">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboard()"
                    class="rounded-md bg-primary px-6 py-2.5 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                >
                    Go to dashboard
                </Link>
                <Link
                    v-else
                    :href="login()"
                    class="rounded-md bg-primary px-6 py-2.5 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                >
                    Log in
                </Link>
            </div>

            <div class="mt-16 grid w-full gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card v-for="feature in features" :key="feature.title" class="text-left">
                    <CardHeader class="gap-2">
                        <component :is="feature.icon" class="size-5 text-muted-foreground" />
                        <CardTitle class="text-base">{{ feature.title }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        {{ feature.description }}
                    </CardContent>
                </Card>
            </div>
        </main>

        <footer class="w-full px-6 py-6 text-center text-xs text-muted-foreground">
            Schools Division Office of Angeles City — Information and Communications Technology Unit
        </footer>
    </div>
</template>
