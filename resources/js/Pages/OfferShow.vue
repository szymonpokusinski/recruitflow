<script setup lang="ts">
import { toRef } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useOfferDetails } from '../composables/useDetails.ts';
import { useRecruitment } from '../composables/useRecruitment.ts';
import PageHeader from '../components/PageHeader.vue';
import ActivityTimeline from '../components/ActivityTimeline.vue';
import OfferDetails from '../components/OfferDetails.vue';
import ApplicationTable from '../components/ApplicationTable.vue';
import EmptyState from '../components/EmptyState.vue';
import OfferAiAnalysis from '../components/OfferAiAnalysis.vue';
import OfferActions from '../components/OfferActions.vue';
import RecruitmentAudit from '../components/RecruitmentAudit.vue';
import { management } from '../composables/useManagement.ts';
const props = defineProps<{ offerId: string }>();
const { offer, applications, activities } = useOfferDetails(
    toRef(props, 'offerId'),
);
const { state } = useRecruitment();
function fieldValue(
    offer: import('../types/recruitment.ts').Offer,
    key: string,
): string {
    const value = (offer as unknown as Record<string, unknown>)[key];
    return value === true
        ? 'Tak'
        : value === false
          ? 'Nie'
          : Array.isArray(value)
            ? value.join('; ')
            : value === null || value === undefined || value === ''
              ? '—'
              : String(value);
}
</script>
<template>
    <Head :title="offer?.title ?? 'Szczegóły oferty'" />
    <PageHeader
        :title="offer?.title ?? 'Szczegóły oferty'"
        :subtitle="
            offer
                ? `${offer.externalKey ?? offer.id} · ${offer.company}`
                : 'Szczegóły stanowiska'
        "
        back-href="/offers"
        back-label="Wszystkie oferty"
        ><OfferActions v-if="offer" :offer="offer"
    /></PageHeader>
    <template v-if="offer"
        ><q-banner class="q-mb-lg"
            >{{
                offer.origin === 'local'
                    ? 'Ręczna oferta'
                    : 'Pobrana z Google Sheets'
            }}
            ·
            {{
                offer.syncStatus === 'local'
                    ? 'Tylko lokalnie'
                    : offer.syncStatus === 'pending'
                      ? 'Oczekuje na synchronizację'
                      : offer.syncStatus === 'conflict'
                        ? 'Konflikt'
                        : offer.syncStatus === 'blocked'
                          ? 'Błąd synchronizacji'
                          : 'Lokalny cache źródła'
            }}<span v-if="offer.completionStatus === 'draft'">
                · Do uzupełnienia</span
            ><span v-if="offer.localOnlyFields?.length">
                · Pola lokalne: {{ offer.localOnlyFields.join(', ') }}</span
            ></q-banner
        >
        <OfferDetails :offer="offer" />
        <section class="panel detail-content q-mt-lg">
            <h2>Dane zawodowe i moje decyzje</h2>
            <template
                v-for="section in [
                    ...new Set(
                        management?.offerFields.map((f) => f.section) ?? [],
                    ),
                ]"
                :key="section"
                ><q-expansion-item :label="section"
                    ><dl class="detail-list q-pa-md">
                        <template
                            v-for="field in management?.offerFields.filter(
                                (f) => f.section === section,
                            )"
                            :key="field.key"
                            ><dt>{{ field.label }}</dt>
                            <dd class="text-preserve">
                                {{ fieldValue(offer, field.key) }}
                            </dd></template
                        >
                    </dl></q-expansion-item
                ></template
            >
            <dl class="detail-list">
                <template
                    v-for="attribute in offer.customAttributes"
                    :key="attribute.label"
                    ><dt>{{ attribute.label }}</dt>
                    <dd>{{ attribute.value }}</dd></template
                >
            </dl>
            <RecruitmentAudit
                :entity-id="offer.id"
                :offer-key="offer.externalKey"
            />
        </section>
        <OfferAiAnalysis :offer="offer" />
        <section class="panel detail-content q-mt-lg">
            <h2>Zdarzenia HR dotyczące oferty</h2>
            <ActivityTimeline :activities="activities" />
        </section>
        <section class="panel q-mt-lg">
            <div class="section-heading">
                <div>
                    <h2>Aplikacje do tej oferty</h2>
                    <p>Liczba zgłoszeń: {{ applications.length }}</p>
                </div>
            </div>
            <ApplicationTable :rows="applications" compact /></section
    ></template>
    <EmptyState
        v-else-if="state.loaded"
        title="Nie znaleziono oferty"
        description="Ta oferta nie występuje w bieżącym lokalnym zbiorze."
        icon="work_off"
        ><span>Wróć do listy, aby wybrać dostępną ofertę.</span></EmptyState
    >
</template>
