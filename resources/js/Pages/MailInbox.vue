<script setup lang="ts">
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Dialog, Notify } from 'quasar';
import PageHeader from '../components/PageHeader.vue';
import { mailInboxService } from '../services/mailInbox.ts';
import { mailAccountsService } from '../services/mailAccounts.ts';
import { formatDateTime } from '../domain/recruitment.ts';
import type { MailAccount } from '../types/mailAccounts.ts';
import type {
    MailMessage,
    MailMessageCategory,
    MailMessageCounts,
    MailMessageStatus,
    MailRuleAction,
    MailSyncAccountItem,
    MailSyncRule,
    SaveMailRulePayload,
} from '../types/mailInbox.ts';

const activeTab = ref<'messages' | 'rules' | 'sync'>('messages');

// ==========================================
// Accounts & Common
// ==========================================
const accounts = ref<MailAccount[]>([]);
const loadingAccounts = ref(false);

async function loadAccounts(): Promise<void> {
    loadingAccounts.value = true;
    try {
        accounts.value = await mailAccountsService.list();
    } catch (err: unknown) {
        console.error('Błąd ładowania kont pocztowych:', err);
    } finally {
        loadingAccounts.value = false;
    }
}

// ==========================================
// Tab 1: Messages
// ==========================================
const messages = ref<MailMessage[]>([]);
const loadingMessages = ref(false);
const messageCounts = ref<MailMessageCounts>({
    total: 0,
    new: 0,
    to_review: 0,
    relevant: 0,
    ignored: 0,
    job_offer: 0,
    recruitment: 0,
});

const messageFilters = reactive({
    search: '',
    mail_account_id: null as string | null,
    category: null as MailMessageCategory | null,
    status: null as MailMessageStatus | null,
});

const accountOptions = computed(() => {
    return [
        { label: 'Wszystkie konta', value: null },
        ...accounts.value.map((acc) => ({
            label: `${acc.name ? `${acc.name} (${acc.email})` : acc.email}`,
            value: acc.id,
        })),
    ];
});

const categoryOptions = [
    { label: 'Wszystkie kategorie', value: null },
    { label: 'Oferta pracy', value: 'job_offer' },
    { label: 'Rekrutacja', value: 'recruitment' },
    { label: 'Inne', value: 'other' },
];

const statusOptions = [
    { label: 'Wszystkie statusy', value: null },
    { label: 'Do sprawdzenia', value: 'to_review' },
    { label: 'Istotna', value: 'relevant' },
    { label: 'Nowa', value: 'new' },
    { label: 'Zignorowana', value: 'ignored' },
];

async function loadMessages(silent: boolean = false): Promise<void> {
    if (!silent) {
        loadingMessages.value = true;
    }
    try {
        const response = await mailInboxService.getMessages({
            mail_account_id: messageFilters.mail_account_id || undefined,
            category: messageFilters.category || undefined,
            status: messageFilters.status || undefined,
            search: messageFilters.search.trim() || undefined,
        });
        messages.value = response.messages;
        messageCounts.value = response.counts;
    } catch (err: unknown) {
        if (!silent) {
            Notify.create({
                type: 'negative',
                message: 'Nie udało się pobrać wiadomości pocztowych.',
            });
        }
    } finally {
        if (!silent) {
            loadingMessages.value = false;
        }
    }
}

function resetMessageFilters(): void {
    messageFilters.search = '';
    messageFilters.mail_account_id = null;
    messageFilters.category = null;
    messageFilters.status = null;
    void loadMessages();
}

async function updateMessageStatus(
    id: string,
    status: MailMessageStatus
): Promise<void> {
    try {
        const res = await mailInboxService.updateMessageStatus(id, status);
        const idx = messages.value.findIndex((m) => m.id === id);
        if (idx !== -1) {
            messages.value[idx] = res.mail_message;
        }
        Notify.create({
            type: 'positive',
            message: `Wiadomość oznaczona jako: ${
                status === 'relevant'
                    ? 'istotna'
                    : status === 'ignored'
                    ? 'zignorowana'
                    : 'do sprawdzenia'
            }.`,
            timeout: 2000,
        });
        // Refresh counts
        void loadMessages();
    } catch (err: unknown) {
        Notify.create({
            type: 'negative',
            message: 'Nie udało się zaktualizować statusu wiadomości.',
        });
    }
}

// ==========================================
// Message Detail Dialog & Body Fetching
// ==========================================
const selectedMessage = ref<MailMessage | null>(null);
const messageDetailOpen = ref(false);
const fetchingBody = ref(false);
const bodyViewTab = ref<'html' | 'text'>('html');
const scopeReauthRequired = ref(false);
const reconnectingGoogle = ref(false);

function formatFileSize(bytes?: number): string {
    if (!bytes || bytes <= 0) return '0 B';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

async function openMessageDetail(item: MailMessage): Promise<void> {
    selectedMessage.value = item;
    scopeReauthRequired.value = false;
    messageDetailOpen.value = true;
    bodyViewTab.value = item.body_html ? 'html' : 'text';

    // If body not yet fetched, automatically attempt to fetch it
    if (!item.body_text && !item.body_html) {
        await fetchSelectedMessageBody();
    }
}

async function fetchSelectedMessageBody(): Promise<void> {
    if (!selectedMessage.value) return;

    fetchingBody.value = true;
    scopeReauthRequired.value = false;

    try {
        const res = await mailInboxService.fetchMessageBody(selectedMessage.value.id);
        if (res.mail_message) {
            selectedMessage.value = res.mail_message;
            const idx = messages.value.findIndex((m) => m.id === res.mail_message.id);
            if (idx !== -1) {
                messages.value[idx] = res.mail_message;
            }
        }

        if (res.requires_reauth) {
            scopeReauthRequired.value = true;
        } else if (res.success) {
            bodyViewTab.value = res.mail_message.body_html ? 'html' : 'text';
            Notify.create({
                type: 'positive',
                message: 'Pobrano treść wiadomości.',
                timeout: 2500,
            });
        }
    } catch (err: unknown) {
        Notify.create({
            type: 'negative',
            message: err instanceof Error ? err.message : 'Nie udało się pobrać treści wiadomości.',
        });
    } finally {
        fetchingBody.value = false;
    }
}

async function updateDetailMessageStatus(status: MailMessageStatus): Promise<void> {
    if (!selectedMessage.value) return;
    await updateMessageStatus(selectedMessage.value.id, status);
    selectedMessage.value.status = status;
}

async function reconnectGoogleFromDetail(): Promise<void> {
    reconnectingGoogle.value = true;
    try {
        const res = await mailAccountsService.connectGoogle(selectedMessage.value?.mail_account?.email || undefined);
        if (res.authorization_url && typeof window !== 'undefined') {
            window.open(res.authorization_url, '_blank');
        }
        Notify.create({
            type: 'info',
            message: 'Otwarto okno logowania Google. Dokończ autoryzację w przeglądarce, aby przyznać uprawnienie do odczytu poczty.',
            timeout: 5000,
        });
    } catch (err: unknown) {
        Notify.create({
            type: 'negative',
            message: err instanceof Error ? err.message : 'Nie udało się rozpocząć ponownego łączenia z Google.',
        });
    } finally {
        reconnectingGoogle.value = false;
    }
}

// ==========================================
// Tab 2: Rules
// ==========================================
const rules = ref<MailSyncRule[]>([]);
const loadingRules = ref(false);
const ruleFilterType = ref<'all' | 'system' | 'custom'>('all');
const ruleSearch = ref('');

const ruleDialog = ref(false);
const editingRule = ref<MailSyncRule | null>(null);
const savingRule = ref(false);
const ruleFormError = ref('');

const ruleForm = reactive({
    name: '',
    mail_account_id: null as string | null,
    portal_name: '',
    sender_email: '',
    sender_domain: '',
    subject_keywords_raw: '',
    category: 'recruitment' as MailMessageCategory,
    action: 'qualify' as MailRuleAction,
    priority: 100,
    is_active: true,
});

async function loadRules(): Promise<void> {
    loadingRules.value = true;
    try {
        const response = await mailInboxService.getRules();
        rules.value = response.rules;
    } catch (err: unknown) {
        Notify.create({
            type: 'negative',
            message: 'Nie udało się pobrać reguł kwalifikacji.',
        });
    } finally {
        loadingRules.value = false;
    }
}

const filteredRules = computed(() => {
    let result = rules.value;

    if (ruleFilterType.value === 'system') {
        result = result.filter((r) => r.is_system);
    } else if (ruleFilterType.value === 'custom') {
        result = result.filter((r) => !r.is_system);
    }

    if (ruleSearch.value.trim()) {
        const q = ruleSearch.value.toLowerCase().trim();
        result = result.filter(
            (r) =>
                r.name.toLowerCase().includes(q) ||
                (r.portal_name && r.portal_name.toLowerCase().includes(q)) ||
                (r.sender_domain && r.sender_domain.toLowerCase().includes(q)) ||
                (r.sender_email && r.sender_email.toLowerCase().includes(q)) ||
                (r.subject_keywords &&
                    r.subject_keywords.some((kw) => kw.toLowerCase().includes(q)))
        );
    }

    return result;
});

function openAddRuleDialog(): void {
    editingRule.value = null;
    ruleFormError.value = '';
    ruleForm.name = '';
    ruleForm.mail_account_id = null;
    ruleForm.portal_name = '';
    ruleForm.sender_email = '';
    ruleForm.sender_domain = '';
    ruleForm.subject_keywords_raw = '';
    ruleForm.category = 'recruitment';
    ruleForm.action = 'qualify';
    ruleForm.priority = 100;
    ruleForm.is_active = true;
    ruleDialog.value = true;
}

function openEditRuleDialog(rule: MailSyncRule): void {
    if (rule.is_system) {
        Notify.create({
            type: 'info',
            message:
                'Reguły systemowe są wbudowane. Możesz je włączyć lub wyłączyć przełącznikiem.',
        });
        return;
    }
    editingRule.value = rule;
    ruleFormError.value = '';
    ruleForm.name = rule.name;
    ruleForm.mail_account_id = rule.mail_account_id;
    ruleForm.portal_name = rule.portal_name || '';
    ruleForm.sender_email = rule.sender_email || '';
    ruleForm.sender_domain = rule.sender_domain || '';
    ruleForm.subject_keywords_raw = (rule.subject_keywords || []).join(', ');
    ruleForm.category = rule.category;
    ruleForm.action = rule.action;
    ruleForm.priority = rule.priority;
    ruleForm.is_active = rule.is_active;
    ruleDialog.value = true;
}

async function saveRule(): Promise<void> {
    ruleFormError.value = '';
    if (!ruleForm.name.trim()) {
        ruleFormError.value = 'Podaj nazwę reguły.';
        return;
    }

    const keywords = ruleForm.subject_keywords_raw
        .split(',')
        .map((k) => k.trim())
        .filter((k) => k.length > 0);

    const hasSender =
        ruleForm.sender_email.trim().length > 0 ||
        ruleForm.sender_domain.trim().length > 0;
    const hasKeywords = keywords.length > 0;

    if (!hasSender && !hasKeywords) {
        ruleFormError.value =
            'Podaj co najmniej jeden warunek: adres e-mail nadawcy, domenę lub słowa kluczowe w temacie.';
        return;
    }

    const payload: SaveMailRulePayload = {
        name: ruleForm.name.trim(),
        mail_account_id: ruleForm.mail_account_id || null,
        portal_name: ruleForm.portal_name.trim() || null,
        sender_email: ruleForm.sender_email.trim() || null,
        sender_domain: ruleForm.sender_domain.trim() || null,
        subject_keywords: keywords.length > 0 ? keywords : null,
        category: ruleForm.category,
        action: ruleForm.action,
        priority: Number(ruleForm.priority) || 100,
        is_active: ruleForm.is_active,
    };

    savingRule.value = true;
    try {
        if (editingRule.value) {
            await mailInboxService.updateRule(editingRule.value.id, payload);
            Notify.create({
                type: 'positive',
                message: 'Reguła została zaktualizowana.',
            });
        } else {
            await mailInboxService.createRule(payload);
            Notify.create({
                type: 'positive',
                message: 'Nowa reguła została pomyślnie dodana.',
            });
        }
        ruleDialog.value = false;
        void loadRules();
    } catch (err: unknown) {
        ruleFormError.value =
            (err as { message?: string })?.message ||
            'Wystąpił błąd podczas zapisywania reguły.';
    } finally {
        savingRule.value = false;
    }
}

async function toggleRule(rule: MailSyncRule): Promise<void> {
    try {
        const res = await mailInboxService.toggleRule(rule.id);
        rule.is_active = res.rule.is_active;
        Notify.create({
            type: 'positive',
            message: rule.is_active
                ? `Włączono regułę: ${rule.name}`
                : `Wyłączono regułę: ${rule.name}`,
            timeout: 1500,
        });
    } catch (err: unknown) {
        Notify.create({
            type: 'negative',
            message: 'Nie udało się zmienić stanu reguły.',
        });
    }
}

function confirmDeleteRule(rule: MailSyncRule): void {
    if (rule.is_system) {
        Notify.create({
            type: 'warning',
            message: 'Reguły systemowe nie mogą zostać usunięte.',
        });
        return;
    }

    Dialog.create({
        title: 'Usuń regułę',
        message: `Czy na pewno chcesz usunąć regułę „${rule.name}”?`,
        cancel: { label: 'Anuluj', flat: true },
        ok: { label: 'Usuń', color: 'negative', unelevated: true },
    }).onOk(() => {
        void (async () => {
            try {
                await mailInboxService.deleteRule(rule.id);
                Notify.create({
                    type: 'positive',
                    message: 'Reguła została usunięta.',
                });
                void loadRules();
            } catch (err: unknown) {
                Notify.create({
                    type: 'negative',
                    message: 'Nie udało się usunąć reguły.',
                });
            }
        })();
    });
}

// ==========================================
// Tab 3: Sync
// ==========================================
const syncAccounts = ref<MailSyncAccountItem[]>([]);
const loadingSyncStates = ref(false);
const syncingAccountIds = ref<Record<string, boolean>>({});
const toggleAutoSyncLoading = ref<Record<string, boolean>>({});

async function loadSyncStates(silent: boolean = false): Promise<void> {
    if (!silent) {
        loadingSyncStates.value = true;
    }
    try {
        const response = await mailInboxService.getSyncStates();
        syncAccounts.value = response.accounts;
    } catch (err: unknown) {
        if (!silent) {
            Notify.create({
                type: 'negative',
                message: 'Nie udało się pobrać statusu synchronizacji.',
            });
        }
    } finally {
        if (!silent) {
            loadingSyncStates.value = false;
        }
    }
}

async function toggleAccountAutoSync(item: MailSyncAccountItem): Promise<void> {
    const accountId = item.account.id;
    toggleAutoSyncLoading.value[accountId] = true;
    try {
        const res = await mailInboxService.toggleAutoSync(accountId, item.account.auto_sync);
        item.account.auto_sync = res.auto_sync ?? false;
        Notify.create({
            type: 'positive',
            message: item.account.auto_sync
                ? `Włączono automatyczną synchronizację (co 5 minut) dla ${item.account.email}.`
                : `Wyłączono automatyczną synchronizację dla ${item.account.email}.`,
            timeout: 2500,
        });
    } catch (err: unknown) {
        // Revert toggle state on error
        item.account.auto_sync = !item.account.auto_sync;
        Notify.create({
            type: 'negative',
            message: 'Nie udało się zmienić trybu automatycznej synchronizacji.',
        });
    } finally {
        toggleAutoSyncLoading.value[accountId] = false;
    }
}

async function runManualSync(item: MailSyncAccountItem): Promise<void> {
    const accountId = item.account.id;
    if (syncingAccountIds.value[accountId]) {
        return;
    }

    syncingAccountIds.value[accountId] = true;
    item.sync_state.sync_status = 'syncing';

    try {
        const response = await mailInboxService.syncAccount(accountId);
        if (response.sync_state) {
            item.sync_state = response.sync_state;
        }
        Notify.create({
            type: 'positive',
            message: response.message || 'Synchronizacja zakończona sukcesem.',
            timeout: 3500,
        });
        // Refresh data seamlessly
        void loadMessages(true);
        void loadSyncStates(true);
    } catch (err: unknown) {
        const msg = err instanceof Error
            ? err.message
            : (typeof err === 'object' && err !== null && 'message' in err
                ? String((err as { message: unknown }).message)
                : 'Błąd podczas synchronizacji skrzynki.');

        Notify.create({
            type: 'negative',
            message: msg,
            timeout: 4500,
        });
        void loadSyncStates(true);
    } finally {
        syncingAccountIds.value[accountId] = false;
    }
}

// Background auto-refresh timer (every 15s when window is active)
let autoRefreshTimer: ReturnType<typeof setInterval> | null = null;

function startAutoRefresh(): void {
    stopAutoRefresh();
    autoRefreshTimer = setInterval(() => {
        if (document.hidden) {
            return;
        }
        if (activeTab.value === 'messages') {
            void loadMessages(true);
        } else if (activeTab.value === 'sync') {
            void loadSyncStates(true);
        }
    }, 15000);
}

function stopAutoRefresh(): void {
    if (autoRefreshTimer !== null) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }
}

// Initial Load & Lifecycle
onMounted(() => {
    void loadAccounts();
    void loadMessages();
    void loadRules();
    void loadSyncStates();
    startAutoRefresh();
});

onUnmounted(() => {
    stopAutoRefresh();
});
</script>

<template>
    <Head title="Poczta rekrutacyjna" />
    <PageHeader
        title="Poczta rekrutacyjna"
        subtitle="Struktura i reguły kwalifikacji wiadomości z portali pracy oraz korespondencji rekrutacyjnej."
    >
        <div class="row items-center q-gutter-sm">
            <q-btn
                v-if="activeTab === 'rules'"
                color="primary"
                unelevated
                icon="add"
                label="Dodaj regułę"
                @click="openAddRuleDialog"
            />
            <q-btn
                flat
                round
                dense
                icon="refresh"
                aria-label="Odśwież"
                @click="
                    activeTab === 'messages'
                        ? loadMessages()
                        : activeTab === 'rules'
                        ? loadRules()
                        : loadSyncStates()
                "
            >
                <q-tooltip>Odśwież dane</q-tooltip>
            </q-btn>
        </div>
    </PageHeader>

    <div class="mail-inbox-container">
        <!-- Tabs Navigation -->
        <q-tabs
            v-model="activeTab"
            align="left"
            active-color="primary"
            indicator-color="primary"
            no-caps
            class="mail-nav-tabs q-mb-md"
        >
            <q-tab
                name="messages"
                icon="mail"
                :label="`Wiadomości (${messageCounts.total})`"
            />
            <q-tab
                name="rules"
                icon="tune"
                :label="`Reguły (${rules.length})`"
            />
            <q-tab
                name="sync"
                icon="sync"
                :label="`Synchronizacja (${syncAccounts.length})`"
            />
        </q-tabs>

        <!-- ========================================== -->
        <!-- TAB 1: WIADOMOŚCI                          -->
        <!-- ========================================== -->
        <div v-if="activeTab === 'messages'">
            <!-- Metric Counters Row -->
            <div class="metric-grid q-mb-md">
                <div class="metric-card">
                    <div class="metric-label">Wszystkie</div>
                    <div class="metric-value">{{ messageCounts.total }}</div>
                </div>
                <div class="metric-card">
                    <div class="metric-label text-warning">Do sprawdzenia</div>
                    <div class="metric-value text-warning">
                        {{ messageCounts.to_review }}
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-label text-positive">Istotne</div>
                    <div class="metric-value text-positive">
                        {{ messageCounts.relevant }}
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-label text-grey">Zignorowane</div>
                    <div class="metric-value text-grey">
                        {{ messageCounts.ignored }}
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-label text-primary">Oferty pracy</div>
                    <div class="metric-value text-primary">
                        {{ messageCounts.job_offer }}
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-label text-purple">Rekrutacja</div>
                    <div class="metric-value text-purple">
                        {{ messageCounts.recruitment }}
                    </div>
                </div>
            </div>

            <!-- Filter Toolbar -->
            <section class="panel q-pa-md q-mb-md">
                <div class="row q-col-gutter-sm items-center">
                    <div class="col-12 col-md-4">
                        <q-input
                            v-model="messageFilters.search"
                            outlined
                            dense
                            placeholder="Szukaj: nadawca, temat, domena..."
                            clearable
                            @update:model-value="() => loadMessages()"
                        >
                            <template #prepend>
                                <q-icon name="search" />
                            </template>
                        </q-input>
                    </div>

                    <div class="col-12 col-sm-6 col-md-3">
                        <q-select
                            v-model="messageFilters.mail_account_id"
                            :options="accountOptions"
                            emit-value
                            map-options
                            outlined
                            dense
                            label="Konto pocztowe"
                            @update:model-value="() => loadMessages()"
                        />
                    </div>

                    <div class="col-12 col-sm-6 col-md-2">
                        <q-select
                            v-model="messageFilters.category"
                            :options="categoryOptions"
                            emit-value
                            map-options
                            outlined
                            dense
                            label="Kategoria"
                            @update:model-value="() => loadMessages()"
                        />
                    </div>

                    <div class="col-12 col-sm-6 col-md-2">
                        <q-select
                            v-model="messageFilters.status"
                            :options="statusOptions"
                            emit-value
                            map-options
                            outlined
                            dense
                            label="Status"
                            @update:model-value="() => loadMessages()"
                        />
                    </div>

                    <div class="col-12 col-sm-6 col-md-1 text-right">
                        <q-btn
                            flat
                            dense
                            color="grey"
                            icon="clear_all"
                            label="Reset"
                            @click="resetMessageFilters"
                        />
                    </div>
                </div>
            </section>

            <!-- Messages Table or Empty State -->
            <section class="panel">
                <div v-if="loadingMessages" class="text-center q-pa-xl">
                    <q-spinner color="primary" size="3em" />
                    <p class="q-mt-sm text-grey">Ładowanie wiadomości...</p>
                </div>

                <div
                    v-else-if="messages.length === 0"
                    class="empty-state q-pa-xl text-center"
                >
                    <q-icon
                        name="mail_outline"
                        size="64px"
                        color="grey-5"
                        class="q-mb-md"
                    />
                    <div class="text-h6 text-weight-medium">
                        Brak wiadomości rekrutacyjnych
                    </div>
                    <p class="text-grey-7 q-mt-sm max-w-md text-center">
                        Nie znaleziono wiadomości spełniających bieżące kryteria filtrów lub reguł kwalifikacyjnych.
                        Przejdź do zakładki <strong>Synchronizacja</strong>, aby sprawdzić stan skrzynek lub uruchomić synchronizację teraz.
                    </p>
                    <div class="q-mt-md">
                        <q-btn
                            color="primary"
                            unelevated
                            icon="sync"
                            label="Przejdź do Synchronizacji"
                            @click="activeTab = 'sync'"
                        />
                    </div>
                </div>

                <div v-else class="table-responsive">
                    <table class="rf-table full-width">
                        <thead>
                            <tr>
                                <th>Nadawca</th>
                                <th>Temat</th>
                                <th>Kategoria</th>
                                <th>Status</th>
                                <th>Data</th>
                                <th class="text-right">Akcje</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in messages"
                                :key="item.id"
                                class="cursor-pointer mail-row-hover"
                                @click="openMessageDetail(item)"
                            >
                                <td>
                                    <div class="text-weight-bold">
                                        {{ item.sender_name || item.sender_email }}
                                    </div>
                                    <div class="text-caption text-grey">
                                        {{ item.sender_email }}
                                    </div>
                                    <q-badge
                                        v-if="item.sender_domain"
                                        color="blue-grey-2"
                                        text-color="blue-grey-9"
                                        class="q-mt-xs"
                                    >
                                        {{ item.sender_domain }}
                                    </q-badge>
                                </td>
                                <td>
                                    <div class="row items-center no-wrap">
                                        <div class="text-body2 text-weight-medium">
                                            {{ item.subject }}
                                        </div>
                                        <q-icon
                                            v-if="item.has_attachments || (item.attachments && item.attachments.length)"
                                            name="attach_file"
                                            size="14px"
                                            color="grey-6"
                                            class="q-ml-xs"
                                        >
                                            <q-tooltip>Wiadomość zawiera załączniki</q-tooltip>
                                        </q-icon>
                                    </div>
                                    <div
                                        v-if="item.snippet"
                                        class="text-caption text-grey ellipsis q-mt-xs"
                                        style="max-width: 480px"
                                    >
                                        {{ item.snippet }}
                                    </div>
                                    <div
                                        v-if="item.matched_rule"
                                        class="q-mt-xs"
                                    >
                                        <q-badge
                                            color="primary"
                                            outline
                                            class="q-mr-xs"
                                        >
                                            <q-icon
                                                name="filter_alt"
                                                size="12px"
                                                class="q-mr-xs"
                                            />
                                            {{ item.matched_rule.name }}
                                        </q-badge>
                                    </div>
                                </td>
                                <td>
                                    <q-badge
                                        v-if="item.category === 'job_offer'"
                                        color="indigo"
                                        label="Oferta pracy"
                                    />
                                    <q-badge
                                        v-else-if="item.category === 'recruitment'"
                                        color="purple"
                                        label="Rekrutacja"
                                    />
                                    <q-badge
                                        v-else
                                        color="grey-6"
                                        label="Inne"
                                    />
                                </td>
                                <td>
                                    <q-badge
                                        v-if="item.status === 'relevant'"
                                        color="positive"
                                        label="Istotna"
                                    />
                                    <q-badge
                                        v-else-if="item.status === 'to_review'"
                                        color="warning"
                                        label="Do sprawdzenia"
                                    />
                                    <q-badge
                                        v-else-if="item.status === 'new'"
                                        color="info"
                                        label="Nowa"
                                    />
                                    <q-badge
                                        v-else-if="item.status === 'ignored'"
                                        color="grey-6"
                                        label="Zignorowana"
                                    />
                                    <span
                                        v-if="item.is_manually_classified"
                                        class="q-ml-xs text-caption text-grey"
                                        title="Zmieniono ręcznie"
                                    >
                                        (ręcznie)
                                    </span>
                                </td>
                                <td>
                                    <div class="text-caption">
                                        {{ formatDateTime(item.sent_at) }}
                                    </div>
                                </td>
                                <td class="text-right">
                                    <q-btn-group flat>
                                        <q-btn
                                            flat
                                            dense
                                            round
                                            color="primary"
                                            icon="visibility"
                                            @click.stop="openMessageDetail(item)"
                                        >
                                            <q-tooltip>Podgląd wiadomości</q-tooltip>
                                        </q-btn>
                                        <q-btn
                                            v-if="item.status !== 'relevant'"
                                            flat
                                            dense
                                            round
                                            color="positive"
                                            icon="check_circle"
                                            @click.stop="
                                                updateMessageStatus(
                                                    item.id,
                                                    'relevant'
                                                )
                                            "
                                        >
                                            <q-tooltip
                                                >Oznacz jako istotną</q-tooltip
                                            >
                                        </q-btn>
                                        <q-btn
                                            v-if="item.status !== 'ignored'"
                                            flat
                                            dense
                                            round
                                            color="grey-7"
                                            icon="block"
                                            @click.stop="
                                                updateMessageStatus(
                                                    item.id,
                                                    'ignored'
                                                )
                                            "
                                        >
                                            <q-tooltip>Ignoruj</q-tooltip>
                                        </q-btn>
                                        <q-btn
                                            v-if="
                                                item.status === 'relevant' ||
                                                item.status === 'ignored'
                                            "
                                            flat
                                            dense
                                            round
                                            color="warning"
                                            icon="undo"
                                            @click.stop="
                                                updateMessageStatus(
                                                    item.id,
                                                    'to_review'
                                                )
                                            "
                                        >
                                            <q-tooltip
                                                >Cofnij do
                                                sprawdzenia</q-tooltip
                                            >
                                        </q-btn>
                                    </q-btn-group>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- ========================================== -->
        <!-- TAB 2: REGUŁY                              -->
        <!-- ========================================== -->
        <div v-else-if="activeTab === 'rules'">
            <!-- Priority and Info Banner -->
            <q-banner rounded class="bg-blue-1 text-blue-10 q-mb-md">
                <template #avatar>
                    <q-icon name="info" color="primary" />
                </template>
                <div class="text-weight-bold">
                    Zasada pierwszeństwa kwalifikacji:
                </div>
                <div>
                    1. Ręczna decyzja użytkownika &rarr; 2. Własne reguły (wg
                    priorytetu) &rarr; 3. Domyślne reguły systemowe.
                </div>
                <div class="text-caption q-mt-xs">
                    Wiadomości z portali nie zawsze są ofertami pracy (często to
                    powiadomienia lub newslettery). Domyślne reguły oznaczają je
                    jako potencjalnie istotne do weryfikacji.
                </div>
            </q-banner>

            <!-- Rules Filter Toolbar -->
            <section class="panel q-pa-md q-mb-md">
                <div class="row q-col-gutter-sm items-center">
                    <div class="col-12 col-md-5">
                        <q-input
                            v-model="ruleSearch"
                            outlined
                            dense
                            placeholder="Szukaj w regułach (nazwa, portal, domena, słowa)..."
                            clearable
                        >
                            <template #prepend>
                                <q-icon name="search" />
                            </template>
                        </q-input>
                    </div>

                    <div class="col-12 col-md-4">
                        <q-btn-toggle
                            v-model="ruleFilterType"
                            unelevated
                            toggle-color="primary"
                            :options="[
                                { label: 'Wszystkie', value: 'all' },
                                { label: 'Własne', value: 'custom' },
                                { label: 'Systemowe', value: 'system' },
                            ]"
                        />
                    </div>

                    <q-space />

                    <div class="col-12 col-md-3 text-right">
                        <q-btn
                            color="primary"
                            unelevated
                            icon="add"
                            label="Nowa reguła"
                            @click="openAddRuleDialog"
                        />
                    </div>
                </div>
            </section>

            <!-- Rules List -->
            <section class="panel">
                <div v-if="loadingRules" class="text-center q-pa-xl">
                    <q-spinner color="primary" size="3em" />
                    <p class="q-mt-sm text-grey">Ładowanie reguł...</p>
                </div>

                <div
                    v-else-if="filteredRules.length === 0"
                    class="empty-state q-pa-xl text-center"
                >
                    <q-icon
                        name="tune"
                        size="64px"
                        color="grey-5"
                        class="q-mb-md"
                    />
                    <div class="text-h6 text-weight-medium">
                        Brak pasujących reguł
                    </div>
                    <p class="text-grey-7 q-mt-sm">
                        Zmień kryteria wyszukiwania lub dodaj własną regułę.
                    </p>
                </div>

                <div v-else class="table-responsive">
                    <table class="rf-table full-width">
                        <thead>
                            <tr>
                                <th style="width: 70px">Aktywna</th>
                                <th>Nazwa i portal</th>
                                <th>Warunki dopasowania</th>
                                <th>Kategoria / Akcja</th>
                                <th>Priorytet</th>
                                <th class="text-right">Zarządzanie</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="rule in filteredRules"
                                :key="rule.id"
                                :class="{ 'row-disabled': !rule.is_active }"
                            >
                                <td>
                                    <q-toggle
                                        :model-value="rule.is_active"
                                        color="primary"
                                        @update:model-value="toggleRule(rule)"
                                    />
                                </td>
                                <td>
                                    <div class="row items-center q-gutter-xs">
                                        <q-badge
                                            v-if="rule.is_system"
                                            color="blue-grey-7"
                                            label="Systemowa"
                                        />
                                        <q-badge
                                            v-else
                                            color="primary"
                                            label="Własna"
                                        />
                                        <q-badge
                                            v-if="rule.portal_name"
                                            color="teal-7"
                                            :label="rule.portal_name"
                                        />
                                    </div>
                                    <div
                                        class="text-weight-bold text-body2 q-mt-xs"
                                    >
                                        {{ rule.name }}
                                    </div>
                                    <div
                                        v-if="rule.mail_account"
                                        class="text-caption text-grey"
                                    >
                                        Dotyczy konta:
                                        {{ rule.mail_account.email }}
                                    </div>
                                </td>
                                <td>
                                    <div
                                        v-if="rule.sender_domain"
                                        class="text-caption"
                                    >
                                        <span class="text-weight-medium"
                                            >Domena:</span
                                        >
                                        <code>{{ rule.sender_domain }}</code>
                                    </div>
                                    <div
                                        v-if="rule.sender_email"
                                        class="text-caption"
                                    >
                                        <span class="text-weight-medium"
                                            >E-mail:</span
                                        >
                                        <code>{{ rule.sender_email }}</code>
                                    </div>
                                    <div
                                        v-if="
                                            rule.subject_keywords &&
                                            rule.subject_keywords.length > 0
                                        "
                                        class="q-mt-xs"
                                    >
                                        <div class="text-caption text-weight-medium">
                                            Słowa w temacie:
                                        </div>
                                        <div class="row q-gutter-xs q-mt-xs">
                                            <q-badge
                                                v-for="kw in rule.subject_keywords"
                                                :key="kw"
                                                color="grey-3"
                                                text-color="grey-9"
                                                class="q-px-xs"
                                            >
                                                {{ kw }}
                                            </q-badge>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <q-badge
                                            v-if="rule.category === 'job_offer'"
                                            color="indigo"
                                            label="Oferta pracy"
                                        />
                                        <q-badge
                                            v-else-if="
                                                rule.category === 'recruitment'
                                            "
                                            color="purple"
                                            label="Rekrutacja"
                                        />
                                        <q-badge
                                            v-else
                                            color="grey-6"
                                            label="Inne"
                                        />
                                    </div>
                                    <div class="q-mt-xs">
                                        <q-badge
                                            v-if="rule.action === 'qualify'"
                                            color="positive"
                                            label="Zakwalifikuj"
                                        />
                                        <q-badge
                                            v-else
                                            color="negative"
                                            label="Ignoruj"
                                        />
                                    </div>
                                </td>
                                <td>
                                    <span class="text-weight-bold">{{
                                        rule.priority
                                    }}</span>
                                </td>
                                <td class="text-right">
                                    <template v-if="!rule.is_system">
                                        <q-btn
                                            flat
                                            dense
                                            round
                                            color="primary"
                                            icon="edit"
                                            @click="openEditRuleDialog(rule)"
                                        >
                                            <q-tooltip>Edytuj regułę</q-tooltip>
                                        </q-btn>
                                        <q-btn
                                            flat
                                            dense
                                            round
                                            color="negative"
                                            icon="delete"
                                            @click="confirmDeleteRule(rule)"
                                        >
                                            <q-tooltip>Usuń regułę</q-tooltip>
                                        </q-btn>
                                    </template>
                                    <template v-else>
                                        <q-icon
                                            name="lock"
                                            color="grey-5"
                                            size="18px"
                                        >
                                            <q-tooltip>
                                                Reguła systemowa: chroniona
                                                przed usunięciem i edycją
                                            </q-tooltip>
                                        </q-icon>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- ========================================== -->
        <!-- TAB 3: SYNCHRONIZACJA                      -->
        <!-- ========================================== -->
        <div v-else-if="activeTab === 'sync'">
            <!-- Information Banner: Privacy & Security -->
            <q-banner rounded class="bg-blue-1 text-blue-10 q-mb-md">
                <template #avatar>
                    <q-icon name="verified_user" color="primary" />
                </template>
                <div class="text-weight-bold text-subtitle2">
                    Prywatność i bezpieczeństwo synchronizacji poczty
                </div>
                <div class="text-caption q-mt-xs">
                    Synchronizowane są wyłącznie <strong>nagłówki wiadomości</strong> (nadawca, temat, data, Message-ID).
                    Treści wiadomości oraz załączniki <strong>nigdy nie są pobierane</strong> ani przechowywane na dysku.
                    Dla skrzynek z włączoną automatyczną synchronizacją sprawdzanie nowych wiadomości odbywa się <strong>co 5 minut</strong> w tle podczas działania aplikacji RecruitFlow.
                </div>
            </q-banner>

            <!-- Connected Accounts List -->
            <section class="panel q-pa-md q-mb-md">
                <div class="row items-center justify-between q-mb-md">
                    <div>
                        <div class="text-h6 text-weight-medium">
                            Skrzynki pocztowe
                        </div>
                        <div class="text-caption text-grey">
                            Zarządzaj automatyczną synchronizacją i wywołuj sprawdzanie ręcznie.
                        </div>
                    </div>
                    <Link href="/settings" class="text-link">
                        Zarządzaj kontami w Ustawieniach
                        <q-icon name="arrow_forward" />
                    </Link>
                </div>

                <div v-if="loadingSyncStates" class="text-center q-pa-lg">
                    <q-spinner color="primary" size="2em" />
                </div>

                <div
                    v-else-if="syncAccounts.length === 0"
                    class="empty-state q-pa-lg text-center"
                >
                    <q-icon name="mail" size="48px" color="grey-4" />
                    <div class="text-subtitle1 text-weight-medium q-mt-sm">
                        Brak podłączonych kont pocztowych
                    </div>
                    <p class="text-grey-7">
                        Przejdź do Ustawień, aby podłączyć skrzynkę Gmail lub
                        IMAP.
                    </p>
                    <Link href="/settings">
                        <q-btn
                            color="primary"
                            unelevated
                            label="Podłącz konto pocztowe"
                        />
                    </Link>
                </div>

                <div v-else class="row q-col-gutter-md">
                    <div
                        v-for="item in syncAccounts"
                        :key="item.account.id"
                        class="col-12 col-md-6"
                    >
                        <q-card bordered flat class="account-sync-card">
                            <q-card-section>
                                <!-- Card Header: Name, Email, Provider, Status -->
                                <div class="row items-center justify-between no-wrap">
                                    <div class="col ellipsis">
                                        <div class="row items-center q-gutter-xs">
                                            <q-icon
                                                :name="item.account.auth_type === 'oauth' ? 'mail' : 'alternate_email'"
                                                size="18px"
                                                color="primary"
                                                class="q-mr-xs"
                                            />
                                            <div class="text-weight-bold text-subtitle1 ellipsis">
                                                {{ item.account.name || item.account.email }}
                                            </div>
                                        </div>
                                        <div class="text-caption text-grey ellipsis">
                                            {{ item.account.email }} &middot;
                                            {{
                                                item.account.auth_type === 'oauth'
                                                    ? 'Gmail (Google API)'
                                                    : `IMAP (${item.account.provider ? item.account.provider.toUpperCase() : 'Poczta'})`
                                            }}
                                        </div>
                                    </div>

                                    <!-- Sync State Chip / Badge -->
                                    <div class="q-ml-sm text-right">
                                        <q-chip
                                            v-if="syncingAccountIds[item.account.id] || item.sync_state.sync_status === 'syncing'"
                                            color="primary"
                                            text-color="white"
                                            dense
                                            icon="sync"
                                            class="glossy"
                                        >
                                            <q-spinner size="12px" color="white" class="q-mr-xs" />
                                            Synchronizacja...
                                        </q-chip>
                                        <q-chip
                                            v-else-if="item.sync_state.sync_status === 'failed' || item.sync_state.last_error"
                                            color="negative"
                                            text-color="white"
                                            dense
                                            icon="error_outline"
                                        >
                                            Błąd
                                        </q-chip>
                                        <q-chip
                                            v-else-if="item.sync_state.last_synced_at"
                                            color="positive"
                                            text-color="white"
                                            dense
                                            icon="check_circle"
                                        >
                                            Sukces
                                        </q-chip>
                                        <q-chip
                                            v-else
                                            color="grey-6"
                                            text-color="white"
                                            dense
                                            icon="hourglass_empty"
                                        >
                                            Oczekiwanie
                                        </q-chip>
                                    </div>
                                </div>

                                <q-separator class="q-my-sm" />

                                <!-- Auto-sync Toggle and Manual Sync Action -->
                                <div class="account-sync-subpanel row items-center justify-between q-py-sm q-px-md rounded-borders q-mb-md">
                                    <div>
                                        <q-toggle
                                            v-model="item.account.auto_sync"
                                            color="primary"
                                            dense
                                            :disable="syncingAccountIds[item.account.id] || toggleAutoSyncLoading[item.account.id]"
                                            @update:model-value="toggleAccountAutoSync(item)"
                                        >
                                            <span class="text-weight-medium text-body2 q-ml-xs">Automatyczna synchronizacja</span>
                                        </q-toggle>
                                        <div class="text-caption text-grey-7 row items-center q-gutter-xs q-ml-lg">
                                            <q-icon name="schedule" size="14px" />
                                            <span>Sprawdzanie co 5 minut</span>
                                            <span v-if="!item.account.auto_sync" class="text-grey-5">(wyłączona)</span>
                                        </div>
                                    </div>

                                    <q-btn
                                        color="primary"
                                        unelevated
                                        no-caps
                                        class="q-px-md q-py-xs"
                                        icon="sync"
                                        label="Synchronizuj teraz"
                                        :loading="syncingAccountIds[item.account.id] || item.sync_state.sync_status === 'syncing'"
                                        :disable="!['active', 'connected'].includes(item.account.status)"
                                        @click="runManualSync(item)"
                                    >
                                        <q-tooltip v-if="!['active', 'connected'].includes(item.account.status)">Konto nie jest aktywne ani połączone</q-tooltip>
                                        <q-tooltip v-else>Pobierz nowe nagłówki wiadomości teraz</q-tooltip>
                                    </q-btn>
                                </div>

                                <!-- Metrics Grid -->
                                <div class="sync-metrics-grid">
                                    <div>
                                        <div class="text-caption text-grey">Ostatnia synchronizacja:</div>
                                        <div class="text-weight-medium">
                                            {{ item.sync_state.last_synced_at ? formatDateTime(item.sync_state.last_synced_at) : 'Brak (nigdy)' }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-caption text-grey">Wykryte wiadomości:</div>
                                        <div class="text-weight-bold text-primary">
                                            {{ item.sync_state.total_messages_count }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-caption text-grey">Istotne rekrutacyjnie:</div>
                                        <div class="text-weight-bold text-positive">
                                            {{ item.sync_state.relevant_messages_count }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-caption text-grey">Do sprawdzenia:</div>
                                        <div class="text-weight-bold text-warning">
                                            {{ item.sync_state.to_review_messages_count }}
                                        </div>
                                    </div>
                                </div>

                                <!-- Last Error Box if Present -->
                                <div
                                    v-if="item.sync_state.last_error"
                                    class="q-mt-sm q-pa-sm bg-red-1 text-negative rounded-borders row items-start q-gutter-xs text-caption"
                                >
                                    <q-icon name="warning" size="16px" class="q-mt-xs" />
                                    <div class="col">
                                        <div class="text-weight-bold">Ostatni błąd:</div>
                                        <div>{{ item.sync_state.last_error }}</div>
                                    </div>
                                </div>
                            </q-card-section>
                        </q-card>
                    </div>
                </div>
            </section>

            <!-- Schedule Status Info Section -->
            <section class="panel q-pa-md">
                <div class="row items-center q-gutter-sm q-mb-sm">
                    <q-icon name="schedule" size="24px" color="primary" />
                    <div class="text-h6 text-weight-medium">
                        Harmonogram NativePHP
                    </div>
                    <q-badge color="positive">Aktywny (co 5 minut)</q-badge>
                </div>
                <p class="text-grey-7 q-mb-xs">
                    RecruitFlow automatycznie sprawdza nowe wiadomości w tle dla wszystkich skrzynek
                    z włączoną opcją <strong>Automatyczna synchronizacja</strong>.
                </p>
                <div class="row q-gutter-md text-caption text-grey-8 q-mt-xs">
                    <div class="row items-center q-gutter-xs">
                        <q-icon name="check" color="positive" size="16px" />
                        <span>Działa w tle (również przy zminimalizowanym oknie)</span>
                    </div>
                    <div class="row items-center q-gutter-xs">
                        <q-icon name="lock" color="primary" size="16px" />
                        <span>Blokada atomowa zapobiega nakładaniu się synchronizacji</span>
                    </div>
                    <div class="row items-center q-gutter-xs">
                        <q-icon name="offline_bolt" color="indigo" size="16px" />
                        <span>Odporność na brak sieci i uśpienie komputera</span>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- DIALOG: DODAJ / EDYTUJ REGUŁĘ              -->
    <!-- ========================================== -->
    <q-dialog v-model="ruleDialog" persistent>
        <q-card style="min-width: 520px; max-width: 650px">
            <q-card-section class="row items-center q-pb-none">
                <div class="text-h6">
                    {{ editingRule ? 'Edytuj regułę' : 'Nowa reguła kwalifikacji' }}
                </div>
                <q-space />
                <q-btn flat round dense icon="close" v-close-popup />
            </q-card-section>

            <q-card-section class="q-pt-sm">
                <q-banner
                    v-if="ruleFormError"
                    class="bg-negative text-white q-mb-md"
                    rounded
                    dense
                >
                    {{ ruleFormError }}
                </q-banner>

                <q-form @submit.prevent="saveRule" class="q-gutter-md">
                    <q-input
                        v-model="ruleForm.name"
                        outlined
                        dense
                        label="Nazwa reguły *"
                        placeholder="np. Oferty No Fluff Jobs lub Odpowiedzi z firmy XYZ"
                    />

                    <div class="row q-col-gutter-sm">
                        <div class="col-12 col-sm-6">
                            <q-select
                                v-model="ruleForm.mail_account_id"
                                :options="accountOptions"
                                emit-value
                                map-options
                                outlined
                                dense
                                label="Konto pocztowe (opcjonalnie)"
                            />
                        </div>
                        <div class="col-12 col-sm-6">
                            <q-input
                                v-model="ruleForm.portal_name"
                                outlined
                                dense
                                label="Nazwa portalu / źródła (opcjonalnie)"
                                placeholder="np. Pracuj.pl, Just Join IT"
                            />
                        </div>
                    </div>

                    <div class="text-subtitle2 text-weight-medium q-mt-md">
                        Warunki dopasowania (podaj co najmniej jeden):
                    </div>

                    <div class="row q-col-gutter-sm">
                        <div class="col-12 col-sm-6">
                            <q-input
                                v-model="ruleForm.sender_domain"
                                outlined
                                dense
                                label="Domena nadawcy"
                                placeholder="np. pracuj.pl lub nofluffjobs.com"
                            />
                        </div>
                        <div class="col-12 col-sm-6">
                            <q-input
                                v-model="ruleForm.sender_email"
                                outlined
                                dense
                                label="Konkretny e-mail nadawcy"
                                placeholder="np. rekrutacja@firma.pl"
                            />
                        </div>
                    </div>

                    <q-input
                        v-model="ruleForm.subject_keywords_raw"
                        outlined
                        dense
                        label="Słowa kluczowe w temacie (rozdzielone przecinkami)"
                        placeholder="np. Zaproszenie na rozmowę, Dziękujemy za aplikację, Interview"
                    />

                    <div class="text-subtitle2 text-weight-medium q-mt-md">
                        Kwalifikacja i akcja:
                    </div>

                    <div class="row q-col-gutter-sm">
                        <div class="col-12 col-sm-6">
                            <q-select
                                v-model="ruleForm.category"
                                :options="[
                                    { label: 'Oferta pracy', value: 'job_offer' },
                                    { label: 'Rekrutacja', value: 'recruitment' },
                                    { label: 'Inne', value: 'other' },
                                ]"
                                emit-value
                                map-options
                                outlined
                                dense
                                label="Kategoria"
                            />
                        </div>
                        <div class="col-12 col-sm-6">
                            <q-select
                                v-model="ruleForm.action"
                                :options="[
                                    {
                                        label: 'Zakwalifikuj (jako potencjalnie istotną)',
                                        value: 'qualify',
                                    },
                                    {
                                        label: 'Ignoruj (oznacz jako zignorowaną)',
                                        value: 'ignore',
                                    },
                                ]"
                                emit-value
                                map-options
                                outlined
                                dense
                                label="Akcja"
                            />
                        </div>
                    </div>

                    <div class="row q-col-gutter-sm items-center">
                        <div class="col-12 col-sm-6">
                            <q-input
                                v-model.number="ruleForm.priority"
                                type="number"
                                min="1"
                                max="1000"
                                outlined
                                dense
                                label="Priorytet (wyższy = sprawdzany wcześniej)"
                            />
                        </div>
                        <div class="col-12 col-sm-6">
                            <q-toggle
                                v-model="ruleForm.is_active"
                                label="Reguła aktywna"
                                color="primary"
                            />
                        </div>
                    </div>

                    <div class="row justify-end q-gutter-sm q-mt-lg">
                        <q-btn flat label="Anuluj" v-close-popup />
                        <q-btn
                            unelevated
                            color="primary"
                            type="submit"
                            :loading="savingRule"
                            :label="editingRule ? 'Zapisz zmiany' : 'Dodaj regułę'"
                        />
                    </div>
                </q-form>
            </q-card-section>
        </q-card>
    </q-dialog>

    <!-- ========================================== -->
    <!-- DIALOG: PODGLĄD WIADOMOŚCI I TREŚCI        -->
    <!-- ========================================== -->
    <q-dialog v-model="messageDetailOpen" transition-show="scale" transition-hide="scale">
        <q-card v-if="selectedMessage" class="message-detail-card">
            <!-- Header -->
            <q-card-section class="row items-start no-wrap q-pb-xs">
                <div class="col">
                    <div class="row items-center q-gutter-xs q-mb-xs">
                        <q-badge
                            v-if="selectedMessage.category === 'job_offer'"
                            color="indigo"
                            label="Oferta pracy"
                        />
                        <q-badge
                            v-else-if="selectedMessage.category === 'recruitment'"
                            color="purple"
                            label="Rekrutacja"
                        />
                        <q-badge
                            v-else
                            color="grey-6"
                            label="Inne"
                        />

                        <q-badge
                            v-if="selectedMessage.status === 'relevant'"
                            color="positive"
                            label="Istotna"
                        />
                        <q-badge
                            v-else-if="selectedMessage.status === 'to_review'"
                            color="warning"
                            label="Do sprawdzenia"
                        />
                        <q-badge
                            v-else-if="selectedMessage.status === 'new'"
                            color="info"
                            label="Nowa"
                        />
                        <q-badge
                            v-else-if="selectedMessage.status === 'ignored'"
                            color="grey-6"
                            label="Zignorowana"
                        />
                        <span v-if="selectedMessage.is_manually_classified" class="text-caption text-grey">
                            (ręcznie)
                        </span>

                        <q-badge
                            v-if="selectedMessage.matched_rule"
                            color="primary"
                            outline
                            class="q-ml-xs"
                        >
                            <q-icon name="filter_alt" size="12px" class="q-mr-xs" />
                            {{ selectedMessage.matched_rule.name }}
                        </q-badge>
                    </div>

                    <div class="text-h6 text-weight-bold" style="line-height: 1.3">
                        {{ selectedMessage.subject }}
                    </div>
                </div>

                <q-btn flat round dense icon="close" v-close-popup class="q-ml-sm" />
            </q-card-section>

            <!-- Sender & Recipient Metadata Bar -->
            <q-card-section class="q-py-sm">
                <div class="row items-center justify-between q-col-gutter-sm">
                    <div class="col-12 col-md-8">
                        <div class="row items-center q-gutter-sm">
                            <q-avatar size="36px" color="primary" text-color="white" class="text-weight-bold">
                                {{ (selectedMessage.sender_name || selectedMessage.sender_email).charAt(0).toUpperCase() }}
                            </q-avatar>
                            <div>
                                <div class="text-weight-bold text-body2">
                                    {{ selectedMessage.sender_name || selectedMessage.sender_email }}
                                    <q-badge
                                        v-if="selectedMessage.sender_domain"
                                        color="blue-grey-2"
                                        text-color="blue-grey-9"
                                        class="q-ml-xs"
                                    >
                                        {{ selectedMessage.sender_domain }}
                                    </q-badge>
                                </div>
                                <div class="text-caption text-grey">
                                    <span>Od: {{ selectedMessage.sender_email }}</span>
                                    <span v-if="selectedMessage.to_email" class="q-ml-md">Do: {{ selectedMessage.to_email }}</span>
                                    <span v-if="selectedMessage.cc_email" class="q-ml-md">DW: {{ selectedMessage.cc_email }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4 text-right">
                        <div class="text-caption text-grey-8">
                            {{ formatDateTime(selectedMessage.sent_at) }}
                        </div>
                        <div v-if="selectedMessage.mail_account" class="text-caption text-primary">
                            {{ selectedMessage.mail_account.name || selectedMessage.mail_account.email }}
                        </div>
                    </div>
                </div>

                <!-- Action buttons inside dialog -->
                <div class="row items-center justify-between q-mt-sm q-pt-sm" style="border-top: 1px solid var(--border)">
                    <div class="row items-center q-gutter-xs">
                        <q-btn
                            unelevated
                            size="sm"
                            :color="selectedMessage.status === 'relevant' ? 'positive' : 'grey-8'"
                            :outline="selectedMessage.status !== 'relevant'"
                            icon="check_circle"
                            label="Istotna"
                            @click="updateDetailMessageStatus('relevant')"
                        />
                        <q-btn
                            unelevated
                            size="sm"
                            :color="selectedMessage.status === 'to_review' ? 'warning' : 'grey-8'"
                            :outline="selectedMessage.status !== 'to_review'"
                            icon="hourglass_empty"
                            label="Do sprawdzenia"
                            @click="updateDetailMessageStatus('to_review')"
                        />
                        <q-btn
                            unelevated
                            size="sm"
                            :color="selectedMessage.status === 'ignored' ? 'grey-7' : 'grey-8'"
                            :outline="selectedMessage.status !== 'ignored'"
                            icon="block"
                            label="Zignoruj"
                            @click="updateDetailMessageStatus('ignored')"
                        />
                    </div>

                    <div class="row items-center q-gutter-xs">
                        <!-- Mode toggle if both HTML and Text available -->
                        <q-btn-toggle
                            v-if="selectedMessage.body_html && selectedMessage.body_text"
                            v-model="bodyViewTab"
                            dense
                            rounded
                            unelevated
                            toggle-color="primary"
                            color="grey-9"
                            size="sm"
                            :options="[
                                { label: 'HTML', value: 'html' },
                                { label: 'Tekst', value: 'text' },
                            ]"
                        />

                        <q-btn
                            flat
                            dense
                            size="sm"
                            color="primary"
                            icon="download"
                            label="Pobierz treść ponownie"
                            :loading="fetchingBody"
                            @click="fetchSelectedMessageBody"
                        >
                            <q-tooltip>Wymuś ponowne pobranie treści z serwera</q-tooltip>
                        </q-btn>
                    </div>
                </div>
            </q-card-section>

            <!-- Re-auth Banner (if Google scope was metadata only) -->
            <q-banner v-if="scopeReauthRequired" rounded class="bg-amber-10 text-white q-mx-md q-my-xs">
                <template v-slot:avatar>
                    <q-icon name="warning" color="white" />
                </template>
                <div class="text-weight-bold">Wymagane uprawnienie do odczytu poczty (gmail.readonly)</div>
                <div class="text-caption q-mt-xs">
                    Konto Google posiada uprawnienie tylko do metadanych. Aby pobrać pełną treść HTML wiadomości i załączniki, połącz konto ponownie w celu nadania uprawnienia do odczytu poczty.
                </div>
                <template v-slot:action>
                    <q-btn
                        flat
                        color="white"
                        label="Połącz ponownie z Google"
                        icon="sync"
                        :loading="reconnectingGoogle"
                        @click="reconnectGoogleFromDetail"
                    />
                </template>
            </q-banner>

            <!-- Content Area -->
            <q-card-section class="col q-pt-none scroll" style="min-height: 280px; max-height: 58vh">
                <!-- Snippet callout if body is not loaded yet -->
                <div v-if="selectedMessage.snippet && (!selectedMessage.body_html && !selectedMessage.body_text)" class="q-mb-md">
                    <q-banner rounded class="bg-grey-9 text-grey-2">
                        <template v-slot:avatar>
                            <q-icon name="short_text" color="primary" />
                        </template>
                        <div class="text-weight-medium">Podgląd wiadomości:</div>
                        <div class="text-body2 q-mt-xs text-italic">{{ selectedMessage.snippet }}</div>
                    </q-banner>
                </div>

                <!-- If body is empty and not fetching -->
                <div
                    v-if="!selectedMessage.body_html && !selectedMessage.body_text && !fetchingBody"
                    class="column items-center justify-center q-pa-lg text-center"
                >
                    <q-icon name="mail_outline" size="48px" color="grey-6" class="q-mb-sm" />
                    <div class="text-subtitle1 text-weight-medium text-grey-4">
                        Treść wiadomości nie została jeszcze pobrana
                    </div>
                    <p class="text-caption text-grey-6 q-mb-md" style="max-width: 440px">
                        Kliknij poniższy przycisk, aby pobrać pełną zawartość e-maila ze skrzynki pocztowej.
                    </p>
                    <q-btn
                        unelevated
                        color="primary"
                        icon="download"
                        label="Pobierz treść wiadomości"
                        :loading="fetchingBody"
                        @click="fetchSelectedMessageBody"
                    />
                </div>

                <!-- Loading spinner -->
                <div v-else-if="fetchingBody && !selectedMessage.body_html && !selectedMessage.body_text" class="column items-center justify-center q-pa-xl text-center">
                    <q-spinner-dots color="primary" size="40px" />
                    <div class="text-caption text-grey q-mt-md">Pobieranie zawartości wiadomości z serwera...</div>
                </div>

                <!-- HTML View -->
                <div v-else-if="bodyViewTab === 'html' && selectedMessage.body_html" class="q-mb-md">
                    <iframe
                        class="mail-body-iframe"
                        :srcdoc="selectedMessage.body_html"
                        sandbox="allow-popups allow-popups-to-escape-sandbox"
                    ></iframe>
                </div>

                <!-- Plain Text View -->
                <div v-else-if="selectedMessage.body_text || selectedMessage.snippet" class="mail-body-text q-mb-md">
                    {{ selectedMessage.body_text || selectedMessage.snippet }}
                </div>

                <!-- Attachments Section -->
                <div v-if="selectedMessage.attachments && selectedMessage.attachments.length > 0" class="q-mt-md">
                    <div class="text-subtitle2 text-weight-bold text-grey-4 q-mb-xs row items-center">
                        <q-icon name="attach_file" size="18px" class="q-mr-xs text-primary" />
                        Załączniki ({{ selectedMessage.attachments.length }}):
                    </div>
                    <div class="row q-gutter-sm">
                        <div
                            v-for="(att, aIdx) in selectedMessage.attachments"
                            :key="aIdx"
                            class="attachment-chip row items-center q-pa-xs q-px-sm"
                        >
                            <q-icon
                                :name="
                                    att.mime_type?.includes('pdf')
                                        ? 'picture_as_pdf'
                                        : att.mime_type?.includes('image')
                                        ? 'image'
                                        : 'description'
                                "
                                size="18px"
                                color="primary"
                                class="q-mr-xs"
                            />
                            <div>
                                <div class="text-weight-bold text-caption ellipsis" style="max-width: 180px">
                                    {{ att.name }}
                                </div>
                                <div class="text-caption text-grey" style="font-size: 11px">
                                    {{ formatFileSize(att.size) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Technical Details Accordion -->
                <q-expansion-item
                    dense
                    dense-toggle
                    expand-separator
                    icon="info"
                    label="Szczegóły techniczne i identyfikatory"
                    header-class="text-grey-6 text-caption"
                    class="q-mt-md"
                >
                    <q-card class="bg-transparent text-caption text-grey-5 q-pa-sm">
                        <div><strong>ID wiadomości:</strong> {{ selectedMessage.id }}</div>
                        <div><strong>Provider Message ID:</strong> {{ selectedMessage.provider_message_id }}</div>
                        <div v-if="selectedMessage.thread_id"><strong>Thread ID:</strong> {{ selectedMessage.thread_id }}</div>
                        <div v-if="selectedMessage.message_id"><strong>RFC Message-ID:</strong> {{ selectedMessage.message_id }}</div>
                        <div v-if="selectedMessage.reply_to"><strong>Reply-To:</strong> {{ selectedMessage.reply_to }}</div>
                        <div v-if="selectedMessage.body_fetched_at"><strong>Treść pobrano:</strong> {{ formatDateTime(selectedMessage.body_fetched_at) }}</div>
                    </q-card>
                </q-expansion-item>
            </q-card-section>

            <!-- Dialog Footer -->
            <q-card-actions align="right" class="q-px-md q-py-sm" style="border-top: 1px solid var(--border)">
                <q-btn flat label="Zamknij" color="grey-5" v-close-popup />
            </q-card-actions>
        </q-card>
    </q-dialog>
</template>

<style scoped>
.mail-inbox-container {
    max-width: 1200px;
    margin: 0 auto;
}

.metric-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
}

.metric-card {
    background: var(--q-panel-bg, #ffffff);
    border: 1px solid var(--q-separator-color, #e2e8f0);
    border-radius: 8px;
    padding: 12px 16px;
    text-align: center;
}

.metric-label {
    font-size: 0.8rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
}

.metric-value {
    font-size: 1.5rem;
    font-weight: 700;
    margin-top: 4px;
}

.table-responsive {
    overflow-x: auto;
}

.rf-table {
    border-collapse: collapse;
    width: 100%;
}

.rf-table th {
    text-align: left;
    padding: 12px 16px;
    border-bottom: 2px solid var(--q-separator-color, #e2e8f0);
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: #64748b;
}

.rf-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--q-separator-color, #e2e8f0);
    vertical-align: middle;
}

.rf-table tr:hover {
    background: rgba(0, 0, 0, 0.02);
}

.row-disabled {
    opacity: 0.55;
}

.account-sync-card {
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: 9px;
    color: var(--ink);
}

.account-sync-subpanel {
    background: var(--workspace);
    border: 1px solid var(--border);
}

.sync-metrics-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.max-w-md {
    max-width: 480px;
    margin-left: auto;
    margin-right: auto;
}

.mail-row-hover:hover {
    background: rgba(59, 130, 246, 0.08) !important;
}

.message-detail-card {
    width: 880px;
    max-width: 95vw;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    color: var(--ink);
}

.mail-body-iframe {
    width: 100%;
    min-height: 380px;
    height: 50vh;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: #ffffff;
}

.mail-body-text {
    white-space: pre-wrap;
    word-break: break-word;
    font-family: inherit;
    font-size: 14px;
    line-height: 1.6;
    padding: 16px;
    background: var(--workspace);
    border: 1px solid var(--border);
    border-radius: 8px;
    min-height: 220px;
    max-height: 50vh;
    overflow-y: auto;
    color: var(--ink);
}

.attachment-chip {
    background: var(--workspace);
    border: 1px solid var(--border);
    border-radius: 8px;
}
</style>
