import { demoOffers, demoApplications, demoActivities } from '../data/demo.ts';
import {
    applicationStages,
    mapOffer,
    mapApplication,
    mapActivity,
    stageLabel,
} from '../domain/recruitment.ts';
import type {
    ActivityDTO,
    ApplicationStage,
    RecruitmentService,
    RecruitmentSnapshot,
} from '../types/recruitment.ts';

export interface DemoStorage {
    getItem(key: string): string | null;
    setItem(key: string, value: string): void;
    removeItem(key: string): void;
}
interface DemoChanges {
    version: 1;
    stages: Partial<Record<string, ApplicationStage>>;
    events: ActivityDTO[];
}
const storageKey = 'recruitflow.demo.v1';
const emptyChanges = (): DemoChanges => ({
    version: 1,
    stages: {},
    events: [],
});
const applicationIds = new Set(demoApplications.map((item) => item.id));
const validStage = (value: unknown): value is ApplicationStage =>
    applicationStages.some((item) => item.value === value);
function validChanges(value: unknown): value is DemoChanges {
    if (typeof value !== 'object' || value === null) return false;
    const data = value as Record<string, unknown>;
    if (
        data.version !== 1 ||
        typeof data.stages !== 'object' ||
        data.stages === null ||
        !Array.isArray(data.events)
    )
        return false;
    return (
        Object.entries(data.stages).every(
            ([id, stage]) => applicationIds.has(id) && validStage(stage),
        ) &&
        data.events.every((event: unknown) => {
            if (typeof event !== 'object' || event === null) return false;
            const item = event as Record<string, unknown>;
            return (
                typeof item.id === 'string' &&
                typeof item.application_id === 'string' &&
                applicationIds.has(item.application_id) &&
                ['note', 'status'].includes(String(item.kind)) &&
                typeof item.actor === 'string' &&
                typeof item.message === 'string' &&
                typeof item.occurred_at === 'string' &&
                Number.isFinite(Date.parse(item.occurred_at))
            );
        })
    );
}

export class DemoRecruitmentService implements RecruitmentService {
    private changes: DemoChanges;
    private readonly storage: DemoStorage | null;
    private readonly now: () => Date;
    constructor(
        storage: DemoStorage | null,
        now: () => Date = () => new Date(),
    ) {
        this.storage = storage;
        this.now = now;
        this.changes = emptyChanges();
        try {
            const raw = storage?.getItem(storageKey);
            if (raw) {
                const data: unknown = JSON.parse(raw);
                if (validChanges(data)) this.changes = data;
            }
        } catch {
            /* Unavailable or corrupt storage leaves the immutable demo seed intact. */
        }
    }
    async getSnapshot(): Promise<RecruitmentSnapshot> {
        return {
            offers: demoOffers.map(mapOffer),
            applications: demoApplications.map((item) =>
                mapApplication({
                    ...item,
                    status: this.changes.stages[item.id] ?? item.status,
                }),
            ),
            activities: [...demoActivities, ...this.changes.events].map(
                mapActivity,
            ),
        };
    }
    async changeStatus(
        id: string,
        status: ApplicationStage,
        actor: string,
    ): Promise<void> {
        this.ensureApplication(id);
        if (!validStage(status))
            throw new Error('Nieprawidłowy status aplikacji.');
        const original = demoApplications.find((item) => item.id === id);
        if ((this.changes.stages[id] ?? original?.status) === status) return;
        this.commit({
            ...this.changes,
            stages: { ...this.changes.stages, [id]: status },
            events: [
                ...this.changes.events,
                this.event(
                    id,
                    'status',
                    `Zmieniono status na „${stageLabel(status)}”.`,
                    actor,
                ),
            ],
        });
    }
    async addNote(id: string, note: string, actor: string): Promise<void> {
        this.ensureApplication(id);
        const text = note.trim();
        if (!text || text.length > 2000)
            throw new Error('Notatka musi zawierać od 1 do 2000 znaków.');
        this.commit({
            ...this.changes,
            events: [
                ...this.changes.events,
                this.event(id, 'note', text, actor),
            ],
        });
    }
    async resetDemo(): Promise<void> {
        if (this.storage) this.storage.removeItem(storageKey);
        this.changes = emptyChanges();
    }
    private ensureApplication(id: string): void {
        if (!applicationIds.has(id))
            throw new Error('Nie znaleziono aplikacji DEMO.');
    }
    private commit(next: DemoChanges): void {
        if (!this.storage)
            throw new Error('Lokalny zapis jest niedostępny w tym środowisku.');
        try {
            this.storage.setItem(storageKey, JSON.stringify(next));
        } catch {
            throw new Error(
                'Nie udało się zapisać danych DEMO lokalnie. Sprawdź dostępną pamięć przeglądarki.',
            );
        }
        this.changes = next;
    }
    private event(
        id: string,
        kind: 'note' | 'status',
        message: string,
        actor: string,
    ): ActivityDTO {
        const name = actor.trim() || 'Użytkownik';
        return {
            id: `local-${this.now().getTime()}-${Math.random().toString(36).slice(2)}`,
            application_id: id,
            kind,
            actor: /\bDEMO\b/i.test(name) ? name : `${name} · DEMO`,
            message,
            occurred_at: this.now().toISOString(),
        };
    }
}
