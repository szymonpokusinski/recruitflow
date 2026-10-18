<script setup lang="ts">
import { ref, reactive, onMounted, onUnmounted, watch, computed } from 'vue';
import { Dialog, Notify } from 'quasar';
import { mailAccountsService } from '../services/mailAccounts.ts';
import { formatDateTime } from '../domain/recruitment.ts';
import type {
    MailAccount,
    MailAccountConnectionTestResult,
    ResolvedProvider,
} from '../types/mailAccounts.ts';

const accounts = ref<MailAccount[]>([]);
const loading = ref(false);
const saving = ref(false);
const testingConnection = ref(false);
const testingAccountId = ref<string | null>(null);
const dialogOpen = ref(false);
const editingAccount = ref<MailAccount | null>(null);
const showPassword = ref(false);
const formError = ref('');
const connectionTestResult = ref<MailAccountConnectionTestResult | null>(null);

const resolvedProvider = ref<ResolvedProvider | null>(null);
const resolvingProvider = ref(false);
let resolveTimer: ReturnType<typeof setTimeout> | null = null;

// OAuth polling & status state
const oauthLoading = ref(false);
const oauthPolling = ref(false);
const oauthFlowId = ref<string | null>(null);
const oauthAuthUrl = ref<string | null>(null);
const oauthStatusMessage = ref<string>('');
let oauthPollTimer: ReturnType<typeof setTimeout> | null = null;

const form = reactive({
    email: '',
    password: '',
});

const providerNames: Record<string, string> = {
    gmail: 'Gmail',
    wp: 'Poczta WP',
    o2: 'Poczta o2',
    onet: 'Onet Poczta',
    interia: 'Poczta Interia',
    gazeta: 'Gazeta.pl',
    outlook: 'Outlook',
    generic: 'Własny dostawca',
};

function formatProviderName(id: string | null): string {
    return (id && providerNames[id]) || id || '';
}

const isGmailProvider = computed(() => {
    const p = resolvedProvider.value?.provider || editingAccount.value?.provider;
    const authType = editingAccount.value?.auth_type;
    return p === 'gmail' || authType === 'oauth';
});

const isSupportedProvider = computed(() => {
    const p = resolvedProvider.value?.provider || editingAccount.value?.provider;
    return (
        p === 'wp' ||
        p === 'o2' ||
        p === 'onet' ||
        p === 'interia' ||
        p === 'gazeta' ||
        p === 'gmail'
    );
});

function getErrorHint(code: string | null | undefined): string {
    switch (code) {
        case 'insufficient_scopes':
            return 'Wskazówka: Zalogowano do konta Google, ale nie przyznano uprawnień do Gmaila (brak zakresu gmail.metadata). Kliknij „Połącz ponownie z Google” i zaznacz pole wyboru zezwalające na dostęp do poczty.';
        case 'token_expired':
            return 'Wskazówka: Token dostępu Google wygasł lub został cofnięty. Kliknij „Połącz ponownie z Google”, aby odświeżyć autoryzację.';
        case 'authentication_failed':
            return 'Wskazówka: Zaloguj się przez przeglądarkę i sprawdź, czy w opcjach skrzynki włączono obsługę IMAP. Jeśli masz włączone logowanie dwuetapowe (2FA), musisz wygenerować i wpisać hasło do programu pocztowego zamiast hasła głównego (w Interii 2FA blokuje IMAP i wymaga wyłączenia).';
        case 'tls_error':
            return 'Wskazówka: Błąd weryfikacji certyfikatu SSL/TLS. Upewnij się, że połączenie nie jest blokowane przez lokalny program antywirusowy lub proxy.';
        case 'connection_timeout':
        case 'timeout':
            return 'Wskazówka: Serwer pocztowy nie odpowiedział w wyznaczonym czasie (timeout). Sprawdź połączenie z internetem.';
        case 'unsupported_provider':
            return 'Wskazówka: Testowanie połączenia IMAP obsługuje obecnie skrzynki Poczty WP, o2, Onet, Interia oraz Gazeta.pl.';
        default:
            return 'Wskazówka: Sprawdź poprawność konfiguracji konta i upewnij się, że dostęp jest aktywny na koncie dostawcy.';
    }
}

function resolveProviderForEmail(val: string): void {
    if (resolveTimer) clearTimeout(resolveTimer);

    const email = val.trim();
    if (!email || !email.includes('@') || !email.includes('.')) {
        resolvedProvider.value = null;
        resolvingProvider.value = false;
        return;
    }

    resolvingProvider.value = true;
    resolveTimer = setTimeout(async () => {
        try {
            resolvedProvider.value =
                await mailAccountsService.resolveProvider(email);
        } catch {
            resolvedProvider.value = null;
        } finally {
            resolvingProvider.value = false;
        }
    }, 200);
}

watch(
    () => form.email,
    (newEmail) => {
        resolveProviderForEmail(newEmail);
    },
);

async function loadAccounts(): Promise<void> {
    loading.value = true;
    try {
        accounts.value = await mailAccountsService.list();
    } catch (err) {
        Notify.create({
            type: 'negative',
            message:
                err instanceof Error
                    ? err.message
                    : 'Nie udało się pobrać listy kont pocztowych.',
        });
    } finally {
        loading.value = false;
    }
}

function stopOAuthPolling(): void {
    if (oauthPollTimer) {
        clearTimeout(oauthPollTimer);
        oauthPollTimer = null;
    }
    oauthPolling.value = false;
    oauthLoading.value = false;
    oauthFlowId.value = null;
    oauthAuthUrl.value = null;
    oauthStatusMessage.value = '';
}

function openCreateDialog(): void {
    stopOAuthPolling();
    editingAccount.value = null;
    form.email = '';
    form.password = '';
    formError.value = '';
    connectionTestResult.value = null;
    resolvedProvider.value = null;
    resolvingProvider.value = false;
    showPassword.value = false;
    dialogOpen.value = true;
}

function openEditDialog(account: MailAccount): void {
    stopOAuthPolling();
    editingAccount.value = account;
    form.email = account.email;
    form.password = '';
    formError.value = '';
    connectionTestResult.value = account.last_test_result
        ? {
              success: account.last_test_result === 'success',
              message:
                  account.last_test_result === 'success'
                      ? (account.auth_type === 'oauth'
                          ? 'Połączenie z Gmail API powiodło się.'
                          : 'Połączenie z serwerem pocztowym powiodło się.')
                      : (account.last_error ?? 'Błąd połączenia.'),
              error: account.last_error,
              error_code: null,
              provider: account.provider ?? 'generic',
              status: account.status,
              last_tested_at: account.last_tested_at ?? null,
              last_test_result: account.last_test_result ?? null,
          }
        : null;
    resolvedProvider.value = null;
    resolvingProvider.value = false;
    showPassword.value = false;
    dialogOpen.value = true;
    resolveProviderForEmail(account.email);
}

async function testConnectionForAccount(
    accountId: string,
    passwordOverride?: string,
): Promise<MailAccountConnectionTestResult | null> {
    testingAccountId.value = accountId;
    try {
        const result = await mailAccountsService.test(
            accountId,
            passwordOverride,
        );
        if (result.success) {
            Notify.create({
                type: 'positive',
                message: result.provider === 'gmail'
                    ? 'Połączenie z Gmail API powiodło się!'
                    : 'Połączenie z serwerem IMAP powiodło się!',
            });
        } else {
            Notify.create({
                type: 'negative',
                message: result.error || 'Nie udało się nawiązać połączenia.',
            });
        }
        await loadAccounts();
        return result;
    } catch (err) {
        const message =
            err instanceof Error
                ? err.message
                : 'Błąd podczas testowania połączenia.';
        Notify.create({
            type: 'negative',
            message,
        });
        return {
            success: false,
            message,
            error: message,
            error_code: 'network_error',
            provider: 'generic',
            status: 'error',
            last_tested_at: new Date().toISOString(),
            last_test_result: 'failed',
        };
    } finally {
        testingAccountId.value = null;
    }
}

async function testCurrentFormConnection(): Promise<void> {
    if (!editingAccount.value) return;

    testingConnection.value = true;
    try {
        const result = await testConnectionForAccount(
            editingAccount.value.id,
            form.password || undefined,
        );
        connectionTestResult.value = result;
    } finally {
        testingConnection.value = false;
    }
}

async function testAccountFromList(account: MailAccount): Promise<void> {
    await testConnectionForAccount(account.id);
}

async function startGoogleOAuth(): Promise<void> {
    formError.value = '';
    oauthLoading.value = true;
    oauthStatusMessage.value = 'Inicjalizowanie połączenia Google OAuth2...';

    try {
        const res = await mailAccountsService.connectGoogle(form.email || undefined);
        oauthFlowId.value = res.flow_id;
        oauthAuthUrl.value = res.authorization_url;
        oauthPolling.value = true;
        oauthStatusMessage.value = 'Oczekiwanie na autoryzację w oknie przeglądarki... Zezwól na dostęp do poczty w oknie Google.';

        if (res.authorization_url && typeof window !== 'undefined' && !('nativephp' in window)) {
            window.open(res.authorization_url, '_blank');
        }

        pollOAuthFlow(res.flow_id);
    } catch (err: unknown) {
        stopOAuthPolling();
        const msg = err instanceof Error ? err.message : 'Nie udało się rozpocząć logowania Google.';
        formError.value = msg;
        Notify.create({
            type: 'negative',
            message: msg,
        });
    }
}

async function pollOAuthFlow(flowId: string): Promise<void> {
    if (oauthPollTimer) clearTimeout(oauthPollTimer);

    try {
        const res = await mailAccountsService.checkGoogleFlow(flowId);

        if (res.status === 'connected') {
            stopOAuthPolling();
            Notify.create({
                type: 'positive',
                message: res.message || 'Konto Gmail zostało pomyślnie połączone!',
            });
            dialogOpen.value = false;
            await loadAccounts();
            return;
        }

        if (res.status === 'scope_missing') {
            stopOAuthPolling();
            formError.value = res.message || 'Zalogowano do konta Google, ale nie przyznano uprawnień do Gmaila (brak zakresu gmail.metadata).';
            Notify.create({
                type: 'warning',
                message: 'Brak uprawnień do Gmaila. Spróbuj ponownie i zaznacz zgodę na dostęp do poczty.',
            });
            await loadAccounts();
            return;
        }

        if (res.status === 'failed' || res.status === 'expired' || res.status === 'error') {
            stopOAuthPolling();
            const msg = res.error || res.message || 'Logowanie Google OAuth2 nie powiodło się.';
            formError.value = msg;
            Notify.create({
                type: 'negative',
                message: msg,
            });
            await loadAccounts();
            return;
        }

        // Still pending
        oauthPollTimer = setTimeout(() => {
            void pollOAuthFlow(flowId);
        }, 2000);
    } catch (err: unknown) {
        stopOAuthPolling();
        const msg = err instanceof Error ? err.message : 'Błąd sprawdzania statusu logowania Google.';
        formError.value = msg;
    }
}

async function saveAccount(andTest = false): Promise<void> {
    if (isGmailProvider.value && !editingAccount.value) {
        // Adding Gmail requires OAuth authorization
        await startGoogleOAuth();
        return;
    }

    saving.value = true;
    formError.value = '';

    try {
        let savedAccount: MailAccount;
        if (editingAccount.value) {
            savedAccount = await mailAccountsService.update(
                editingAccount.value.id,
                {
                    email: form.email.trim(),
                    password: form.password ? form.password : undefined,
                },
            );
            Notify.create({
                type: 'positive',
                message: 'Konto pocztowe zostało zaktualizowane.',
            });
        } else {
            savedAccount = await mailAccountsService.create({
                email: form.email.trim(),
                password: form.password,
            });
            Notify.create({
                type: 'positive',
                message: 'Konto pocztowe zostało dodane.',
            });
        }

        if (andTest) {
            testingConnection.value = true;
            connectionTestResult.value = await testConnectionForAccount(
                savedAccount.id,
                form.password || undefined,
            );
            testingConnection.value = false;
        }

        dialogOpen.value = false;
        await loadAccounts();
    } catch (err) {
        const message =
            err instanceof Error
                ? err.message
                : 'Wystąpił błąd podczas zapisywania konta.';
        formError.value = message;
        Notify.create({
            type: 'negative',
            message,
        });
    } finally {
        saving.value = false;
    }
}

function confirmDelete(account: MailAccount): void {
    Dialog.create({
        title: 'Potwierdź usunięcie',
        message: `Czy na pewno chcesz usunąć konto pocztowe „${account.email}”?`,
        cancel: {
            label: 'Anuluj',
            flat: true,
        },
        ok: {
            label: 'Usuń',
            color: 'negative',
            unelevated: true,
        },
    }).onOk(async () => {
        try {
            await mailAccountsService.delete(account.id);
            Notify.create({
                type: 'positive',
                message: 'Konto pocztowe zostało usunięte.',
            });
            await loadAccounts();
        } catch (err) {
            Notify.create({
                type: 'negative',
                message:
                    err instanceof Error
                        ? err.message
                        : 'Nie udało się usunąć konta pocztowego.',
            });
        }
    });
}

function confirmDisconnectOAuth(account: MailAccount): void {
    Dialog.create({
        title: 'Odłącz konto Gmail',
        message: `Czy na pewno chcesz odłączyć integrację Gmail dla konta „${account.email}”? Połączenie z Google Sheets pozostanie nienaruszone.`,
        cancel: {
            label: 'Anuluj',
            flat: true,
        },
        ok: {
            label: 'Odłącz Gmail',
            color: 'negative',
            unelevated: true,
        },
    }).onOk(async () => {
        try {
            await mailAccountsService.disconnectOAuth(account.id);
            Notify.create({
                type: 'positive',
                message: 'Konto Gmail zostało odłączone.',
            });
            dialogOpen.value = false;
            await loadAccounts();
        } catch (err) {
            Notify.create({
                type: 'negative',
                message:
                    err instanceof Error
                        ? err.message
                        : 'Nie udało się odłączyć konta Gmail.',
            });
        }
    });
}

onMounted(() => {
    void loadAccounts();
});

onUnmounted(() => {
    stopOAuthPolling();
    if (resolveTimer) clearTimeout(resolveTimer);
});
</script>

<template>
    <section class="panel detail-content relative-position">
        <div class="row items-center justify-between q-mb-sm">
            <h2 class="q-ma-none">Konta pocztowe</h2>
            <q-btn
                color="primary"
                unelevated
                icon="add"
                label="Dodaj konto"
                :disable="loading"
                @click="openCreateDialog"
            />
        </div>
        <p class="muted">
            Lokalnie skonfigurowane konta pocztowe do komunikacji rekrutacyjnej.
            Obsługuje bezpieczne logowanie OAuth2 (Gmail) oraz szyfrowane połączenia IMAP (WP, o2, Onet, Interia, Gazeta.pl).
        </p>

        <q-inner-loading :showing="loading">
            <q-spinner size="32px" color="primary" />
        </q-inner-loading>

        <div v-if="!loading && accounts.length === 0" class="muted q-my-md">
            Brak skonfigurowanych kont pocztowych. Kliknij „Dodaj konto”, aby
            dodać skrzynkę.
        </div>

        <q-list v-if="accounts.length > 0" separator class="q-mt-md">
            <q-item
                v-for="account in accounts"
                :key="account.id"
                class="q-px-none"
            >
                <q-item-section avatar>
                    <q-icon
                        :name="account.provider === 'gmail' ? 'mail_outline' : 'mail'"
                        :color="
                            account.last_test_result === 'success'
                                ? 'positive'
                                : account.last_test_result === 'failed'
                                  ? 'negative'
                                  : 'primary'
                        "
                        size="24px"
                    />
                </q-item-section>

                <q-item-section>
                    <div class="row items-center q-gutter-x-sm">
                        <q-item-label class="text-weight-medium">{{
                            account.email
                        }}</q-item-label>

                        <q-badge
                            v-if="account.auth_type === 'oauth'"
                            color="blue-8"
                            class="q-px-xs"
                        >
                            Google OAuth2
                        </q-badge>

                        <q-badge
                            v-if="account.last_test_result === 'success'"
                            color="positive"
                            class="q-px-xs"
                        >
                            {{ account.auth_type === 'oauth' ? 'Połączono (OAuth2)' : 'Połączono' }}
                        </q-badge>
                        <q-badge
                            v-else-if="account.last_test_result === 'failed'"
                            color="negative"
                            class="q-px-xs"
                        >
                            {{ account.last_error && account.last_error.includes('brak zakresu') ? 'Brak uprawnień' : 'Błąd połączenia' }}
                        </q-badge>
                        <q-badge v-else color="grey-6" class="q-px-xs">
                            Nieprzetestowane
                        </q-badge>
                    </div>

                    <q-item-label caption class="q-mt-xs">
                        <template
                            v-if="
                                account.provider &&
                                account.provider !== 'generic'
                            "
                        >
                            <span class="text-weight-medium">{{
                                formatProviderName(account.provider)
                            }}</span>
                            &nbsp;•&nbsp;
                        </template>
                        <span v-if="account.last_tested_at">
                            Ostatni test:
                            {{ formatDateTime(account.last_tested_at) }}
                        </span>
                        <span v-else>Brak wykonanego testu połączenia</span>
                        <span
                            v-if="account.last_error"
                            class="text-negative block q-mt-xs"
                        >
                            {{ account.last_error }}
                        </span>
                    </q-item-label>
                </q-item-section>

                <q-item-section side>
                    <div class="row q-gutter-xs">
                        <q-btn
                            flat
                            round
                            dense
                            icon="network_check"
                            color="primary"
                            aria-label="Testuj połączenie"
                            :loading="testingAccountId === account.id"
                            @click="testAccountFromList(account)"
                        >
                            <q-tooltip>{{ account.auth_type === 'oauth' ? 'Testuj połączenie Gmail API' : 'Testuj połączenie IMAP' }}</q-tooltip>
                        </q-btn>
                        <q-btn
                            flat
                            round
                            dense
                            icon="edit"
                            aria-label="Edytuj konto"
                            @click="openEditDialog(account)"
                        >
                            <q-tooltip>Edytuj konto</q-tooltip>
                        </q-btn>
                        <q-btn
                            flat
                            round
                            dense
                            icon="delete"
                            color="negative"
                            aria-label="Usuń konto"
                            @click="confirmDelete(account)"
                        >
                            <q-tooltip>Usuń konto</q-tooltip>
                        </q-btn>
                    </div>
                </q-item-section>
            </q-item>
        </q-list>

        <!-- Modal Dialog for Add / Edit -->
        <q-dialog v-model="dialogOpen" persistent>
            <q-card style="width: 560px; max-width: 95vw">
                <q-form @submit.prevent="saveAccount(false)">
                    <q-card-section class="row items-center">
                        <h3 class="q-ma-none text-h6">
                            {{
                                editingAccount
                                    ? (isGmailProvider ? 'Ustawienia Gmail (OAuth2)' : 'Edytuj konto pocztowe')
                                    : (isGmailProvider ? 'Podłącz skrzynkę Gmail' : 'Dodaj konto pocztowe')
                            }}
                        </h3>
                        <q-space />
                        <q-btn
                            flat
                            round
                            dense
                            icon="close"
                            aria-label="Zamknij"
                            v-close-popup
                            :disable="saving || testingConnection || oauthPolling"
                        />
                    </q-card-section>

                    <q-separator />

                    <q-card-section class="q-gutter-y-md">
                        <q-banner
                            v-if="formError"
                            class="bg-negative text-white q-mb-sm rounded-borders"
                        >
                            {{ formError }}
                        </q-banner>

                        <div>
                            <q-input
                                v-model="form.email"
                                type="email"
                                outlined
                                label="Adres e-mail"
                                :hint="isGmailProvider ? 'Wpisz swój adres @gmail.com' : undefined"
                                :rules="[
                                    (val: string) =>
                                        !!val?.trim() || 'Wpisz adres e-mail.',
                                    (val: string) =>
                                        /.+@.+\..+/.test(val) ||
                                        'Podaj prawidłowy adres e-mail.',
                                ]"
                                :disable="saving || testingConnection || oauthPolling || !!(editingAccount && isGmailProvider)"
                            />

                            <!-- Recognized provider indicator -->
                            <div class="q-mt-xs">
                                <div
                                    v-if="resolvingProvider"
                                    class="text-caption text-grey row items-center q-gutter-xs"
                                >
                                    <q-spinner size="14px" color="primary" />
                                    <span>Rozpoznawanie dostawcy...</span>
                                </div>
                                <div
                                    v-else-if="resolvedProvider"
                                    class="row items-center q-gutter-xs"
                                >
                                    <q-badge
                                        v-if="resolvedProvider.is_known"
                                        color="primary"
                                        class="q-py-xs q-px-sm text-body2"
                                    >
                                        <q-icon
                                            name="check_circle"
                                            class="q-mr-xs"
                                        />
                                        Wykryto: {{ resolvedProvider.name }}
                                        <span v-if="resolvedProvider.provider === 'gmail'" class="q-ml-xs text-weight-bold">
                                            (Logowanie Google OAuth2)
                                        </span>
                                    </q-badge>
                                    <q-badge
                                        v-else
                                        color="grey-7"
                                        class="q-py-xs q-px-sm text-body2"
                                    >
                                        <q-icon
                                            name="help_outline"
                                            class="q-mr-xs"
                                        />
                                        Nie rozpoznano dostawcy
                                    </q-badge>
                                </div>
                            </div>
                        </div>

                        <!-- Provider instructions accordion -->
                        <q-expansion-item
                            v-if="resolvedProvider?.instructions"
                            icon="help_outline"
                            label="Jak przygotować i podłączyć skrzynkę?"
                            header-class="text-primary text-weight-medium bg-blue-1 rounded-borders"
                            class="overflow-hidden rounded-borders border"
                            default-opened
                        >
                            <q-card class="bg-blue-1 q-pa-sm">
                                <div class="text-subtitle2 text-primary q-mb-xs">
                                    {{ resolvedProvider.instructions.title }}
                                </div>
                                <ol class="q-my-xs q-pl-md text-body2">
                                    <li
                                        v-for="(step, index) in resolvedProvider
                                            .instructions.steps"
                                        :key="index"
                                        class="q-mb-xs"
                                    >
                                        {{ step }}
                                    </li>
                                </ol>
                                <div v-if="resolvedProvider.doc_url" class="q-mt-sm">
                                    <a
                                        :href="resolvedProvider.doc_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-link text-weight-medium inline-block"
                                    >
                                        Otwórz oficjalną instrukcję {{ resolvedProvider.name }}
                                        <q-icon name="open_in_new" size="14px" class="q-ml-xs" />
                                    </a>
                                </div>
                            </q-card>
                        </q-expansion-item>

                        <!-- GMAIL OAUTH SECTION (NO PASSWORD FIELD) -->
                        <div v-if="isGmailProvider" class="q-gutter-y-sm">
                            <div v-if="oauthPolling" class="q-pa-md bg-blue-1 rounded-borders text-center">
                                <q-spinner size="36px" color="primary" class="q-mb-sm" />
                                <div class="text-weight-medium text-primary">
                                    {{ oauthStatusMessage }}
                                </div>
                                <div class="text-caption text-grey-8 q-mt-xs">
                                    Autoryzacja odbywa się w Twojej domyślnej przeglądarce. Pamiętaj, aby zezwolić na dostęp do Gmaila.
                                </div>
                                <div v-if="oauthAuthUrl" class="q-mt-sm">
                                    <a
                                        :href="oauthAuthUrl"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-link text-caption"
                                    >
                                        Kliknij tutaj, jeśli okno przeglądarki nie otworzyło się automatycznie
                                    </a>
                                </div>
                                <div class="q-mt-md">
                                    <q-btn
                                        flat
                                        dense
                                        color="negative"
                                        label="Anuluj oczekiwanie"
                                        @click="stopOAuthPolling"
                                    />
                                </div>
                            </div>

                            <div v-else-if="!editingAccount">
                                <div class="q-pa-sm bg-grey-2 rounded-borders text-body2 q-mb-sm">
                                    <div class="row items-center q-gutter-x-xs text-weight-medium text-grey-9">
                                        <q-icon name="lock" color="primary" size="18px" />
                                        <span>Brak konieczności podawania hasła</span>
                                    </div>
                                    <div class="text-caption text-grey-7 q-mt-xs">
                                        RecruitFlow łączy się z kontem Gmail za pomocą bezpiecznego protokołu Google OAuth2 + PKCE. Twoje hasło nigdy nie trafia do aplikacji.
                                    </div>
                                </div>

                                <q-btn
                                    unelevated
                                    color="primary"
                                    class="full-width q-py-sm"
                                    icon="security"
                                    label="Połącz z Google"
                                    :loading="oauthLoading"
                                    @click="startGoogleOAuth"
                                />
                            </div>

                            <div v-else class="q-gutter-y-sm">
                                <div class="q-pa-sm bg-grey-2 rounded-borders text-body2">
                                    <div class="row items-center justify-between">
                                        <span class="text-weight-medium">Połączenie OAuth2:</span>
                                        <q-badge color="positive">Aktywne powiązanie</q-badge>
                                    </div>
                                    <div v-if="editingAccount.token_expires_at" class="text-caption text-grey-7 q-mt-xs">
                                        Ważność tokena: {{ formatDateTime(editingAccount.token_expires_at) }}
                                    </div>
                                </div>

                                <div class="row q-gutter-sm">
                                    <q-btn
                                        outline
                                        color="primary"
                                        class="col"
                                        icon="refresh"
                                        label="Połącz ponownie z Google"
                                        :loading="oauthLoading"
                                        @click="startGoogleOAuth"
                                    />
                                    <q-btn
                                        outline
                                        color="negative"
                                        class="col"
                                        icon="link_off"
                                        label="Odłącz Gmail"
                                        @click="confirmDisconnectOAuth(editingAccount)"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- STANDARD IMAP PASSWORD INPUT (FOR NON-GMAIL PROVIDERS) -->
                        <q-input
                            v-else
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            outlined
                            label="Hasło"
                            :hint="
                                editingAccount
                                    ? 'Pozostaw puste, aby nie zmieniać hasła'
                                    : undefined
                            "
                            :rules="[
                                (val: string) =>
                                    !!editingAccount ||
                                    (!!val && val.length > 0) ||
                                    'Hasło jest wymagane.',
                            ]"
                            :disable="saving || testingConnection"
                        >
                            <template #append>
                                <q-icon
                                    :name="
                                        showPassword
                                            ? 'visibility_off'
                                            : 'visibility'
                                    "
                                    class="cursor-pointer"
                                    @click="showPassword = !showPassword"
                                />
                            </template>
                        </q-input>

                        <!-- Connection test status in modal -->
                        <div v-if="connectionTestResult" class="q-mt-sm">
                            <q-banner
                                v-if="connectionTestResult.success"
                                class="bg-positive text-white rounded-borders"
                            >
                                <template #avatar>
                                    <q-icon name="check_circle" />
                                </template>
                                <div>Połączenie z serwerem pocztowym powiodło się!</div>
                                <div class="text-caption">
                                    {{ isGmailProvider ? 'Pomyślnie zweryfikowano uprawnienia profilu Gmail API.' : 'Uwierzytelnienie IMAP zakończone sukcesem.' }}
                                </div>
                            </q-banner>
                            <q-banner
                                v-else
                                class="bg-negative text-white rounded-borders"
                            >
                                <template #avatar>
                                    <q-icon name="error_outline" />
                                </template>
                                <div class="text-weight-bold">Błąd połączenia:</div>
                                <div>{{ connectionTestResult.error || connectionTestResult.message }}</div>
                                <div class="q-mt-xs text-caption bg-red-10 q-pa-xs rounded-borders">
                                    {{ getErrorHint(connectionTestResult.error_code) }}
                                </div>
                            </q-banner>
                        </div>
                    </q-card-section>

                    <q-card-section class="row items-center justify-between q-gutter-sm">
                        <!-- Left side test button if editing an existing account -->
                        <div>
                            <q-btn
                                v-if="editingAccount && isSupportedProvider"
                                outline
                                color="primary"
                                icon="network_check"
                                :label="isGmailProvider ? 'Testuj Gmail API' : 'Testuj połączenie'"
                                :loading="testingConnection"
                                :disable="saving || oauthPolling"
                                @click="testCurrentFormConnection"
                            />
                        </div>

                        <!-- Right side action buttons -->
                        <div class="row q-gutter-sm">
                            <q-btn
                                flat
                                label="Zamknij"
                                v-close-popup
                                :disable="saving || testingConnection || oauthPolling"
                            />
                            <template v-if="!isGmailProvider">
                                <q-btn
                                    v-if="!editingAccount && isSupportedProvider"
                                    outline
                                    color="primary"
                                    label="Zapisz i testuj"
                                    :loading="saving || testingConnection"
                                    @click="saveAccount(true)"
                                />
                                <q-btn
                                    type="submit"
                                    unelevated
                                    color="primary"
                                    :label="editingAccount ? 'Zapisz zmiany' : 'Dodaj konto'"
                                    :loading="saving"
                                />
                            </template>
                        </div>
                    </q-card-section>
                </q-form>
            </q-card>
        </q-dialog>
    </section>
</template>
