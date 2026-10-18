import { test } from 'node:test';
import assert from 'node:assert/strict';
import { recruitmentService } from '../../resources/js/services/recruitment.ts';
import {
    formatDate,
    formatDateTime,
    formatSalary,
    mapOffer,
    activityRows,
    applicationRows,
    dashboardStats,
} from '../../resources/js/domain/recruitment.ts';
import { demoOffers } from '../../resources/js/data/demo.ts';
Object.defineProperty(globalThis, 'document', {
    value: { cookie: '' },
    configurable: true,
});

test('local SQLite is the source of truth and never falls back to demo on a backend failure', async () => {
    const originalFetch = globalThis.fetch;
    try {
        globalThis.fetch = async () =>
            new Response(
                JSON.stringify({
                    mode: 'local',
                    sources: [],
                    snapshot: { offers: [], applications: [], activities: [] },
                }),
            );
        assert.equal((await recruitmentService.getSnapshot()).offers.length, 0);
        const snapshot = { offers: [], applications: [], activities: [] };
        globalThis.fetch = async () =>
            new Response(
                JSON.stringify({
                    mode: 'connected',
                    sources: [
                        {
                            id: 'source',
                            name: 'Own source',
                            last_synced_at: null,
                            last_error: null,
                        },
                    ],
                    snapshot,
                }),
            );
        assert.deepEqual(await recruitmentService.getSnapshot(), snapshot);
        await recruitmentService.changeStatus(
            'anything',
            'hr_contact',
            'actor',
        );
        await recruitmentService.addNote('anything', 'note', 'actor');
        await assert.rejects(recruitmentService.resetDemo(), /resetowaniu/);
        globalThis.fetch = async () => {
            throw new Error('offline');
        };
        await assert.rejects(
            recruitmentService.getSnapshot(),
            /Brak połączenia/,
        );
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('missing optional dates and amounts render as missing instead of fabricated data', () => {
    assert.equal(formatDate(null), '—');
    assert.equal(formatDateTime('not-a-date'), '—');
    const offer = {
        ...mapOffer(demoOffers[0]!),
        salaryMin: null,
        salaryMax: null,
        currency: '',
    };
    assert.equal(formatSalary(offer), '—');
    assert.equal(formatSalary({ ...offer, currency: 'EUR' }), '—');
    assert.equal(
        formatSalary({ ...offer, salaryMin: 100, currency: 'EUR' }),
        'od 100 EUR',
    );
});

test('offer-level HR events resolve the offer without a candidate and statuses remain separate', () => {
    const offer = {
        ...mapOffer(demoOffers[0]!),
        id: 'offer-1',
        effectiveStatus: 'NIEPOTWIERDZONA',
        portalStatus: 'Niepotwierdzona',
        applicationStage: 'Wysłano CV',
    };
    const snapshot = {
        offers: [offer],
        applications: [
            {
                id: 'app-1',
                offerId: 'offer-1',
                candidateName: '',
                candidateEmail: '',
                candidateRole: '',
                location: '',
                status: 'sent' as const,
                owner: '',
                source: 'sheet',
                appliedAt: null,
                nextStep: null,
            },
        ],
        activities: [
            {
                id: 'event-1',
                offerId: 'offer-1',
                applicationId: null,
                kind: 'unknown' as const,
                actor: '',
                message: 'An offer event',
                occurredAt: '2026-10-12T12:00:00Z',
            },
        ],
    };
    assert.equal(applicationRows(snapshot)[0]?.displayName, offer.title);
    assert.equal(activityRows(snapshot)[0]?.offerTitle, offer.title);
    assert.equal(activityRows(snapshot)[0]?.candidateName, offer.title);
    assert.equal(dashboardStats(snapshot).activeApplications, 1);
    assert.equal(snapshot.offers[0]?.effectiveStatus, 'NIEPOTWIERDZONA');
    assert.equal(snapshot.offers[0]?.applicationStage, 'Wysłano CV');
});
