<script setup lang="ts">
import { useRecruitment } from '../composables/useRecruitment.ts';
import { Head } from '@inertiajs/vue3';
import { useApplications } from '../composables/useApplications.ts';
import { applicationStages } from '../domain/recruitment.ts';
import PageHeader from '../components/PageHeader.vue';
import ApplicationTable from '../components/ApplicationTable.vue';
import { ref } from 'vue';
import ApplicationKanban from '../components/ApplicationKanban.vue';
const view = ref('table');
const { state } = useRecruitment();
const { filters, offerOptions, rows, total, clear } = useApplications();
</script>
<template>
    <Head title="Aplikacje" />
    <PageHeader
        title="Aplikacje"
        subtitle="Powiązane oferty i bieżący etap aplikacji."
        ><q-btn-toggle
            v-model="view"
            :options="[
                { label: 'Tabela', value: 'table' },
                { label: 'Kanban', value: 'kanban' },
            ]"
            toggle-color="primary"
    /></PageHeader>
    <section class="panel">
        <div class="filter-toolbar">
            <q-input
                v-model="filters.search"
                outlined
                dense
                placeholder="Oferta, firma, identyfikator…"
                aria-label="Szukaj aplikacji"
                class="search-field"
                ><template #prepend><q-icon name="search" /></template
            ></q-input>
            <q-select
                v-model="filters.status"
                :options="applicationStages"
                emit-value
                map-options
                clearable
                outlined
                dense
                label="Etap"
                class="filter-select"
            />
            <q-select
                v-model="filters.offerId"
                :options="offerOptions"
                emit-value
                map-options
                clearable
                outlined
                dense
                label="Oferta"
                class="filter-select"
            />
            <q-btn
                flat
                dense
                icon="filter_alt_off"
                aria-label="Wyczyść filtry aplikacji"
                @click="clear"
                ><q-tooltip>Wyczyść filtry</q-tooltip></q-btn
            >
        </div>
        <div class="result-count" aria-live="polite">
            {{ rows.length }} z {{ total }} aplikacji
            <span
                >·
                {{
                    state.mode === 'local'
                        ? 'SQLite · tryb lokalny'
                        : 'SQLite · Google Sheets'
                }}</span
            >
        </div>
        <ApplicationKanban
            v-if="view === 'kanban'"
            :rows="rows"
        /><ApplicationTable v-else :rows="rows" @clear="clear" />
    </section>
</template>
