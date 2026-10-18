<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { ActivityRow } from '../types/recruitment.ts';
import { formatDateTime } from '../domain/recruitment.ts';
withDefaults(
    defineProps<{ activities: ActivityRow[]; showCandidate?: boolean }>(),
    { showCandidate: false },
);
const icons = {
    applied: 'person_add_alt',
    status: 'swap_horiz',
    note: 'notes',
    unknown: 'history',
};
</script>
<template>
    <ol class="activity-timeline" aria-label="Historia zdarzeń">
        <li v-for="activity in activities" :key="activity.id">
            <span class="timeline-icon"
                ><q-icon :name="icons[activity.kind]" size="17px"
            /></span>
            <div class="timeline-content">
                <Link
                    v-if="showCandidate"
                    :href="
                        activity.applicationId
                            ? `/applications/${activity.applicationId}`
                            : `/offers/${activity.offerId}`
                    "
                    class="table-main-link"
                    >{{ activity.candidateName }}</Link
                >
                <div v-if="activity.eventType" class="table-secondary">
                    {{ activity.eventType
                    }}<span v-if="activity.eventSubtype">
                        · {{ activity.eventSubtype }}</span
                    >
                </div>
                <p>{{ activity.message }}</p>
                <q-badge v-if="activity.requiresAction" color="orange"
                    >Wymaga działania</q-badge
                >
                <div class="timeline-meta">
                    {{ activity.actor || activity.source || '' }}<span>·</span
                    ><time :datetime="activity.occurredAt">{{
                        formatDateTime(activity.occurredAt)
                    }}</time>
                </div>
                <div
                    v-if="activity.channel || activity.direction"
                    class="table-secondary"
                >
                    {{
                        [activity.channel, activity.direction]
                            .filter(Boolean)
                            .join(' · ')
                    }}
                </div>
                <div v-if="activity.actionDeadlineAt" class="table-secondary">
                    Termin: {{ formatDateTime(activity.actionDeadlineAt) }}
                </div>
                <div v-if="activity.interviewAt" class="table-secondary">
                    Rozmowa: {{ formatDateTime(activity.interviewAt) }}
                </div>
                <span v-if="showCandidate" class="table-secondary">{{
                    activity.offerTitle
                }}</span>
            </div>
        </li>
    </ol>
    <div v-if="!activities.length" class="empty-state">
        <q-icon name="history" size="30px" />
        <p>Brak zdarzeń dla tego widoku.</p>
    </div>
</template>
