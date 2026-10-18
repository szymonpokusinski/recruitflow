<script setup lang="ts">
import { useRecruitment } from '../composables/useRecruitment.ts';
import { Head } from '@inertiajs/vue3';
import { useActivity } from '../composables/useActivity.ts';
import PageHeader from '../components/PageHeader.vue';
import ActivityTimeline from '../components/ActivityTimeline.vue';
import EmptyState from '../components/EmptyState.vue';
const { state } = useRecruitment();
const { filters, actors, kinds, rows, clear } = useActivity();
</script>
<template>
    <Head title="Aktywność HR" />
    <PageHeader
        title="Aktywność HR"
        subtitle="Zdarzenia HR powiązane z ofertami i aplikacjami."
    />
    <section class="panel">
        <div class="filter-toolbar">
            <q-input
                v-model="filters.search"
                outlined
                dense
                placeholder="Oferta, rodzaj, treść zdarzenia…"
                aria-label="Szukaj aktywności HR"
                class="search-field"
                ><template #prepend><q-icon name="search" /></template
            ></q-input>
            <q-select
                v-model="filters.actor"
                :options="actors"
                clearable
                outlined
                dense
                label="Źródło / osoba"
                class="filter-select"
            />
            <q-select
                v-model="filters.kind"
                :options="kinds"
                emit-value
                map-options
                clearable
                outlined
                dense
                label="Rodzaj zdarzenia"
                class="filter-select"
            />
            <q-btn
                flat
                dense
                icon="filter_alt_off"
                aria-label="Wyczyść filtry aktywności"
                @click="clear"
            />
        </div>
        <div class="result-count">
            {{ rows.length }} zdarzeń
            <span
                >· Najnowsze najpierw ·
                {{
                    state.mode === 'local' ? 'SQLite' : 'Cache Google Sheets'
                }}</span
            >
        </div>
        <div class="detail-content">
            <ActivityTimeline
                v-if="rows.length"
                :activities="rows"
                show-candidate
            /><EmptyState v-else @clear="clear" />
        </div>
    </section>
</template>
