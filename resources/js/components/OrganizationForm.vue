<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import type { OrganizationDetails } from '@/types/organization';

const props = defineProps<{
    organization?: OrganizationDetails;
}>();

const form = useForm({
    name: props.organization?.name ?? '',
    industry: props.organization?.industry ?? '',
    employee_count: props.organization?.employee_count ?? null,
    address: props.organization?.address ?? '',
    contact_name: props.organization?.contact_name ?? '',
    contact_email: props.organization?.contact_email ?? '',
    contact_phone: props.organization?.contact_phone ?? '',
    notes: props.organization?.notes ?? '',
});

function submit(): void {
    if (props.organization) {
        form.put(`/organizations/${props.organization.id}`);
        return;
    }

    form.post('/organizations');
}
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="grid gap-6 md:grid-cols-2">
            <label class="block md:col-span-2">
                <span class="text-sm font-medium text-slate-200"
                    >Firmenname</span
                >
                <input
                    v-model="form.name"
                    required
                    maxlength="255"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.name"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.name }}</span
                >
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-200">Branche</span>
                <input
                    v-model="form.industry"
                    maxlength="255"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.industry"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.industry }}</span
                >
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-200"
                    >Mitarbeitende</span
                >
                <input
                    v-model="form.employee_count"
                    type="number"
                    min="0"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.employee_count"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.employee_count }}</span
                >
            </label>

            <label class="block md:col-span-2">
                <span class="text-sm font-medium text-slate-200">Adresse</span>
                <textarea
                    v-model="form.address"
                    rows="3"
                    maxlength="500"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.address"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.address }}</span
                >
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-200"
                    >Ansprechperson</span
                >
                <input
                    v-model="form.contact_name"
                    maxlength="255"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.contact_name"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.contact_name }}</span
                >
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-200">E-Mail</span>
                <input
                    v-model="form.contact_email"
                    type="email"
                    maxlength="255"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.contact_email"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.contact_email }}</span
                >
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-200">Telefon</span>
                <input
                    v-model="form.contact_phone"
                    maxlength="100"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.contact_phone"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.contact_phone }}</span
                >
            </label>

            <label class="block md:col-span-2">
                <span class="text-sm font-medium text-slate-200"
                    >Interne Notizen</span
                >
                <textarea
                    v-model="form.notes"
                    rows="5"
                    maxlength="10000"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.notes"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.notes }}</span
                >
            </label>
        </div>

        <button
            type="submit"
            :disabled="form.processing"
            class="rounded-lg bg-cyan-500 px-5 py-2.5 font-semibold text-slate-950 disabled:opacity-50"
        >
            {{ props.organization ? 'Änderungen speichern' : 'Kunde anlegen' }}
        </button>
    </form>
</template>
