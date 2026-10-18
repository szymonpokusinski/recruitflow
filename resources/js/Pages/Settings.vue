<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useSettings } from '../composables/useSettings.ts';
import PageHeader from '../components/PageHeader.vue';
import SyncPanel from '../components/SyncPanel.vue';
import MailAccountsSection from '../components/MailAccountsSection.vue';

const { draft, save } = useSettings();
const themes = [
    { value: 'system', label: 'Systemowy' },
    { value: 'light', label: 'Jasny' },
    { value: 'dark', label: 'Ciemny' },
];
</script>
<template>
    <Head title="Ustawienia" />
    <PageHeader
        title="Ustawienia"
        subtitle="Preferencje wyglądu i lokalne dane aplikacji."
    />
    <div class="settings-grid">
        <div>
            <section class="panel detail-content">
                <h2>Preferencje</h2>
                <p class="muted">
                    Ustawienia zapisujemy lokalnie. Nie tworzą konta użytkownika.
                </p>
                <q-form @submit="save" class="settings-form"
                    ><q-input
                        v-model="draft.displayName"
                        outlined
                        label="Nazwa użytkownika (lokalnie)"
                        :maxlength="80"
                        :rules="[
                            (value: string) =>
                                value.trim().length > 0 ||
                                'Wpisz nazwę użytkownika.',
                        ]"
                    />
                    <q-select
                        v-model="draft.theme"
                        :options="themes"
                        emit-value
                        map-options
                        outlined
                        label="Motyw aplikacji"
                    />
                    <q-toggle
                        v-model="draft.compactRows"
                        label="Kompaktowe wiersze tabel"
                        color="primary"
                    />
                    <div>
                        <q-btn
                            type="submit"
                            unelevated
                            color="primary"
                            label="Zapisz preferencje"
                        />
                    </div>
                </q-form>
            </section>
            <MailAccountsSection class="q-mt-lg" />
        </div>
        <div>
            <SyncPanel />
            <section class="panel detail-content q-mt-lg">
                <h2>Integracje</h2>
                <p>
                    Google Sheets jest opcjonalnym źródłem i kanałem
                    synchronizacji. Oferty i historia są przechowywane w SQLite.
                </p>
                <Link href="/settings/integrations" class="text-link"
                    >Zarządzaj integracjami i źródłami
                    <q-icon name="arrow_forward"
                /></Link>
            </section>
        </div>
    </div>
</template>
