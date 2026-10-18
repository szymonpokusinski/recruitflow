<script setup lang="ts">
import { computed } from 'vue';
import type { Offer } from '../types/recruitment.ts';
import { formatAiEvaluationDate } from '../domain/aiMatching.ts';
import AiMatchScore from './AiMatchScore.vue';
import {useRecruitment} from '../composables/useRecruitment.ts';
const {state}=useRecruitment();
const props = defineProps<{ offer: Offer }>();
const scores = computed(() => [
    { label: 'Technologie', value: props.offer.aiStackScore },
    { label: 'Poziom stanowiska', value: props.offer.aiSeniorityScore },
    { label: 'Lokalizacja i tryb pracy', value: props.offer.aiLocationScore },
    { label: 'Wynagrodzenie', value: props.offer.aiSalaryScore },
]);
const explanations = computed(() => [
    { label: 'Argumenty za ofertą', value: props.offer.aiFitReasons },
    { label: 'Potencjalne problemy', value: props.offer.aiFitConcerns },
    { label: 'Rekomendacja AI', value: props.offer.aiRecommendation },
    { label: 'Sugestie do CV', value: props.offer.aiCvSuggestions },
]);
</script>

<template>
    <section id="ai-analysis" class="panel detail-content q-mt-lg">
        <h2>Analiza dopasowania AI</h2>
        <p class="muted q-mt-xs">
            Dopasowanie oferty do profilu wyszukiwania. Dane odczytane z
            arkusza; nie stanowią odrębnej analizy Twojego CV.
        </p>
        <p class="muted">Profil oceny: {{offer.aiProfileId ? state.data.profiles?.find(p=>p.id===offer.aiProfileId)?.name??'Profil źródłowy':'Ocena ogólna · profil nieprzypisany'}}</p>
        <div class="ai-analysis-overview q-mt-lg">
            <AiMatchScore :score="offer.aiMatchScore" />
            <div class="muted">
                <div>{{ formatAiEvaluationDate(offer.aiEvaluatedAt) }}</div>
                <div>
                    Pewność oceny:
                    {{ offer.aiEvaluationConfidence ?? 'Brak danych' }}
                </div>
            </div>
        </div>
        <div class="ai-analysis-scores q-mt-lg">
            <div v-for="item in scores" :key="item.label">
                <h3>{{ item.label }}</h3>
                <AiMatchScore :score="item.value" :show-level="false" compact />
            </div>
        </div>
        <div class="ai-analysis-text q-mt-lg">
            <div v-for="item in explanations" :key="item.label">
                <h3>{{ item.label }}</h3>
                <p v-if="item.value" class="ai-original-text">
                    {{ item.value }}
                </p>
                <p v-else class="muted">Brak danych w arkuszu.</p>
            </div>
        </div>
    </section>
</template>
