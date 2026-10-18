<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import type { QTableColumn } from 'quasar';
import type { ApplicationRow } from '../types/recruitment.ts';
import { formatDate } from '../domain/recruitment.ts';
import { preferences } from '../composables/usePreferences.ts';
import ApplicationStatus from './ApplicationStatus.vue';
import EmptyState from './EmptyState.vue';
import AiMatchScore from './AiMatchScore.vue';
import { compareAiScores } from '../domain/aiMatching.ts';
withDefaults(defineProps<{ rows: ApplicationRow[]; compact?: boolean }>(), {
    compact: false,
});
defineEmits<{ clear: [] }>();
const pagination = ref({
    sortBy: 'appliedAt',
    descending: true,
    page: 1,
    rowsPerPage: 10,
});
const columns: QTableColumn<ApplicationRow>[] = [
    {
        name: 'candidate',
        label: 'Aplikacja',
        field: 'displayName',
        align: 'left',
        sortable: true,
    },
    {
        name: 'offer',
        label: 'Oferta',
        field: 'offerTitle',
        align: 'left',
        sortable: true,
    },
    {
        name: 'aiMatchScore',
        label: 'Dopasowanie AI',
        field: 'aiMatchScore',
        align: 'left',
        sortable: true,
        rawSort: compareAiScores,
    },
    {
        name: 'status',
        label: 'Etap',
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
    {
        name: 'appliedAt',
        label: 'Data aplikacji',
        field: 'appliedAt',
        format: formatDate,
        align: 'left',
        sortable: true,
    },
];
function openRow(_event: Event, row: ApplicationRow): void {
    router.visit(`/applications/${row.id}`);
}
</script>
<template>
    <q-table
        flat
        class="data-table"
        :rows="rows"
        :columns="columns"
        row-key="id"
        :dense="preferences.compactRows"
        v-model:pagination="pagination"
        :hide-bottom="compact && rows.length <= 10"
        :rows-per-page-options="[5, 10, 25]"
        @row-click="openRow"
    >
        <template #body-cell-candidate="props"
            ><q-td :props="props"
                ><Link
                    :href="`/applications/${props.row.id}`"
                    class="table-main-link"
                    @click.stop
                    >{{ props.row.displayName }}</Link
                ><span class="table-secondary">{{
                    props.row.candidateRole
                }}</span></q-td
            ></template
        >
        <template #body-cell-offer="props"
            ><q-td :props="props"
                >{{ props.row.offerTitle
                }}<span class="table-secondary">{{
                    props.row.company
                }}</span></q-td
            ></template
        >
        <template #body-cell-aiMatchScore="props">
            <q-td :props="props"
                ><AiMatchScore :score="props.row.aiMatchScore" compact
            /></q-td>
        </template>
        <template #body-cell-status="props"
            ><q-td :props="props"
                ><ApplicationStatus
                    :status="props.row.status"
                    :raw-status="props.row.rawStatus" /></q-td
        ></template>
        <template #no-data><EmptyState @clear="$emit('clear')" /></template>
    </q-table>
</template>
