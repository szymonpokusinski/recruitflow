<script setup lang="ts">
import { computed } from 'vue';
import { audits } from '../composables/useManagement.ts';
import { formatDateTime } from '../domain/recruitment.ts';
const props = defineProps<{ entityId?: string; offerKey?: string }>();
const rows = computed(() =>
    audits.value.filter(
        (a) =>
            a.entity_id === props.entityId ||
            (!!props.offerKey && a.offer_key === props.offerKey),
    ),
);
</script>
<template>
    <q-expansion-item label="Dziennik zmian i korekt" icon="fact_check"
        ><q-list
            ><q-item v-for="audit in rows" :key="audit.id"
                ><q-item-section
                    ><q-item-label>{{ audit.action }}</q-item-label
                    ><q-item-label caption
                        >{{ formatDateTime(audit.created_at) }} ·
                        {{ audit.actor }}</q-item-label
                    >
                    <pre class="text-preserve" style="font-size: 12px">{{
                        JSON.stringify(audit.changes, null, 2)
                    }}</pre>
                </q-item-section></q-item
            ></q-list
        >
        <p v-if="!rows.length" class="muted q-pa-md">
            Brak lokalnych zmian.
        </p></q-expansion-item
    >
</template>
