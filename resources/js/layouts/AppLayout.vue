<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { AuthProps } from '@/types/auth';

const page = usePage<AuthProps>();
const user = computed(() => page.props.auth.user);

function logout(): void {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-slate-800 bg-slate-900/80">
            <div
                class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4"
            >
                <div class="flex items-center gap-8">
                    <div>
                        <p
                            class="text-xs font-semibold tracking-[0.22em] text-slate-400 uppercase"
                        >
                            ISMS Builder
                        </p>
                        <p v-if="user" class="mt-1 text-sm text-slate-300">
                            {{ user.name }} · {{ user.role }}
                        </p>
                    </div>
                    <nav class="hidden items-center gap-5 text-sm md:flex">
                        <Link
                            href="/dashboard"
                            class="text-slate-300 hover:text-white"
                            >Dashboard</Link
                        >
                        <Link
                            href="/organizations"
                            class="text-slate-300 hover:text-white"
                            >Kunden</Link
                        >
                    </nav>
                </div>
                <button
                    type="button"
                    class="rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium hover:bg-slate-800"
                    @click="logout"
                >
                    Abmelden
                </button>
            </div>
        </header>
        <main class="mx-auto max-w-7xl px-6 py-10">
            <slot />
        </main>
    </div>
</template>
