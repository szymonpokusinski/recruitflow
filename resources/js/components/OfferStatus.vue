<script setup lang="ts">
import { computed } from 'vue';
import { offerStatuses } from '../domain/recruitment.ts';
import type { OfferStatus } from '../types/recruitment.ts';
const props = defineProps<{ status: OfferStatus; rawStatus?: string }>();
const item = computed(() =>
    offerStatuses.find((item) => item.value === props.status),
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
