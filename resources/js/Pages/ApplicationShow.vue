<script setup lang="ts">
import { toRef } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useApplicationDetails } from '../composables/useDetails.ts';
import { useRecruitment } from '../composables/useRecruitment.ts';
import { formatDate, formatDateTime } from '../domain/recruitment.ts';
import PageHeader from '../components/PageHeader.vue';
import ApplicationStatus from '../components/ApplicationStatus.vue';
import ActivityTimeline from '../components/ActivityTimeline.vue';
import EmptyState from '../components/EmptyState.vue';
import AiMatchScore from '../components/AiMatchScore.vue';
import { openDialog } from '../composables/useManagement.ts';
import OfferActions from '../components/OfferActions.vue';
import RecruitmentAudit from '../components/RecruitmentAudit.vue';
const props = defineProps<{ applicationId: string }>();
const { application, offer, activities } = useApplicationDetails(
    toRef(props, 'applicationId'),
);
const { state } = useRecruitment();
</script>
<template>
    <Head :title="application?.displayName ?? 'Szczegóły aplikacji'" />
    <PageHeader
        :title="application?.displayName ?? 'Szczegóły aplikacji'"
        :subtitle="
            application
                ? `${application.externalKey ?? application.id} · ${application.candidateRole} · ${state.mode === 'local' ? 'SQLite · tryb lokalny' : 'SQLite · Google Sheets'}`
                : 'Szczegóły zgłoszenia'
        "
        back-href="/applications"
        back-label="Wszystkie aplikacje"
        ><ApplicationStatus
            v-if="application"
            :status="application.status"
            :raw-status="application.rawStatus"
    /></PageHeader>
    <div v-if="application" class="details-grid application-details">
        <div>
            <section class="panel detail-content">
                <h2>
                    {{
                        application.schemaProfile === 'recruitflow'
                            ? 'Informacje o aplikacji'
                            : 'Profil kandydata'
                    }}
                </h2>
                <dl class="detail-list horizontal">
                    <dt v-if="application.candidateEmail">Adres e-mail</dt>
                    <dd v-if="application.candidateEmail">
                        {{ application.candidateEmail }}
                    </dd>
                    <dt>Lokalizacja</dt>
                    <dd>{{ application.location }}</dd>
                    <dt>Oferta</dt>
                    <dd>
                        <Link
                            v-if="offer"
                            :href="`/offers/${offer.id}`"
                            class="text-link"
                            >{{ offer.title }}<q-icon name="open_in_new"
                        /></Link>
                    </dd>
                    <template
                        v-if="application.schemaProfile === 'recruitflow'"
                    >
                        <dt>CV wysłane</dt>
                        <dd>
                            {{
                                application.cvSent === null
                                    ? '—'
                                    : application.cvSent
                                      ? 'Tak'
                                      : 'Nie'
                            }}
                        </dd>
                        <dt>Potwierdzenie</dt>
                        <dd>
                            {{
                                formatDateTime(
                                    application.confirmationAt ?? null,
                                )
                            }}
                        </dd>
                        <dt>Pierwsza odpowiedź</dt>
                        <dd>
                            {{
                                formatDateTime(application.firstReplyAt ?? null)
                            }}
                        </dd>
                        <dt>Ostatnia odpowiedź</dt>
                        <dd>
                            {{
                                formatDateTime(application.lastReplyAt ?? null)
                            }}
                        </dd>
                    </template>
                    <dt>Opiekun</dt>
                    <dd>{{ application.owner }}</dd>
                    <dt>Źródło zgłoszenia</dt>
                    <dd>{{ application.source }}</dd>
                    <dt>Data aplikacji</dt>
                    <dd>{{ formatDate(application.appliedAt) }}</dd>
                    <dt v-if="application.nextStep">Termin kolejnego kroku</dt>
                    <dd v-if="application.nextStep">
                        {{ formatDateTime(application.nextStep) }}
                    </dd>
                </dl>
            </section>
            <section v-if="offer" class="panel detail-content q-mt-lg">
                <h2>Dopasowanie oferty</h2>
                <p class="muted q-mt-xs">
                    Ocena oferty względem profilu wyszukiwania, odczytana z
                    powiązanej oferty. Odrębna analiza CV nie jest dostępna.
                </p>
                <AiMatchScore :score="offer.aiMatchScore" class="q-mt-md" />
                <Link
                    :href="`/offers/${offer.id}#ai-analysis`"
                    class="text-link q-mt-md"
                    >Pełna analiza dopasowania<q-icon name="arrow_forward"
                /></Link>
            </section>
            <section class="panel detail-content q-mt-lg">
                <h2>Historia zdarzeń</h2>
                <ActivityTimeline :activities="activities" />
            </section>
        </div>
        <aside>
            <section class="panel detail-content">
                <h2>Zarządzanie aplikacją</h2>
                <div class="column q-gutter-md">
                    <OfferActions v-if="offer" :offer="offer" /><q-btn
                        outline
                        label="Zmień etap"
                        @click="openDialog('stage', offer ?? null, application)"
                    /><q-btn
                        outline
                        label="Edytuj dane aplikacji"
                        @click="
                            openDialog(
                                'application',
                                offer ?? null,
                                application,
                            )
                        "
                    /><q-btn
                        color="primary"
                        label="Dodaj zdarzenie HR"
                        @click="openDialog('event', offer ?? null, application)"
                    />
                </div>
                <dl class="detail-list q-mt-lg">
                    <dt>Wersja CV</dt>
                    <dd>{{ application.cvVersion || '—' }}</dd>
                    <dt>Numer referencyjny</dt>
                    <dd>{{ application.reference || '—' }}</dd>
                    <dt>Kontakt HR</dt>
                    <dd>
                        {{ application.recruiterName || '—' }}
                        {{ application.recruiterEmail }}
                    </dd>
                    <dt>Notatki / feedback</dt>
                    <dd class="text-preserve">
                        {{ application.feedbackNotes || '—' }}
                    </dd>
                </dl>
            </section>
            <section class="panel q-mt-lg">
                <RecruitmentAudit
                    :entity-id="application.id"
                    :offer-key="offer?.externalKey"
                />
            </section>
        </aside>
    </div>
    <EmptyState
        v-else-if="state.loaded"
        title="Nie znaleziono aplikacji"
        description="Ta aplikacja nie występuje w bieżącym lokalnym zbiorze."
        icon="person_off"
        ><span>Wróć do listy aplikacji.</span></EmptyState
    >
</template>
