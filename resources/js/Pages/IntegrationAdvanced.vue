<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '../components/PageHeader.vue';
import EmptyState from '../components/EmptyState.vue';
import { useIntegrations, roles } from '../composables/useIntegrations.ts';
import { formatDateTime } from '../domain/recruitment.ts';
const {
    state,
    busy,
    error,
    sourceDialog,
    mappingDialog,
    selected,
    draft,
    mapping,
    sheetTitle,
    primary,
    accountName,
    clientFile,
    authUrl,
    authStatus,
    accounts,
    edit,
    save,
    configure,
    connect,
    disconnect,
    remove,
    openMapping,
    headers,
    roleChanged,
    headerChanged,
    saveMapping,
    test,
    sync,
    toggle,
    setPrimary,
    reload,
} = useIntegrations();
const required = (value: string) => !!value?.trim() || 'To pole jest wymagane.';
</script>
<template>
    <Head title="Ustawienia zaawansowane integracji" />
    <PageHeader
        title="Ustawienia zaawansowane integracji"
        subtitle="Twoje konta, arkusze i lokalny cache. Google Sheets jest dostępne wyłącznie do odczytu."
        back-href="/settings/integrations"
        back-label="Proste połączenie"
    >
        <q-btn
            flat
            icon="refresh"
            label="Odśwież panel"
            :loading="busy"
            @click="reload"
        />
        <q-btn
            color="primary"
            unelevated
            icon="add"
            label="Dodaj źródło"
            :disable="busy || !state?.accounts.length"
            @click="edit(null)"
        />
    </PageHeader>
    <q-banner v-if="error" class="error-banner q-mb-md" rounded>{{
        error
    }}</q-banner>
    <q-banner v-if="busy" class="q-mb-md" rounded
        ><q-spinner class="q-mr-sm" />Trwa operacja integracji. Odczyt i
        walidacja dużego arkusza mogą potrwać.</q-banner
    >
    <div class="integration-grid">
        <section class="panel detail-content">
            <h2>Google OAuth</h2>
            <p class="muted">
                Zaimportuj JSON klienta typu Desktop app z Google Cloud. Sekrety
                trafiają do
                {{
                    !state
                        ? 'bezpiecznego magazynu lokalnego'
                        : state.oauth.credential_store === 'keychain'
                          ? 'macOS Keychain'
                          : 'zaszyfrowanego magazynu lokalnego'
                }}. Panel nie wyświetla tokenów.
            </p>
            <q-badge :color="state?.oauth.configured ? 'green' : 'grey'">{{
                state?.oauth.configured
                    ? 'Klient skonfigurowany'
                    : 'Wymagana konfiguracja'
            }}</q-badge>
            <p v-if="state?.oauth.client_id" class="muted oauth-client">
                {{ state.oauth.client_id }}
            </p>
            <q-form @submit="configure" class="q-mt-md">
                <q-file
                    v-model="clientFile"
                    outlined
                    accept=".json,application/json"
                    label="Plik JSON klienta OAuth"
                    :disable="busy"
                />
                <q-btn
                    class="q-mt-md"
                    outline
                    label="Importuj konfigurację"
                    type="submit"
                    :disable="!clientFile || busy"
                />
            </q-form>
            <a
                href="https://console.cloud.google.com/apis/credentials"
                target="_blank"
                rel="noopener noreferrer"
                class="text-link q-mt-md inline-block"
                >Google Cloud Console</a
            >
        </section>
        <section class="panel detail-content">
            <h2>Konta Google</h2>
            <p class="muted">
                Nazwa to lokalna etykieta konta. Konto wybierzesz w systemowej
                przeglądarce Google. Zakres: tylko odczyt arkuszy.
            </p>
            <q-list separator
                ><q-item
                    v-for="account in state?.accounts"
                    :key="account.id"
                    class="q-px-none"
                    ><q-item-section
                        ><q-item-label>{{ account.name }}</q-item-label
                        ><q-item-label caption>{{
                            account.status === 'connected'
                                ? 'Połączone'
                                : account.status === 'pending'
                                  ? 'Oczekuje na logowanie'
                                  : 'Wymaga ponownego logowania'
                        }}</q-item-label></q-item-section
                    ><q-item-section side
                        ><div>
                            <q-btn
                                flat
                                dense
                                icon="login"
                                :disable="busy"
                                aria-label="Połącz konto ponownie"
                                @click="connect(account)"
                            /><q-btn
                                flat
                                dense
                                icon="link_off"
                                :disable="busy"
                                aria-label="Odłącz konto"
                                @click="disconnect(account)"
                            /></div></q-item-section></q-item
            ></q-list>
            <q-form @submit="connect()" class="q-mt-md"
                ><q-input
                    v-model="accountName"
                    outlined
                    label="Nazwa konta, np. Rekrutacja"
                    maxlength="120"
                    :rules="[required]" /><q-btn
                    type="submit"
                    outline
                    icon="login"
                    label="Połącz konto Google"
                    :disable="!state?.oauth.configured || busy"
            /></q-form>
            <p v-if="authStatus" class="muted">Logowanie: {{ authStatus }}</p>
            <q-btn
                v-if="authUrl"
                :href="authUrl"
                target="_blank"
                rel="noopener noreferrer"
                outline
                label="Otwórz logowanie Google w przeglądarce"
            />
        </section>
    </div>
    <section class="panel detail-content q-mt-lg">
        <h2>Źródła danych</h2>
        <p class="muted">
            Każda kolekcja może mieć jedno źródło główne. W tym etapie oznacza
            ono pierwszeństwo przy konfliktach kluczy; zapis do arkuszy jest
            wyłączony.
        </p>
        <EmptyState
            v-if="state && !state.sources.length"
            title="Brak skonfigurowanych źródeł"
            description="Połącz konto, dodaj własny arkusz i przypisz role oraz kolumny."
            icon="table_view"
            ><span>Każde źródło konfigurujesz samodzielnie.</span></EmptyState
        >
        <div
            v-for="source in state?.sources"
            :key="source.id"
            class="source-card"
        >
            <div class="row items-center q-gutter-sm">
                <h3 class="q-ma-none">{{ source.name }}</h3>
                <q-badge :color="source.enabled ? 'green' : 'grey'">{{
                    source.enabled ? 'Aktywne' : 'Wyłączone'
                }}</q-badge
                ><q-badge outline color="primary">Tylko odczyt</q-badge
                ><q-badge
                    v-if="source.configurations.some((c) => c.is_primary)"
                    color="primary"
                    >Główne</q-badge
                >
            </div>
            <p class="muted">
                {{ source.account.name }} · Co
                {{ source.sync_interval_minutes }} min · Ostatnia
                synchronizacja: {{ formatDateTime(source.last_synced_at) }}
            </p>
            <div class="q-gutter-xs">
                <q-chip
                    v-for="sheet in source.configurations"
                    :key="sheet.sheet_id"
                    dense
                    square
                    >{{ sheet.sheet_title }} →
                    {{ roles.find((r) => r.value === sheet.role)?.label
                    }}{{ sheet.is_primary ? ' (główne)' : '' }}</q-chip
                >
            </div>
            <p v-if="source.last_error" class="text-negative">
                {{ source.last_error }}
            </p>
            <q-banner
                v-if="!source.configurations.length"
                rounded
                class="q-my-md"
            >
                Przed aktywacją skonfiguruj mapowanie: wybierz role zakładek,
                pobierz nagłówki i przypisz wymagane kolumny.
                <template #action>
                    <q-btn
                        outline
                        color="primary"
                        label="Skonfiguruj mapowanie"
                        :disable="busy"
                        @click="openMapping(source)"
                    />
                </template>
            </q-banner>
            <div class="row q-gutter-sm q-mt-sm">
                <q-btn
                    flat
                    dense
                    icon="edit"
                    label="Edytuj"
                    :disable="busy"
                    @click="edit(source)"
                /><q-btn
                    flat
                    dense
                    icon="view_column"
                    label="Mapowanie"
                    :disable="busy || source.enabled"
                    @click="openMapping(source)"
                /><q-btn
                    flat
                    dense
                    icon="check_circle_outline"
                    label="Test połączenia"
                    :disable="busy"
                    @click="test(source)"
                /><q-btn
                    flat
                    dense
                    icon="sync"
                    label="Synchronizuj"
                    :disable="busy || !source.enabled"
                    @click="sync(source)"
                /><q-btn
                    flat
                    dense
                    :label="source.enabled ? 'Wyłącz' : 'Aktywuj'"
                    :disable="busy || !source.configurations.length"
                    @click="toggle(source)"
                /><q-btn
                    flat
                    dense
                    :label="
                        source.configurations.some((c) => c.is_primary)
                            ? 'Usuń pierwszeństwo'
                            : 'Ustaw jako główne'
                    "
                    :disable="busy || !source.enabled"
                    @click="setPrimary(source)"
                /><q-btn
                    flat
                    dense
                    color="negative"
                    icon="delete_outline"
                    label="Usuń"
                    :disable="busy"
                    @click="remove(source)"
                />
            </div>
        </div>
    </section>
    <section class="panel detail-content q-mt-lg">
        <h2>Historia synchronizacji</h2>
        <q-markup-table flat wrap-cells
            ><thead>
                <tr>
                    <th class="text-left">Źródło / czas</th>
                    <th class="text-left">Stan</th>
                    <th class="text-left">Rekordy</th>
                    <th class="text-left">Błąd</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="run in state?.runs" :key="run.id">
                    <td>
                        {{
                            state?.sources.find(
                                (s) => s.id === run.data_source_id,
                            )?.name
                        }}<br /><span class="muted">{{
                            formatDateTime(run.started_at)
                        }}</span>
                    </td>
                    <td>
                        {{
                            run.status === 'succeeded'
                                ? 'Zakończona'
                                : run.status === 'running'
                                  ? 'W toku'
                                  : 'Nieudana'
                        }}
                    </td>
                    <td>{{ run.records_count }}</td>
                    <td>{{ run.error ?? '—' }}</td>
                </tr>
                <tr v-if="!state?.runs.length">
                    <td colspan="4" class="muted">
                        Nie uruchomiono jeszcze synchronizacji.
                    </td>
                </tr>
            </tbody></q-markup-table
        >
    </section>
    <q-dialog v-model="sourceDialog" :persistent="busy"
        ><q-card class="integration-dialog"
            ><q-card-section
                ><h2>
                    {{
                        selected ? 'Edytuj źródło' : 'Nowe źródło Google Sheets'
                    }}
                </h2></q-card-section
            ><q-form @submit="save"
                ><q-card-section class="q-gutter-md"
                    ><q-input
                        v-model="draft.name"
                        outlined
                        label="Nazwa źródła"
                        maxlength="120"
                        :rules="[required]"
                    /><q-input
                        v-model="draft.spreadsheet"
                        outlined
                        label="Pełny URL lub spreadsheet ID"
                        :disable="selected?.enabled"
                        :rules="[required]"
                    /><q-select
                        v-model="draft.integration_account_id"
                        outlined
                        :options="accounts"
                        emit-value
                        map-options
                        label="Konto Google"
                        :disable="selected?.enabled"
                        :rules="[(v: string) => !!v || 'Wybierz konto.']"
                    /><q-input
                        v-model.number="draft.sync_interval_minutes"
                        outlined
                        type="number"
                        min="1"
                        max="1440"
                        label="Interwał synchronizacji (minuty)"
                        :rules="[
                            (v: number) =>
                                (Number.isInteger(Number(v)) &&
                                    Number(v) >= 1 &&
                                    Number(v) <= 1440) ||
                                'Wpisz liczbę 1–1440.',
                        ]"
                    /><q-toggle
                        v-model="draft.read_only"
                        disable
                        label="Tylko odczyt — zapis dostępny w kolejnym etapie"
                    />
                    <p class="muted">
                        Źródło zostanie aktywowane po poprawnym mapowaniu i
                        walidacji danych. Zmiana arkusza lub konta wymaga
                        wyłączenia źródła.
                    </p></q-card-section
                ><q-card-actions align="right"
                    ><q-btn
                        flat
                        label="Anuluj"
                        v-close-popup
                        :disable="busy" /><q-btn
                        color="primary"
                        unelevated
                        type="submit"
                        label="Zapisz źródło"
                        :loading="busy" /></q-card-actions></q-form></q-card
    ></q-dialog>
    <q-dialog v-model="mappingDialog" :persistent="busy"
        ><q-card class="mapping-dialog"
            ><q-card-section
                ><h2>Mapowanie: {{ sheetTitle }}</h2>
                <p class="muted">
                    Wybierz role zakładek, wiersz nagłówków i kolumny. Pola z *
                    są wymagane. Klucze muszą być stabilne i wspólne dla
                    powiązanych kolekcji.
                </p></q-card-section
            ><q-card-section>
                <q-expansion-item
                    v-for="sheet in mapping"
                    :key="sheet.sheet_id"
                    :label="sheet.sheet_title"
                    :caption="roles.find((r) => r.value === sheet.role)?.label"
                    group="mapping"
                    class="mapping-sheet"
                    :default-opened="sheet.role !== 'other'"
                >
                    <div class="q-pa-md">
                        <div class="row q-col-gutter-md">
                            <q-select
                                class="col-12 col-sm-6"
                                v-model="sheet.role"
                                outlined
                                :options="roles"
                                emit-value
                                map-options
                                label="Rola zakładki"
                                @update:model-value="roleChanged(sheet)"
                                :disable="busy"
                            /><q-input
                                class="col-6 col-sm-3"
                                v-model.number="sheet.header_row"
                                outlined
                                type="number"
                                min="1"
                                max="100"
                                label="Wiersz nagłówków"
                                @update:model-value="headerChanged(sheet)"
                                :disable="busy"
                            />
                            <div class="col-6 col-sm-3">
                                <q-btn
                                    outline
                                    label="Pobierz nagłówki"
                                    :disable="busy || sheet.role === 'other'"
                                    @click="headers(sheet)"
                                />
                            </div>
                        </div>
                        <div v-if="sheet.loaded" class="mapping-fields q-mt-md">
                            <q-select
                                v-for="field in state?.schemas[sheet.role] ??
                                []"
                                :key="field.field"
                                v-model="sheet.mapping[field.field]"
                                :options="sheet.columns"
                                outlined
                                clearable
                                :label="
                                    field.label +
                                    ((
                                        sheet.schema_profile === 'recruitflow'
                                            ? state?.recruitflow_required?.[
                                                  sheet.role
                                              ]?.includes(field.field)
                                            : field.required
                                    )
                                        ? ' *'
                                        : '')
                                "
                                :disable="busy"
                            />
                        </div>
                        <p
                            v-else-if="sheet.role !== 'other'"
                            class="muted q-mt-md"
                        >
                            Pobierz nagłówki, aby uzyskać propozycję mapowania.
                        </p>
                    </div></q-expansion-item
                >
                <q-toggle
                    v-model="primary"
                    label="Ustaw jako źródło główne dla przypisanych kolekcji przy aktywacji"
                    :disable="busy"
                />
                <p class="muted">
                    Test odczytu sprawdzi wymagane pola i unikalność kluczy.
                    Aktywacja sprawdzi też powiązania między ofertami,
                    aplikacjami i zdarzeniami. Profile trafiają do lokalnego
                    cache; pozostałe zakładki nie są importowane.
                </p> </q-card-section
            ><q-card-actions align="right"
                ><q-btn
                    flat
                    label="Anuluj"
                    v-close-popup
                    :disable="busy" /><q-btn
                    outline
                    label="Zapisz i przetestuj"
                    :loading="busy"
                    @click="saveMapping(false)" /><q-btn
                    color="primary"
                    unelevated
                    label="Zapisz i aktywuj"
                    :loading="busy"
                    @click="saveMapping(true)" /></q-card-actions></q-card
    ></q-dialog>
</template>
