<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { management, openDialog } from '../composables/useManagement.ts';
import { useRecruitment } from '../composables/useRecruitment.ts';
import type { ApplicationRow, ApplicationStage } from '../types/recruitment.ts';
import AiMatchScore from './AiMatchScore.vue';
import { formatDate } from '../domain/recruitment.ts';
const props = defineProps<{ rows: ApplicationRow[] }>();
const { offers, changeStatus } = useRecruitment();
const dragged = ref<string | null>(null);
const saving = ref(false);
async function drop(stage: string): Promise<void> {
    const row = props.rows.find((a) => a.id === dragged.value);
    dragged.value = null;
    if (!row || row.status === stage || saving.value) return;
    saving.value = true;
    try {
        await changeStatus(row.id, stage as ApplicationStage);
    } finally {
        saving.value = false;
    }
}
const linked = (row: ApplicationRow) =>
    offers.value.find((o) => o.id === row.offerId);
</script>
<template>
    <div class="kanban" aria-label="Kanban aplikacji">
        <section
            v-for="stage in management?.stages"
            :key="stage.value"
            class="kanban-column"
            @dragover.prevent
            @drop.prevent="drop(stage.value)"
        >
            <h3>
                {{ stage.label }}
                <q-badge color="grey">{{
                    rows.filter((a) => a.status === stage.value).length
                }}</q-badge>
            </h3>
            <article
                v-for="row in rows.filter((a) => a.status === stage.value)"
                :key="row.id"
                class="panel kanban-card"
                draggable="true"
                @dragstart="dragged = row.id"
                @dragend="dragged = null"
            >
                <Link
                    :href="`/applications/${row.id}`"
                    class="table-main-link"
                    >{{ row.offerTitle || 'Do uzupełnienia' }}</Link
                >
                <p>{{ row.company }}</p>
                <small class="muted"
                    >{{ linked(row)?.industry || 'Branża niepodana' }} ·
                    {{ stage.label }}</small
                ><AiMatchScore
                    :score="row.aiMatchScore"
                    compact
                    class="q-my-sm"
                />
                <div class="muted">
                    CV: {{ formatDate(row.cvSentAt ?? row.appliedAt) }}
                </div>
                <div v-if="linked(row)?.nextAction">
                    {{ linked(row)?.nextAction }}
                </div>
                <div v-if="row.nextStep">
                    Spotkanie: {{ formatDate(row.nextStep) }}
                </div>
                <div v-else-if="linked(row)?.nextActionAt">
                    Działanie:
                    {{ formatDate(linked(row)?.nextActionAt ?? null) }}
                </div>
                <q-btn
                    flat
                    dense
                    label="Zmień etap"
                    :disable="saving"
                    @click="openDialog('stage', linked(row) ?? null, row)"
                />
            </article>
        </section>
    </div>
</template>
