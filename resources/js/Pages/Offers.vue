<script setup lang="ts">
import { useRecruitment } from '../composables/useRecruitment.ts';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useOffers } from '../composables/useOffers.ts';
import { offerStatuses } from '../domain/recruitment.ts';
import PageHeader from '../components/PageHeader.vue';
import OfferTable from '../components/OfferTable.vue';
import { openDialog, management } from '../composables/useManagement.ts';
const { state } = useRecruitment();
const props = defineProps<{ widgetOfferIds?: string[] | null }>();
const {
    filters,
    companies,
    industries,
    categories,
    seniorities,
    workModes,
    employments,
    rows,
    counts,
    total,
    clear,
} = useOffers();
const displayedRows = computed(() => props.widgetOfferIds ? rows.value.filter(row => props.widgetOfferIds?.includes(row.id)) : rows.value);
</script>
<template>
    <Head title="Oferty" />
    <PageHeader
        title="Oferty"
        subtitle="Twoja skrzynka ofert do aplikowania. Wysłane zgłoszenia znajdziesz w Aplikacjach."
        ><Link href="/applications" class="text-link">Moje aplikacje<q-icon name="arrow_forward" /></Link><q-btn
            color="primary"
            unelevated
            icon="add"
            label="Dodaj ofertę"
            @click="openDialog('offer')"
    /></PageHeader>
    <section class="panel">
        <q-banner v-if="widgetOfferIds" class="q-mb-md">Nowe, dostępne oferty z ostatnich 24 godzin. <a href="/offers">Pokaż wszystkie oferty</a></q-banner>
        <q-tabs v-model="filters.applicationScope" align="left" active-color="primary" indicator-color="primary" no-caps class="offer-inbox-tabs">
            <q-tab name="pending" :label="`Do aplikowania (${counts.pending})`" />
            <q-tab name="applied" :label="`Aplikowano (${counts.applied})`" />
            <q-tab name="all" :label="`Wszystkie (${counts.all})`" />
        </q-tabs>
        <p class="offer-inbox-hint" v-if="filters.applicationScope === 'pending'">
            Oferty z wysłanym CV lub rozpoczętą rekrutacją są tutaj ukryte.
        </p>
        <div class="filter-toolbar">
            <q-input
                v-model="filters.search"
                outlined
                dense
                placeholder="Stanowisko, firma, lokalizacja…"
                aria-label="Szukaj ofert"
                class="search-field"
                ><template #prepend><q-icon name="search" /></template
            ></q-input>
            <q-select
                v-model="filters.status"
                :options="offerStatuses"
                emit-value
                map-options
                clearable
                outlined
                dense
                label="Status"
                class="filter-select"
            />
            <q-select
                v-model="filters.company"
                :options="companies"
                clearable
                outlined
                dense
                label="Firma"
                class="filter-select"
            />
            <q-input
                v-model="filters.minimumAiScore"
                type="number"
                min="0"
                max="100"
                step="1"
                clearable
                outlined
                dense
                label="Min. dopasowanie AI"
                class="filter-select"
                :rules="[
                    (value) =>
                        value === null ||
                        value === '' ||
                        (Number(value) >= 0 && Number(value) <= 100) ||
                        'Wpisz ocenę 0–100.',
                ]"
                hide-bottom-space
            />
            <q-btn
                flat
                dense
                icon="filter_alt_off"
                aria-label="Wyczyść filtry ofert"
                @click="clear"
                ><q-tooltip>Wyczyść filtry</q-tooltip></q-btn
            >
        </div>
        <q-expansion-item label="Więcej filtrów" icon="filter_alt"
            ><div class="form-grid q-pa-md">
                <q-select
                    v-model="filters.industry"
                    :options="industries"
                    outlined
                    dense
                    clearable
                    label="Branża"
                />
                <q-select
                    v-model="filters.jobCategory"
                    :options="categories"
                    outlined
                    dense
                    clearable
                    label="Kategoria zawodowa"
                />
                <q-select
                    v-model="filters.seniority"
                    :options="seniorities"
                    outlined
                    dense
                    clearable
                    label="Poziom doświadczenia"
                />
                <q-select
                    v-model="filters.workMode"
                    :options="workModes"
                    outlined
                    dense
                    clearable
                    label="Tryb pracy"
                />
                <q-input
                    v-model="filters.location"
                    outlined
                    dense
                    clearable
                    label="Lokalizacja"
                />
                <q-input
                    v-model="filters.salaryMin"
                    type="number"
                    outlined
                    dense
                    clearable
                    label="Minimalne wynagrodzenie (w walucie oferty)"
                />
                <q-select
                    v-model="filters.employment"
                    :options="employments"
                    outlined
                    dense
                    clearable
                    label="Rodzaj umowy"
                />
                <q-select
                    v-model="filters.stage"
                    :options="management?.stages.map((s) => s.label)"
                    outlined
                    dense
                    clearable
                    label="Etap rekrutacji"
                />
                <q-input
                    v-model="filters.technology"
                    outlined
                    dense
                    clearable
                    label="Umiejętność / technologia (opcjonalnie)"
                />
                <q-toggle
                    v-model="filters.favorite"
                    label="Tylko ulubione"
                /><q-toggle
                    v-model="filters.showHidden"
                    label="Pokaż ukryte"
                /><q-toggle
                    v-model="filters.showArchived"
                    label="Pokaż archiwalne"
                /></div
        ></q-expansion-item>
        <div class="result-count" aria-live="polite">
            {{ displayedRows.length }} z {{ total }} ofert
            <span
                >·
                {{
                    state.mode === 'local'
                        ? 'SQLite · tryb lokalny'
                        : 'SQLite · Google Sheets'
                }}</span
            >
        </div>
        <OfferTable :rows="displayedRows" :pending="filters.applicationScope === 'pending'" :loading="state.loading" @clear="clear" />
    </section>
</template>
