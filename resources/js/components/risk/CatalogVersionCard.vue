<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import RiskFormFeedback from './RiskFormFeedback.vue';
import type { CatalogEntry, CatalogVersion } from '@/types/risk';
const props = defineProps<{
    version: CatalogVersion;
    canPublish: boolean;
    versionPage: number;
}>();
const base = `/threat-catalogs/${props.version.id}`;
const clone = useForm({});
const publish = useForm({});
const draft = useForm<CatalogEntry>({
    code: '',
    title: '',
    description: '',
    confidentiality: false,
    integrity: false,
    availability: true,
    sort_order: 0,
    source_edition: '',
    source_reference: '',
});
function edit(entry: CatalogEntry): void {
    if (draft.isDirty || draft.processing || publish.processing) return;
    Object.assign(draft, entry);
    draft.defaults({ ...entry });
    draft.clearErrors();
}
function publishSaved(): void {
    if (draft.isDirty || draft.processing || publish.processing) return;
    publish.post(`${base}/publish`, { preserveScroll: true });
}
</script>
<template>
    <article class="my-6 rounded border border-slate-700 p-5">
        <h2 class="text-xl font-semibold">
            {{ version.name }} · Version {{ version.version }}
        </h2>
        <p>
            {{ version.status }} ·
            {{ version.publishedAt ?? 'Nicht veröffentlicht' }} ·
            {{ version.threatCount }} Gefährdungen
        </p>
        <p>{{ version.description }}</p>
        <nav aria-label="Katalogeinträge" class="flex gap-4">
            <Link
                v-if="version.entryPages.current > 1"
                :href="`/threat-catalogs?version_page=${versionPage}&entry_page=${version.entryPages.current - 1}`"
                preserve-state
                preserve-scroll
                >Vorherige Einträge</Link
            ><span
                >Eintragsseite {{ version.entryPages.current }} von
                {{ version.entryPages.last }}</span
            ><Link
                v-if="version.entryPages.current < version.entryPages.last"
                :href="`/threat-catalogs?version_page=${versionPage}&entry_page=${version.entryPages.current + 1}`"
                preserve-state
                preserve-scroll
                >Weitere Einträge</Link
            >
        </nav>
        <p>
            Quelle: {{ version.sourceEdition }} · {{ version.sourceReference }}
        </p>
        <details>
            <summary>Gefährdungen anzeigen</summary>
            <table class="my-4 w-full text-left">
                <thead>
                    <tr>
                        <th scope="col">Code</th>
                        <th scope="col">Gefährdung</th>
                        <th scope="col">Beschreibung</th>
                        <th scope="col">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in version.entries" :key="entry.code">
                        <td>{{ entry.code }}</td>
                        <td>{{ entry.title }}</td>
                        <td>{{ entry.description }}</td>
                        <td>
                            <button
                                v-if="canPublish && version.status === 'draft'"
                                type="button"
                                :disabled="
                                    draft.isDirty ||
                                    draft.processing ||
                                    publish.processing
                                "
                                @click="edit(entry)"
                            >
                                Bearbeiten
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </details>
        <button
            v-if="canPublish && version.status === 'published'"
            type="button"
            :disabled="clone.processing"
            @click="clone.post(`${base}/clone`, { preserveScroll: true })"
        >
            Als Entwurf klonen</button
        ><RiskFormFeedback :errors="clone.errors" />
        <template v-if="canPublish && version.status === 'draft'"
            ><form
                @submit.prevent="
                    draft.put(`${base}/draft`, {
                        preserveScroll: true,
                        onSuccess: () => draft.defaults(),
                    })
                "
            >
                <fieldset
                    :disabled="draft.processing || publish.processing"
                    class="my-4 grid gap-3"
                >
                    <legend>Entwurfseintrag ergänzen oder ändern</legend>
                    <label
                        >Code
                        <input
                            v-model="draft.code"
                            required
                            maxlength="32" /></label
                    ><label
                        >Titel
                        <input
                            v-model="draft.title"
                            required
                            maxlength="255" /></label
                    ><label
                        >Beschreibung
                        <textarea
                            v-model="draft.description"
                            required
                            maxlength="10000"
                        /></label
                    ><label
                        ><input
                            v-model="draft.confidentiality"
                            type="checkbox"
                        />
                        Vertraulichkeit</label
                    ><label
                        ><input v-model="draft.integrity" type="checkbox" />
                        Integrität</label
                    ><label
                        ><input v-model="draft.availability" type="checkbox" />
                        Verfügbarkeit</label
                    ><label
                        >Reihenfolge
                        <input
                            v-model.number="draft.sort_order"
                            type="number"
                            min="0"
                            max="10000" /></label
                    ><label
                        >Quellenausgabe
                        <input
                            v-model="draft.source_edition"
                            maxlength="255" /></label
                    ><label
                        >Quellenreferenz
                        <input
                            v-model="draft.source_reference"
                            maxlength="1000" /></label
                    ><button type="submit">Eintrag speichern</button>
                </fieldset>
                <RiskFormFeedback
                    :errors="draft.errors"
                    :success="draft.recentlySuccessful"
                />
            </form>
            <button
                type="button"
                :disabled="
                    publish.processing || draft.processing || draft.isDirty
                "
                @click="publishSaved"
            >
                Gespeicherten Katalog veröffentlichen
            </button>
            <p v-if="draft.isDirty">
                Änderungen vor Veröffentlichung oder Eintragswechsel speichern.
            </p>
            <RiskFormFeedback :errors="publish.errors"
        /></template>
    </article>
</template>
