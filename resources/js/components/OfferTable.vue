<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import type { QTableColumn } from 'quasar';
import type { OfferRow } from '../domain/offerInbox.ts';
import { applicationStatePresentation, compareOfferAddedAt, offerAddedAt } from '../domain/offerInbox.ts';
import { formatSalary } from '../domain/recruitment.ts';
import { preferences } from '../composables/usePreferences.ts';
import OfferStatus from './OfferStatus.vue';
import EmptyState from './EmptyState.vue';
import AiMatchScore from './AiMatchScore.vue';
import { compareAiScores } from '../domain/aiMatching.ts';
import OfferActions from './OfferActions.vue';
const props = defineProps<{ rows: OfferRow[]; pending?: boolean; loading?: boolean }>();
defineEmits<{ clear: [] }>();
const pagination = ref({
    sortBy: 'createdAt',
    descending: true,
    page: 1,
    rowsPerPage: 10,
});
const sortOptions = [
    { label: 'Najnowsze oferty', value: 'newest', column: 'createdAt', descending: true },
    { label: 'Najlepsze dopasowanie AI', value: 'match', column: 'aiMatchScore', descending: true },
    { label: 'Firma A–Z', value: 'company', column: 'company', descending: false },
    { label: 'Stanowisko A–Z', value: 'title', column: 'title', descending: false },
];
const selectedSort = computed(() => sortOptions.find(option => option.column === pagination.value.sortBy
    && option.descending === pagination.value.descending)?.value ?? null);
function changeSort(value: string): void {
    const option = sortOptions.find(item => item.value === value);
    if (option) Object.assign(pagination.value, { sortBy: option.column, descending: option.descending, page: 1 });
}
watch(() => props.rows.map(row => row.id).join(','), () => { pagination.value.page = 1; });
const columns: QTableColumn<OfferRow>[] = [
    {
        name: 'title',
        label: 'Stanowisko',
        field: 'title',
        align: 'left',
        sortable: true,
    },
    { name: 'applicationState', label: 'Aplikowanie', field: 'applicationState', align: 'left' },
    { name: 'company', label: 'Firma', field: 'company', align: 'left', sortable: true },
    { name: 'industry', label: 'Branża', field: 'industry', align: 'left', sortable: true },
    { name: 'myInterest', label: 'Zainteresowanie', field: 'myInterest', align: 'left', sortable: true },
    { name: 'applicationStage', label: 'Etap rekrutacji', field: 'applicationStage', align: 'left', sortable: true },
    {
        name: 'aiMatchScore',
        label: 'Dopasowanie AI',
        field: 'aiMatchScore',
        align: 'left',
        sortable: true,
        rawSort: compareAiScores,
    },
    {
        name: 'location',
        label: 'Lokalizacja',
        field: 'location',
        align: 'left',
        sortable: true,
    },
    {
        name: 'salary',
        label: 'Wynagrodzenie',
        field: 'salaryMin',
        align: 'left',
        sortable: true,
    },
    { name: 'platform', label: 'Źródło ogłoszenia', field: 'platform', align: 'left', sortable: true },
    { name: 'createdAt', label: 'Znaleziono / dodano', field: offerAddedAt, align: 'left', sortable: true,
        rawSort: (_a, _b, a, b) => compareOfferAddedAt(a, b) },
    {
        name: 'applications',
        label: 'Aplikacje',
        field: 'applicationCount',
        align: 'right',
        sortable: true,
    },
    {
        name: 'status',
        label: 'Status',
        field: 'status',
        align: 'left',
        sortable: true,
    },
    {
        name: 'owner',
        label: 'Opiekun',
        field: 'owner',
        align: 'left',
        sortable: true,
    },
    { name: 'actions', label: 'Akcje', field: 'id', align: 'right' },
];
function formatDate(value: string | null | undefined): string {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
}
function openRow(_event: Event, row: OfferRow): void {
    router.visit(`/offers/${row.id}`);
}
</script>
<template>
    <q-table
        class="data-table"
        flat
        :rows="rows"
        :columns="columns"
        row-key="id"
        :dense="preferences.compactRows"
        :loading="loading"
        v-model:pagination="pagination"
        :rows-per-page-options="[5, 10, 25]"
        @row-click="openRow"
    >
        <template #top>
            <div class="offer-table-heading">{{ pending ? 'Oferty do aplikowania' : 'Lista ofert' }}</div>
            <q-space />
            <q-select :model-value="selectedSort" :display-value="sortOptions.find(option => option.value === selectedSort)?.label ?? 'Sortowanie z nagłówka tabeli'"
                :options="sortOptions" emit-value map-options outlined dense label="Sortuj oferty" class="offer-sort-select" @update:model-value="changeSort" />
        </template>
        <template #body-cell-title="props"
            ><q-td :props="props"
                ><Link
                    :href="`/offers/${props.row.id}`"
                    class="table-main-link"
                    @click.stop
                    >{{ props.row.title || 'Do uzupełnienia' }}</Link
                ><span class="table-secondary">{{
                    props.row.company
                }}</span></q-td
            ></template
        >
        <template #body-cell-aiMatchScore="props">
            <q-td :props="props"
                ><AiMatchScore :score="props.row.aiMatchScore" compact
            /></q-td>
        </template>
        <template #body-cell-applicationState="props">
            <q-td :props="props">
                <q-badge outline :color="applicationStatePresentation[props.row.applicationState as keyof typeof applicationStatePresentation].color">
                    {{ applicationStatePresentation[props.row.applicationState as keyof typeof applicationStatePresentation].label }}
                </q-badge>
                <q-tooltip v-if="props.row.applicationState === 'unknown'">Brak jednoznacznych danych o wysłaniu CV. Sprawdź szczegóły oferty.</q-tooltip>
            </q-td>
        </template>
        <template #body-cell-location="props"
            ><q-td :props="props"
                >{{ props.row.location
                }}<span class="table-secondary">{{
                    props.row.workMode
                }}</span></q-td
            ></template
        >
        <template #body-cell-salary="props"
            ><q-td :props="props"
                >{{ formatSalary(props.row)
                }}<span class="table-secondary">{{
                    props.row.employment
                }}</span></q-td
            ></template
        >
        <template #body-cell-status="props"
            ><q-td :props="props"
                ><OfferStatus
                    :status="props.row.status"
                    :raw-status="props.row.rawStatus" /></q-td
        ></template>
        <template #body-cell-createdAt="props"><q-td :props="props">{{ formatDate(offerAddedAt(props.row)) }}</q-td></template>
        <template #body-cell-actions="props"><q-td :props="props"><OfferActions :offer="props.row" compact/></q-td></template>
        <template #no-data><EmptyState :title="pending ? 'Brak ofert do aplikowania dla tych filtrów' : 'Brak wyników'"
            :description="pending ? 'Wyczyść filtry lub sprawdź zakładkę Wszystkie. Wysłane zgłoszenia znajdziesz w Aplikacjach.' : undefined" @clear="$emit('clear')" /></template>
    </q-table>
</template>
