<script setup lang="ts">
import { computed } from 'vue';
import { applicationStages } from '../domain/recruitment.ts';
import type { ApplicationStage } from '../types/recruitment.ts';
const props = defineProps<{ status: ApplicationStage; rawStatus?: string }>();
const item = computed(() =>
    applicationStages.find((item) => item.value === props.status),
);
</script>
<template>
    <span class="status-chip" :class="`status-${status}`"
        ><span class="status-point" />{{
            status === 'unknown' && rawStatus
                ? `Nieznany: ${rawStatus}`
                : (item?.label ?? status)
        }}</span
    >
</template>
