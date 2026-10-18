<script setup lang="ts">
import { computed } from 'vue';
import { aiScorePresentation } from '../domain/aiMatching.ts';
const props = withDefaults(
    defineProps<{
        score?: number | null;
        compact?: boolean;
        showLevel?: boolean;
    }>(),
    { compact: false, showLevel: true },
);
const presentation = computed(() => aiScorePresentation(props.score));
</script>

<template>
    <div
        class="ai-match-score"
        :class="{ 'ai-match-score--compact': compact }"
        :aria-label="
            presentation.score === null
                ? presentation.label
                : `${presentation.formatted} · ${presentation.label}`
        "
    >
        <span v-if="presentation.score === null" class="muted">Brak oceny</span>
        <template v-else>
            <div class="ai-score-caption">
                <strong>{{ presentation.formatted }}</strong>
                <span v-if="showLevel" class="muted">{{
                    presentation.label
                }}</span>
            </div>
            <q-linear-progress
                :value="presentation.score / 100"
                :color="presentation.color"
                rounded
                size="4px"
                aria-hidden="true"
            />
        </template>
    </div>
</template>
