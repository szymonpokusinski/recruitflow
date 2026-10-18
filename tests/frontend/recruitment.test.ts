import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DemoRecruitmentService } from '../../resources/js/services/DemoRecruitmentService.ts';
import type { DemoStorage } from '../../resources/js/services/DemoRecruitmentService.ts';
import {
    activityRows,
    applicationRows,
    dashboardStats,
    matchesSearch,
} from '../../resources/js/domain/recruitment.ts';
class MemoryStorage implements DemoStorage {
    values = new Map<string, string>();
    getItem(key: string): string | null {
        return this.values.get(key) ?? null;
    }
    setItem(key: string, value: string): void {
        this.values.set(key, value);
    }
    removeItem(key: string): void {
        this.values.delete(key);
    }
}
const fixedNow = () => new Date('2026-10-12T12:00:00Z');

test('demo seed produces linked rows and accurate dashboard totals without writing storage', async () => {
    const storage = new MemoryStorage();
    const service = new DemoRecruitmentService(storage, fixedNow);
    const snapshot = await service.getSnapshot();
    assert.equal(snapshot.offers.length, 6);
    assert.equal(snapshot.applications.length, 10);
    assert.equal(storage.values.size, 0);
    assert.deepEqual(dashboardStats(snapshot), {
        activeOffers: 4,
        activeApplications: 8,
        newApplications: 3,
        interviews: 2,
    });
    assert.ok(
        applicationRows(snapshot).every(
            (row) => row.offerTitle !== 'Nieznana oferta',
        ),
    );
    snapshot.offers[0]!.requirements.push('Mutated');
    assert.ok(
        !(await service.getSnapshot()).offers[0]!.requirements.includes(
            'Mutated',
        ),
    );
});

test('search ignores Polish accents, casing and surrounding spaces', () => {
    assert.ok(matchesSearch('  LODZ  ', ['Łódź']));
    assert.ok(matchesSearch('wisniewski', ['Tomasz Wiśniewski']));
    assert.ok(!matchesSearch('designer', ['Senior Frontend Developer']));
});

test('status and notes persist locally, generate history and update metrics', async () => {
    const storage = new MemoryStorage();
    const service = new DemoRecruitmentService(storage, fixedNow);
    await service.changeStatus('A-201', 'hired', 'Tester');
    await service.addNote('A-201', '  Notatka demonstracyjna.  ', 'Tester');
    const restored = new DemoRecruitmentService(storage, fixedNow);
    const snapshot = await restored.getSnapshot();
    assert.equal(
        snapshot.applications.find((item) => item.id === 'A-201')?.status,
        'hired',
    );
    assert.equal(dashboardStats(snapshot).activeApplications, 7);
    const events = activityRows(snapshot).filter((item) =>
        item.id.startsWith('local-'),
    );
    assert.equal(events.length, 2);
    assert.ok(events.every((item) => item.actor === 'Tester · DEMO'));
    assert.ok(
        events.some((item) => item.message === 'Notatka demonstracyjna.'),
    );
    await restored.changeStatus('A-201', 'hired', 'Tester');
    assert.equal(
        (await restored.getSnapshot()).activities.length,
        snapshot.activities.length,
    );
});

test('invalid changes never mutate the demo and failed writes are reported', async () => {
    const service = new DemoRecruitmentService(new MemoryStorage(), fixedNow);
    await assert.rejects(service.changeStatus('unknown', 'new', 'Tester'));
    await assert.rejects(service.addNote('A-201', ' ', 'Tester'));
    await assert.rejects(service.addNote('A-201', 'x'.repeat(2001), 'Tester'));
    const blocked = new DemoRecruitmentService({
        getItem: () => null,
        removeItem: () => {},
        setItem: () => {
            throw new Error('blocked');
        },
    });
    await assert.rejects(
        blocked.changeStatus('A-201', 'hired', 'Tester'),
        /Nie udało się zapisać/,
    );
    assert.equal(
        (await blocked.getSnapshot()).applications.find(
            (item) => item.id === 'A-201',
        )?.status,
        'new',
    );
});

test('corrupt storage falls back to the demo seed and reset only removes the demo key', async () => {
    const storage = new MemoryStorage();
    storage.setItem('recruitflow.demo.v1', '{broken');
    storage.setItem('recruitflow.preferences.v1', 'keep');
    const service = new DemoRecruitmentService(storage, fixedNow);
    assert.equal((await service.getSnapshot()).applications.length, 10);
    await service.changeStatus('A-201', 'screening', 'Tester');
    await service.resetDemo();
    assert.equal(storage.getItem('recruitflow.demo.v1'), null);
    assert.equal(storage.getItem('recruitflow.preferences.v1'), 'keep');
    assert.equal(
        (await service.getSnapshot()).applications.find(
            (item) => item.id === 'A-201',
        )?.status,
        'new',
    );
});
