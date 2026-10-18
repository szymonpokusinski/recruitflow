<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import PageHeader from '../components/PageHeader.vue';
import {
    useRecruitment,
    loadRecruitment,
} from '../composables/useRecruitment.ts';
import { saveCommand, fieldErrors } from '../composables/useManagement.ts';
import { commandId } from '../services/recruitment.ts';
import { api } from '../services/http.ts';
import type { SearchProfile } from '../types/recruitment.ts';
const { state } = useRecruitment();
const profiles = computed(() => state.data.profiles ?? []);
const opened = ref(false);
const selected = ref<SearchProfile | null>(null);
const form = ref<Record<string, string | boolean | number | null>>({});
const errors = ref<Record<string, string[]>>({});
const error = ref('');
const saving = ref(false);
let operationId = '';
const fields: Record<string, string> = {
    industry: 'Branża',
    jobCategory: 'Kategoria zawodowa',
    keywords: 'Słowa kluczowe',
    excludedKeywords: 'Wykluczenia',
    seniority: 'Poziom doświadczenia',
    skills: 'Wymagane umiejętności',
    preferredSkills: 'Mile widziane umiejętności',
    location: 'Lokalizacja',
    workMode: 'Tryb pracy',
    employment: 'Rodzaj umowy',
    salaryMin: 'Wynagrodzenie minimalne',
    minAiScore: 'Minimalne dopasowanie AI',
};
function edit(profile: SearchProfile | null): void {
    selected.value = profile;
    operationId = commandId();
    errors.value = {};
    error.value = '';
    form.value = {
        name: profile?.name ?? '',
        enabled: profile?.enabled ?? true,
    };
    for (const key of Object.keys(fields)) {
        const v = (profile as unknown as Record<string, unknown> | null)?.[key];
        form.value[key] = Array.isArray(v)
            ? v.join('; ')
            : typeof v === 'string' || typeof v === 'number'
              ? v
              : '';
    }
    opened.value = true;
}
async function save(): Promise<void> {
    saving.value = true;
    try {
        const values: Record<string, unknown> = { ...form.value, operationId };
        for (const key of [
            'keywords',
            'excludedKeywords',
            'skills',
            'preferredSkills',
        ])
            values[key] = String(form.value[key] ?? '')
                .split(/[,;\n]/)
                .map((v) => v.trim())
                .filter(Boolean);
        for (const key of ['salaryMin', 'minAiScore'])
            if (values[key] === '') values[key] = null;
        await saveCommand(
            `/api/recruitment/profiles${selected.value ? '/' + selected.value.id : ''}`,
            values,
            selected.value ? 'PUT' : 'POST',
        );
        opened.value = false;
    } catch (e) {
        errors.value = fieldErrors(e);
        error.value = e instanceof Error ? e.message : 'Błąd zapisu.';
    } finally {
        saving.value = false;
    }
}
async function select(id: string | null): Promise<void> {
    await api('/api/recruitment/profile', 'PUT', { profileId: id });
    await loadRecruitment(true);
}
</script>
<template>
    <Head title="Profile wyszukiwania" /><PageHeader
        title="Profile wyszukiwania"
        subtitle="Kryteria dowolnego zawodu. Nowe profile i dodatkowe pola zapisujemy lokalnie."
        ><q-btn
            color="primary"
            label="Dodaj profil"
            icon="add"
            @click="edit(null)"
    /></PageHeader>
    <section class="panel detail-content">
        <q-select
            :model-value="state.data.activeProfileId ?? null"
            @update:model-value="select"
            :options="
                profiles
                    .filter((p) => p.enabled)
                    .map((p) => ({
                        label: p.name ?? p.externalKey,
                        value: p.id,
                    }))
            "
            emit-value
            map-options
            clearable
            outlined
            label="Aktywny profil (opcjonalnie)"
        />
        <p class="muted q-mt-md">
            Wynik AI bez przypisanego profilu zachowuje oznaczenie oceny
            ogólnej. Wybór profilu nie uruchamia płatnej analizy AI.
        </p>
        <q-list separator
            ><q-item v-for="profile in profiles" :key="profile.id"
                ><q-item-section
                    ><q-item-label>{{
                        profile.name ?? profile.externalKey
                    }}</q-item-label
                    ><q-item-label caption
                        >{{
                            profile.industry ??
                            profile.main_language ??
                            'Dowolna branża'
                        }}
                        ·
                        {{
                            profile.enabled ? 'Aktywny' : 'Wyłączony'
                        }}</q-item-label
                    ></q-item-section
                ><q-item-section side
                    ><q-btn
                        flat
                        label="Edytuj"
                        @click="edit(profile)" /></q-item-section></q-item
        ></q-list>
    </section>
    <q-dialog v-model="opened" persistent
        ><q-card style="width: 720px; max-width: 95vw"
            ><q-form @submit="save"
                ><q-card-section
                    ><h2>Profil wyszukiwania</h2>
                    <q-banner v-if="error" class="error-banner">{{
                        error
                    }}</q-banner
                    ><q-input
                        :model-value="form.name as string"
                        @update:model-value="(v) => (form.name = v)"
                        outlined
                        label="Nazwa profilu"
                        :error="!!errors.name"
                        :error-message="errors.name?.[0]" /><q-toggle
                        v-model="form.enabled"
                        label="Profil włączony" />
                    <div
                        class="form-grid"
                        style="max-height: 55vh; overflow: auto"
                    >
                        <q-input
                            v-for="(label, key) in fields"
                            :key="key"
                            :model-value="form[key] as string | number | null"
                            @update:model-value="(v) => (form[key] = v)"
                            outlined
                            dense
                            :label="label"
                            :type="
                                ['salaryMin', 'minAiScore'].includes(key)
                                    ? 'number'
                                    : 'text'
                            "
                            :error="!!errors[key]"
                            :error-message="errors[key]?.[0]"
                        /></div></q-card-section
                ><q-card-actions align="right"
                    ><q-btn
                        flat
                        label="Anuluj"
                        :disable="saving"
                        @click="opened = false" /><q-btn
                        type="submit"
                        color="primary"
                        label="Zapisz profil"
                        :loading="saving" /></q-card-actions></q-form></q-card
    ></q-dialog>
</template>
