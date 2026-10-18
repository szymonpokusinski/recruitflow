import { computed, reactive } from 'vue';
import { Notify } from 'quasar';
import {
    recruitmentService,
    recruitmentRuntime,
} from '../services/recruitment.ts';
import {
    activityRows,
    applicationRows,
    dashboardStats,
} from '../domain/recruitment.ts';
import type {
    ApplicationStage,
    RecruitmentSnapshot,
} from '../types/recruitment.ts';
import { profileOffers } from '../domain/profileMatching.ts';
import { preferences } from './usePreferences.ts';
const state = reactive<{
    data: RecruitmentSnapshot;
    mode: 'local' | 'connected';
    sources: typeof recruitmentRuntime.sources;
    loading: boolean;
    loaded: boolean;
    error: string | null;
}>({
    data: { offers: [], applications: [], activities: [] },
    mode: 'local',
    sources: [],
    loading: false,
    loaded: false,
    error: null,
});
let pending: Promise<void> | null = null;
export async function loadRecruitment(force = false): Promise<void> {
    if (pending) return pending;
    if (state.loaded && !force) return;
    state.loading = true;
    pending = (async () => {
        try {
            state.data = await recruitmentService.getSnapshot();
            state.mode = recruitmentRuntime.mode;
            state.sources = recruitmentRuntime.sources;
            state.loaded = true;
            state.error = null;
        } catch (error) {
            state.error =
                error instanceof Error
                    ? error.message
                    : 'Nie można odczytać lokalnych danych.';
        } finally {
            state.loading = false;
            pending = null;
        }
    })();
    return pending;
}
export function useRecruitment() {
    async function mutate(
        action: () => Promise<void>,
        message: string,
    ): Promise<boolean> {
        try {
            await action();
            await loadRecruitment(true);
            Notify.create({ type: 'positive', message });
            return true;
        } catch (error) {
            Notify.create({
                type: 'negative',
                message:
                    error instanceof Error
                        ? error.message
                        : 'Nie udało się zapisać zmiany.',
            });
            return false;
        }
    }
    return {
        state,
        offers: computed(() => profileOffers(state.data)),
        applications: computed(() =>
            applicationRows({
                ...state.data,
                offers: profileOffers(state.data),
            }),
        ),
        activities: computed(() => activityRows(state.data)),
        stats: computed(() => dashboardStats(state.data)),
        changeStatus: (id: string, status: ApplicationStage) =>
            mutate(
                () =>
                    recruitmentService.changeStatus(
                        id,
                        status,
                        preferences.displayName,
                    ),
                'Etap zapisany w SQLite.',
            ),
        addNote: (id: string, note: string) =>
            mutate(
                () =>
                    recruitmentService.addNote(
                        id,
                        note,
                        preferences.displayName,
                    ),
                'Notatka zapisana w SQLite.',
            ),
        resetDemo: () =>
            mutate(
                () => recruitmentService.resetDemo(),
                'Przywrócono dane demonstracyjne.',
            ),
    };
}
