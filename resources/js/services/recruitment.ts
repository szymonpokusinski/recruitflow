import type {
    RecruitmentService,
    RecruitmentSnapshot,
} from '../types/recruitment.ts';
import { api } from './http.ts';
export const recruitmentRuntime: {
    mode: 'local' | 'connected';
    sources: {
        id: string;
        name: string;
        last_synced_at: string | null;
        last_error: string | null;
    }[];
} = { mode: 'local', sources: [] };
export const commandId = (): string => crypto.randomUUID();
export const recruitmentService: RecruitmentService = {
    async getSnapshot() {
        const response = await api<{
            mode: 'local' | 'connected';
            snapshot: RecruitmentSnapshot;
            sources: typeof recruitmentRuntime.sources;
        }>('/api/recruitment/state');
        recruitmentRuntime.mode = response.mode;
        recruitmentRuntime.sources = response.sources;
        return response.snapshot;
    },
    async changeStatus(id, stage, actor) {
        await api(`/api/recruitment/applications/${id}/stage`, 'POST', {
            operationId: commandId(),
            stage,
            actor,
        });
    },
    async addNote(id, message, actor) {
        await api(`/api/recruitment/applications/${id}/events`, 'POST', {
            operationId: commandId(),
            eventType: 'Notatka',
            message,
            occurredAt: new Date().toISOString(),
            actor,
        });
    },
    async resetDemo() {
        throw new Error(
            'Dane rzeczywiste SQLite nie podlegają resetowaniu DEMO.',
        );
    },
};
