<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../components/PageHeader.vue';
import { useAutomaticSource } from '../composables/useAutomaticSource.ts';
import { formatDateTime } from '../domain/recruitment.ts';
import { integrationsService } from '../services/integrations.ts';
import { api } from '../services/http.ts';
import { Notify } from 'quasar';
import SyncPanel from '../components/SyncPanel.vue';
import { loadManagement } from '../composables/useManagement.ts';
async function access(sourceId: string, enabled: boolean): Promise<void> {
    try {
        await api(`/api/integrations/sources/${sourceId}/write-access`, 'PUT', {
            enabled,
        });
        await reload();
        await loadManagement();
    } catch (e) {
        Notify.create({
            type: 'negative',
            message:
                e instanceof Error
                    ? e.message
                    : 'Nie udało się zmienić uprawnień.',
        });
    }
}
async function upgrade(id: string, name: string): Promise<void> {
    try {
        await integrationsService.connect(name, id, true);
        Notify.create({
            message:
                'Dokończ zgodę w systemowej przeglądarce. Następnie włącz zapis dla wybranego źródła.',
        });
    } catch (e) {
        Notify.create({
            type: 'negative',
            message: e instanceof Error ? e.message : 'Błąd OAuth.',
        });
    }
}
const {
    state,
    spreadsheet,
    accountId,
    busy,
    error,
    lastResult,
    accounts,
    connect,
    reload,
} = useAutomaticSource();
const required = (value: string) => !!value?.trim() || 'Wklej adres arkusza.';
const countLabels = {
    offers: 'ofert',
    applications: 'aplikacji',
    events: 'zdarzeń HR',
    profiles: 'profili',
} as const;
</script>
<template>
    <Head title="Integracje i źródła danych" />
    <PageHeader
        title="Integracje i źródła danych"
        subtitle="Podłącz arkusz. RecruitFlow rozpozna zakładki i kolumny oraz pobierze dane automatycznie."
        back-href="/settings"
        back-label="Ustawienia"
    />
    <section class="panel detail-content automatic-source-panel">
        <q-form @submit="connect" class="settings-form">
            <q-select
                v-model="accountId"
                :options="accounts"
                emit-value
                map-options
                outlined
                label="Połączone konto Google"
                :disable="busy"
                :rules="[
                    (v: string) =>
                        !!v ||
                        'Najpierw połącz konto w ustawieniach zaawansowanych.',
                ]"
            />
            <q-input
                v-model="spreadsheet"
                outlined
                label="URL arkusza Google Sheets"
                placeholder="Wklej pełny adres swojego arkusza"
                :disable="busy"
                :rules="[required]"
            />
            <div>
                <q-btn
                    type="submit"
                    color="primary"
                    unelevated
                    icon="sync"
                    label="Połącz i synchronizuj"
                    :loading="busy"
                    :disable="!accounts.length"
                />
            </div>
        </q-form>
        <p v-if="busy" class="muted q-mt-md">
            Rozpoznaję strukturę, sprawdzam klucze i relacje oraz aktualizuję
            lokalny cache…
        </p>
        <q-banner v-if="error" rounded class="error-banner q-mt-lg">{{
            error
        }}</q-banner>
        <div v-if="lastResult" class="q-mt-lg" role="status">
            <strong>Synchronizacja zakończona</strong>
            <p>
                <span
                    v-for="(label, role) in countLabels"
                    :key="role"
                    class="q-mr-md"
                    >{{ lastResult.counts[role] ?? 0 }} {{ label }}</span
                >
            </p>
        </div>
        <div
            v-for="source in state?.sources"
            :key="source.id"
            class="source-card q-mt-md"
        >
            <div class="row items-center q-gutter-sm">
                <strong>{{ source.name }}</strong
                ><q-badge :color="source.enabled ? 'green' : 'grey'">{{
                    source.enabled
                        ? source.read_only
                            ? 'Połączone · tylko odczyt'
                            : 'Połączone · odczyt i zapis'
                        : 'Oczekuje na synchronizację'
                }}</q-badge>
            </div>
            <p class="muted">
                Zaimportowano {{ source.records_count }} rekordów · Ostatnia
                synchronizacja: {{ formatDateTime(source.last_synced_at) }}
            </p>
            <q-btn
                v-if="source.enabled"
                outline
                :label="
                    source.read_only
                        ? 'Włącz zapis do tego źródła'
                        : 'Wyłącz zapis do źródła'
                "
                @click="access(source.id, source.read_only)"
            />
            <p class="muted q-mt-sm">
                Zapis jest oddzielną, świadomie włączaną opcją. Dotyczy tylko
                zgodnego źródła głównego RecruitFlow.
            </p>
            <p v-if="source.last_error" class="text-negative">
                {{ source.last_error }}
            </p>
        </div>
    </section>
    <section class="panel detail-content q-mt-lg">
        <h2>Uprawnienia kont Google</h2>
        <div
            v-for="account in state?.accounts"
            :key="account.id"
            class="row items-center q-gutter-md q-mb-md"
        >
            <span
                >{{ account.name }} ·
                {{
                    account.scopes.includes(
                        'https://www.googleapis.com/auth/spreadsheets',
                    )
                        ? 'Odczyt i edycja'
                        : 'Tylko odczyt'
                }}</span
            ><q-btn
                v-if="
                    !account.scopes.includes(
                        'https://www.googleapis.com/auth/spreadsheets',
                    )
                "
                outline
                label="Połącz ponownie z uprawnieniami edycji"
                @click="upgrade(account.id, account.name)"
            />
        </div>
    </section>
    <SyncPanel class="q-mt-lg" />
    <p class="q-mt-lg">
        <Link href="/settings/integrations/advanced" class="text-link"
            >Ustawienia zaawansowane</Link
        >
    </p>
</template>
