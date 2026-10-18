<script setup lang="ts">
import { useRecruitment } from '../composables/useRecruitment.ts';
import { Head, Link } from '@inertiajs/vue3';
import { useDashboard } from '../composables/useDashboard.ts';
import PageHeader from '../components/PageHeader.vue';
import StatsCard from '../components/StatsCard.vue';
import ApplicationTable from '../components/ApplicationTable.vue';
import ActivityTimeline from '../components/ActivityTimeline.vue';
import BestMatchedOffers from '../components/BestMatchedOffers.vue';
const { state } = useRecruitment();
const { stats, pendingOffersCount, recentApplications, recentActivities, bestOffers } =
    useDashboard();
</script>
<template>
    <Head title="Dashboard" />
    <PageHeader
        title="Dashboard"
        subtitle="Przegląd rekrutacji w lokalnej przestrzeni roboczej."
        ><Link href="/offers" class="button-link"
            >Przeglądaj oferty<q-icon name="arrow_forward" /></Link
    ></PageHeader>
    <div class="stats-grid">
        <StatsCard
            label="Do aplikowania"
            :value="pendingOffersCount"
            caption="Oferty do sprawdzenia i zgłoszenia"
            icon="work_outline"
            href="/offers"
        />
        <StatsCard
            label="Aplikacje w procesie"
            :value="stats.activeApplications"
            caption="Rozpoznane aktywne etapy"
            icon="people_outline"
            href="/applications"
        />
        <StatsCard
            label="Nowe aplikacje"
            :value="stats.newApplications"
            caption="Do pierwszej selekcji"
            icon="inbox"
            href="/applications"
        />
        <StatsCard
            label="Rozmowy"
            :value="stats.interviews"
            caption="Na etapie rozmowy"
            icon="chat_bubble_outline"
            href="/applications"
        />
    </div>
    <BestMatchedOffers :offers="bestOffers" />
    <section class="panel q-mt-lg">
        <div class="section-heading">
            <div>
                <h2>Ostatnie aplikacje</h2>
                <p>
                    Pięć najnowszych zgłoszeń
                    {{ state.mode === 'local' ? 'SQLite' : 'z lokalnego cache' }}
                </p>
            </div>
            <Link href="/applications" class="text-link"
                >Wszystkie aplikacje<q-icon name="arrow_forward"
            /></Link>
        </div>
        <ApplicationTable :rows="recentApplications" compact />
    </section>
    <section class="panel q-mt-lg">
        <div class="section-heading">
            <div>
                <h2>Ostatnia aktywność</h2>
                <p>Historia zdarzeń powiązanych z ofertami</p>
            </div>
            <Link href="/activity" class="text-link"
                >Historia HR<q-icon name="arrow_forward"
            /></Link>
        </div>
        <div class="detail-content">
            <ActivityTimeline :activities="recentActivities" show-candidate />
        </div>
    </section>
</template>
