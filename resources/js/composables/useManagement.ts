import { reactive, ref } from 'vue';
import { Notify } from 'quasar';
import { api, ApiError } from '../services/http.ts';
import { commandId } from '../services/recruitment.ts';
import { loadRecruitment } from './useRecruitment.ts';
import { preferences } from './usePreferences.ts';
import type { ManagementState, Audit } from '../types/management.ts';
import type { Offer, Application } from '../types/recruitment.ts';
export const management = ref<ManagementState | null>(null);
export const audits = ref<Audit[]>([]);
export const dialog = reactive<{
    kind: 'offer' | 'cv' | 'stage' | 'event' | 'application' | null;
    offer: Offer | null;
    application: Application | null;
    operationId: string;
}>({ kind: null, offer: null, application: null, operationId: '' });
let loading: Promise<void> | null = null;
export async function loadManagement(): Promise<void> {
    if (loading) return loading;
    loading = (async () => {
        try {
            const [state, history] = await Promise.all([
                api<ManagementState>('/api/recruitment/manage'),
                api<Audit[]>('/api/recruitment/audits'),
            ]);
            management.value = state;
            audits.value = history;
        } finally {
            loading = null;
        }
    })();
    return loading;
}
export function openDialog(
    kind: typeof dialog.kind,
    offer: Offer | null = null,
    application: Application | null = null,
): void {
    Object.assign(dialog, {
        kind,
        offer,
        application,
        operationId: commandId(),
    });
}
export async function saveCommand(
    path: string,
    values: unknown,
    method = 'POST',
): Promise<{ id?: string }> {
    const result = await api<{ id?: string }>(path, method, values);
    await Promise.all([loadRecruitment(true), loadManagement()]);
    Notify.create({ type: 'positive', message: 'Zapisano lokalnie w SQLite.' });
    return result;
}
export async function updateDecision(
    offer: Offer,
    changes: Record<string, unknown>,
): Promise<void> {
    try {
        await saveCommand(
            `/api/recruitment/offers/${offer.id}`,
            {
                operationId: commandId(),
                expectedVersion: offer.version ?? 0,
                actor: preferences.displayName,
                ...changes,
            },
            'PUT',
        );
    } catch (error) {
        Notify.create({
            type: 'negative',
            message:
                error instanceof Error
                    ? error.message
                    : 'Nie udało się zapisać.',
        });
    }
}
export const fieldErrors = (error: unknown): Record<string, string[]> =>
    error instanceof ApiError ? error.errors : {};
export async function scheduleSync(read = false): Promise<void> {
    await api('/api/recruitment/synchronize', 'POST', { read });
}
