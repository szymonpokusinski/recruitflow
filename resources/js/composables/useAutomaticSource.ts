import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Notify } from 'quasar';
import { integrationsService } from '../services/integrations.ts';
import { loadRecruitment } from './useRecruitment.ts';
import type { IntegrationState } from '../types/integrations.ts';

export function useAutomaticSource() {
    const state = ref<IntegrationState | null>(null),
        spreadsheet = ref(''),
        accountId = ref(''),
        busy = ref(false),
        error = ref<string | null>(null);
    const lastResult = ref<Awaited<
        ReturnType<typeof integrationsService.autoConnect>
    > | null>(null);
    let timer: ReturnType<typeof setInterval> | null = null;
    const accounts = computed(
        () =>
            state.value?.accounts
                .filter((account) => account.status === 'connected')
                .map((account) => ({
                    value: account.id,
                    label: account.name,
                })) ?? [],
    );
    async function reload(): Promise<void> {
        state.value = await integrationsService.state();
        if (!accountId.value)
            accountId.value =
                state.value.sources[0]?.integration_account_id ??
                accounts.value[0]?.value ??
                '';
        if (!spreadsheet.value && state.value.sources[0])
            spreadsheet.value = `https://docs.google.com/spreadsheets/d/${state.value.sources[0].external_id}/edit`;
    }
    async function connect(): Promise<void> {
        busy.value = true;
        error.value = null;
        lastResult.value = null;
        try {
            lastResult.value = await integrationsService.autoConnect(
                spreadsheet.value.trim(),
                accountId.value,
            );
            await reload();
            await loadRecruitment(true);
            Notify.create({
                type: 'positive',
                message: `Zsynchronizowano ${lastResult.value.records_count} rekordów.`,
            });
        } catch (cause) {
            error.value =
                cause instanceof Error
                    ? cause.message
                    : 'Nie udało się połączyć źródła.';
            await reload().catch(() => {});
        } finally {
            busy.value = false;
        }
    }
    onMounted(() => {
        void reload().catch((cause) => {
            error.value =
                cause instanceof Error
                    ? cause.message
                    : 'Nie można odczytać ustawień.';
        });
        timer = setInterval(() => {
            if (!busy.value) void reload().catch(() => {});
        }, 10000);
    });
    onUnmounted(() => {
        if (timer) clearInterval(timer);
    });
    return {
        state,
        spreadsheet,
        accountId,
        busy,
        error,
        lastResult,
        accounts,
        connect,
        reload,
    };
}
