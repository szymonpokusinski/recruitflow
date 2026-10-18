<script setup lang="ts">
import type { Offer } from '../types/recruitment.ts';
import { formatDate, formatSalary } from '../domain/recruitment.ts';
import OfferStatus from './OfferStatus.vue';
import AiMatchScore from './AiMatchScore.vue';
defineProps<{ offer: Offer }>();
</script>
<template>
    <div class="details-grid">
        <section class="panel detail-content">
            <h2>Opis stanowiska</h2>
            <p>{{ offer.description }}</p>
            <p v-if="offer.myNotes" class="q-mt-lg">{{ offer.myNotes }}</p>
            <h2 class="q-mt-xl">Wymagania</h2>
            <ul class="requirements">
                <li
                    v-for="requirement in offer.requirements"
                    :key="requirement"
                >
                    {{ requirement }}
                </li>
            </ul>
        </section>
        <aside class="panel detail-content">
            <h2>Informacje o ofercie</h2>
            <dl class="detail-list">
                <dt>Klucz oferty</dt>
                <dd>{{ offer.externalKey ?? offer.id }}</dd>
                <dt>Status</dt>
                <dd>
                    <OfferStatus
                        :status="offer.status"
                        :raw-status="offer.rawStatus"
                    />
                </dd>
                <template v-if="offer.schemaProfile === 'recruitflow'">
                    <dt>Status dostępności</dt>
                    <dd>{{ offer.effectiveStatus || '—' }}</dd>
                    <dt>Status portalu</dt>
                    <dd>{{ offer.portalStatus || '—' }}</dd>
                    <dt>Etap aplikacji</dt>
                    <dd>{{ offer.applicationStage || '—' }}</dd>
                    <dt>CV wysłane</dt>
                    <dd>
                        {{
                            offer.cvSent === null
                                ? '—'
                                : offer.cvSent
                                  ? 'Tak'
                                  : 'Nie'
                        }}
                    </dd>
                    <dt>Data wysłania CV</dt>
                    <dd>{{ formatDate(offer.cvSentAt ?? null) }}</dd>
                    <dt>Termin aplikowania</dt>
                    <dd>{{ formatDate(offer.applyDeadlineAt ?? null) }}</dd>
                    <dt>Poziom stanowiska</dt>
                    <dd>{{ offer.seniority || '—' }}</dd>
                    <dt>Ocena AI</dt>
                    <dd>
                        <AiMatchScore :score="offer.aiMatchScore" compact />
                    </dd>
                    <dt>Priorytet</dt>
                    <dd>{{ offer.priority || '—' }}</dd>
                    <dt>Okres wynagrodzenia</dt>
                    <dd>{{ offer.salaryPeriod || '—' }}</dd>
                    <dt v-if="offer.offerUrl">Ogłoszenie</dt>
                    <dd v-if="offer.offerUrl">
                        <a
                            v-if="/^https?:\/\//i.test(offer.offerUrl)"
                            :href="offer.offerUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-link"
                            >Otwórz ogłoszenie</a
                        >
                    </dd>
                </template>
                <dt>Firma</dt>
                <dd>{{ offer.company }}</dd>
                <dt>Lokalizacja</dt>
                <dd>{{ offer.location }} · {{ offer.workMode }}</dd>
                <dt>Wynagrodzenie</dt>
                <dd>{{ formatSalary(offer) }}</dd>
                <dt>Forma współpracy</dt>
                <dd>{{ offer.employment }}</dd>
                <dt>Dział</dt>
                <dd>{{ offer.department }}</dd>
                <dt>Opiekun rekrutacji</dt>
                <dd>{{ offer.owner }}</dd>
                <dt>Data utworzenia</dt>
                <dd>{{ formatDate(offer.createdAt) }}</dd>
            </dl>
        </aside>
    </div>
</template>
