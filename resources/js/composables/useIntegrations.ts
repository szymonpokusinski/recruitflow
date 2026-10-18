import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Notify, Dialog } from 'quasar';
import { integrationsService as service } from '../services/integrations.ts';
import { loadRecruitment } from './useRecruitment.ts';
import type {
    DataSource,
    IntegrationState,
    IntegrationAccount,
    SourceDraft,
    MappingDraft,
} from '../types/integrations.ts';
export const roles = [
    { value: 'offers', label: 'Oferty' },
    { value: 'applications', label: 'Aplikacje' },
    { value: 'events', label: 'Zdarzenia HR' },
    { value: 'profiles', label: 'Profile wyszukiwania' },
    { value: 'other', label: 'Inne — bez importu' },
];
export function useIntegrations() {
    const state = ref<IntegrationState | null>(null),
        busy = ref(false),
        error = ref<string | null>(null);
    const sourceDialog = ref(false),
        mappingDialog = ref(false),
        selected = ref<DataSource | null>(null);
    const draft = ref<SourceDraft>({
        name: '',
        spreadsheet: '',
        integration_account_id: '',
        sync_interval_minutes: 15,
        read_only: true,
    });
    const mapping = ref<MappingDraft[]>([]),
        sheetTitle = ref(''),
        primary = ref(false);
    const accountName = ref(''),
        clientFile = ref<File | null>(null),
        authUrl = ref<string | null>(null),
        authStatus = ref('');
    let poll: ReturnType<typeof setTimeout> | null = null,
        destroyed = false;
    let statePoll: ReturnType<typeof setInterval> | null = null;
    async function reload() {
        state.value = await service.state();
    }
    async function action(task: () => Promise<void>): Promise<boolean> {
        if (busy.value) return false;
        busy.value = true;
        error.value = null;
        try {
            await task();
            await reload();
            await loadRecruitment(true);
            return true;
        } catch (e) {
            error.value =
                e instanceof Error ? e.message : 'Operacja nie powiodła się.';
            Notify.create({ type: 'negative', message: error.value });
            await reload().catch(() => {});
            await loadRecruitment(true);
            return false;
        } finally {
            busy.value = false;
        }
    }
    onMounted(() => {
        void action(async () => {});
        statePoll = setInterval(() => {
            if (!busy.value) void reload().catch(() => {});
        }, 10000);
    });
    onUnmounted(() => {
        destroyed = true;
        if (statePoll) clearInterval(statePoll);
        if (poll) clearTimeout(poll);
    });
    const accounts = computed(
        () =>
            state.value?.accounts.map((account) => ({
                value: account.id,
                label: `${account.name} (${account.status === 'connected' ? 'połączone' : 'wymaga połączenia'})`,
            })) ?? [],
    );
    function edit(source: DataSource | null) {
        selected.value = source;
        draft.value = source
            ? {
                  name: source.name,
                  spreadsheet: source.external_id,
                  integration_account_id: source.integration_account_id,
                  sync_interval_minutes: source.sync_interval_minutes,
                  read_only: true,
              }
            : {
                  name: '',
                  spreadsheet: '',
                  integration_account_id:
                      state.value?.accounts.find(
                          (a) => a.status === 'connected',
                      )?.id ?? '',
                  sync_interval_minutes: 15,
                  read_only: true,
              };
        sourceDialog.value = true;
    }
    async function save() {
        await action(async () => {
            await service.save(draft.value, selected.value?.id);
            sourceDialog.value = false;
        });
    }
    async function configure() {
        if (clientFile.value)
            await action(async () => {
                await service.configure(clientFile.value!);
                clientFile.value = null;
                Notify.create({
                    type: 'positive',
                    message: 'Klient OAuth zapisany lokalnie.',
                });
            });
    }
    async function monitor(id: string) {
        if (destroyed) return;
        try {
            const flow = await service.flow(id);
            authStatus.value = flow.status;
            if (
                flow.status === 'connected' ||
                flow.status === 'failed' ||
                new Date(flow.expires_at).getTime() <= Date.now()
            ) {
                authUrl.value = null;
                await reload();
                Notify.create({
                    type: flow.status === 'connected' ? 'positive' : 'negative',
                    message:
                        flow.status === 'connected'
                            ? 'Konto Google połączone.'
                            : (flow.error ??
                              'Logowanie wygasło. Spróbuj ponownie.'),
                });
                return;
            }
        } catch (e) {
            authStatus.value =
                e instanceof Error
                    ? e.message
                    : 'Nie można sprawdzić logowania.';
            return;
        }
        if (destroyed) return;
        poll = setTimeout(() => {
            void monitor(id);
        }, 2000);
    }
    async function connect(account?: IntegrationAccount) {
        if (poll) clearTimeout(poll);
        await action(async () => {
            const flow = await service.connect(
                account?.name ?? accountName.value,
                account?.id,
            );
            authUrl.value = flow.authorization_url;
            authStatus.value = flow.status;
            void monitor(flow.id);
        });
    }
    function confirm(message: string, task: () => Promise<unknown>) {
        Dialog.create({
            title: 'Potwierdź zmianę',
            message,
            cancel: { label: 'Anuluj' },
            ok: { label: 'Potwierdź', color: 'primary' },
        }).onOk(() => {
            void action(async () => {
                await task();
            });
        });
    }
    function disconnect(account: IntegrationAccount) {
        confirm(
            `Odłączyć konto „${account.name}”? Najpierw usuń jego źródła.`,
            () => service.disconnect(account.id),
        );
    }
    function remove(source: DataSource) {
        confirm(
            `Usunąć lokalną konfigurację i cache źródła „${source.name}”? Dane w Google Sheets pozostaną bez zmian.`,
            () => service.remove(source.id),
        );
    }
    async function openMapping(source: DataSource) {
        await action(async () => {
            const metadata = await service.metadata(source.id);
            selected.value = source;
            sheetTitle.value = metadata.title;
            mapping.value = metadata.sheets.map((sheet) => {
                const old = source.configurations.find(
                    (c) => c.sheet_id === sheet.id,
                );
                return {
                    sheet_id: sheet.id,
                    sheet_title: sheet.title,
                    role: old?.role ?? 'other',
                    header_row: old?.header_row ?? 1,
                    schema_profile: old?.schema_profile ?? 'generic',
                    mapping: Object.fromEntries(
                        old?.mappings.map((m) => [m.field, m.column_name]) ??
                            [],
                    ),
                    columns: [],
                    loaded: false,
                };
            });
            primary.value = false;
            mappingDialog.value = true;
        });
    }
    async function headers(sheet: MappingDraft) {
        if (!selected.value) return;
        await action(async () => {
            const result = await service.headers(
                selected.value!.id,
                sheet.sheet_id,
                sheet.header_row,
            );
            sheet.columns = result.columns;
            sheet.mapping = {
                ...result.suggestions[sheet.role],
                ...Object.fromEntries(
                    Object.entries(sheet.mapping).filter(([, value]) =>
                        result.columns.includes(value),
                    ),
                ),
            };
            sheet.loaded = true;
        });
    }
    function roleChanged(sheet: MappingDraft) {
        sheet.mapping = {};
        sheet.loaded = false;
    }
    function headerChanged(sheet: MappingDraft) {
        sheet.loaded = false;
        sheet.columns = [];
    }
    async function saveMapping(activate: boolean) {
        const source = selected.value;
        if (!source) return;
        await action(async () => {
            const sheets = mapping.value.filter((s) => s.role !== 'other');
            if (!sheets.length || sheets.some((s) => !s.loaded))
                throw new Error(
                    'Wybierz role zakładek i pobierz ich nagłówki.',
                );
            await service.mappings(source.id, sheets);
            const result = await service.test(source.id);
            if (activate) await service.toggle(source.id, true, primary.value);
            mappingDialog.value = false;
            Notify.create({
                type: 'positive',
                message: `Mapowanie poprawne: ${result.records_count} rekordów.${activate ? ' Źródło aktywne.' : ''}`,
            });
        });
    }
    async function test(source: DataSource) {
        await action(async () => {
            const result = await service.test(source.id);
            Notify.create({
                type: 'positive',
                message: `Połączono z „${result.title}”: ${result.sheets_count} zakładek, ${result.records_count} poprawnych rekordów. ${result.mapping_valid ? '' : 'Skonfiguruj mapowanie.'}`,
            });
        });
    }
    async function sync(source: DataSource) {
        await action(async () => {
            await service.sync(source.id);
            Notify.create({
                type: 'positive',
                message: 'Lokalny cache odświeżony.',
            });
        });
    }
    async function toggle(source: DataSource) {
        await action(async () => {
            await service.toggle(source.id, !source.enabled);
        });
    }
    async function setPrimary(source: DataSource) {
        await action(async () => {
            await service.primary(
                source.id,
                !source.configurations.some((c) => c.is_primary),
            );
        });
    }
    return {
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
        reload: () => action(async () => {}),
    };
}
