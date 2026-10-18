import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    emptyOfferFilters,
    filterOffers,
} from '../../resources/js/domain/offerFilters.ts';
import { profileOffers } from '../../resources/js/domain/profileMatching.ts';
import {
    applicationRows,
    mapOffer,
} from '../../resources/js/domain/recruitment.ts';
import { demoOffers } from '../../resources/js/data/demo.ts';
import { ApiError, api } from '../../resources/js/services/http.ts';
const base = mapOffer(demoOffers[0]!);
test('universal filters accept non-IT offers, zero AI and hide only explicitly hidden records', () => {
    const accountant = {
        ...base,
        id: 'accountant',
        industry: 'Finanse',
        jobCategory: 'Księgowy',
        salaryMin: 7000,
        salaryMax: 9000,
        aiMatchScore: 0,
        cvSent: true,
        favorite: true,
        applicationStage: 'Kontakt HR',
        employment: 'UoP',
    };
    const driver = {
        ...base,
        id: 'driver',
        industry: 'Transport',
        jobCategory: 'Kierowca',
        aiMatchScore: null,
        hidden: true,
    };
    const filters = emptyOfferFilters();
    assert.equal(filterOffers([accountant, driver], filters).length, 1);
    Object.assign(filters, {
        industry: 'Finanse',
        jobCategory: 'Księgowy',
        salaryMin: 8500,
        cvSent: true,
        favorite: true,
        minimumAiScore: 0,
        stage: 'Kontakt HR',
        employment: 'UoP',
    });
    assert.deepEqual(
        filterOffers([accountant, driver], filters).map((o) => o.id),
        ['accountant'],
    );
    filters.minimumAiScore = 1;
    assert.equal(filterOffers([accountant], filters).length, 0);
    assert.equal(
        filterOffers([{ ...accountant, archived: true }], emptyOfferFilters())
            .length,
        0,
    );
    assert.equal(
        filterOffers([driver], { ...emptyOfferFilters(), showHidden: true })
            .length,
        1,
    );
});
test('profile-specific AI preserves zero, does not assign legacy AI to an arbitrary profile and feeds linked applications', () => {
    const offer = {
        ...base,
        id: 'offer',
        aiMatchScore: 87,
        aiProfileId: 'php',
    };
    const snapshot = {
        offers: [offer, { ...base, id: 'legacy', aiMatchScore: 70 }],
        applications: [],
        activities: [],
        activeProfileId: 'accountant',
        evaluations: [
            {
                offerId: 'offer',
                profileId: 'accountant',
                data: { aiMatchScore: 0 },
            },
        ],
    };
    assert.equal(profileOffers(snapshot)[0]?.aiMatchScore, 0);
    assert.equal(profileOffers(snapshot)[1]?.aiMatchScore, 70);
    assert.equal(profileOffers(snapshot)[1]?.aiProfileId, undefined);
    assert.equal(
        profileOffers({ ...snapshot, evaluations: [] })[0]?.aiMatchScore,
        null,
    );
    const app = {
        id: 'app',
        offerId: 'offer',
        candidateName: '',
        candidateEmail: '',
        candidateRole: '',
        location: '',
        status: 'sent' as const,
        owner: '',
        source: '',
        appliedAt: null,
        nextStep: null,
    };
    assert.equal(
        applicationRows({
            ...snapshot,
            offers: profileOffers(snapshot),
            applications: [app],
        }).length,
        1,
    );
    assert.equal(
        applicationRows({
            ...snapshot,
            offers: profileOffers(snapshot),
            applications: [app],
        })[0]?.aiMatchScore,
        0,
    );
});
test('Laravel validation errors are retained for individual form fields', async () => {
    Object.defineProperty(globalThis, 'document', {
        value: { cookie: '' },
        configurable: true,
    });
    const original = globalThis.fetch;
    try {
        globalThis.fetch = async () =>
            new Response(
                JSON.stringify({
                    errors: { salaryMax: ['Nieprawidłowy zakres.'] },
                }),
                { status: 422 },
            );
        await assert.rejects(
            api('/api/recruitment/offers', 'POST', {}),
            (error: unknown) =>
                error instanceof ApiError &&
                error.errors.salaryMax?.[0] === 'Nieprawidłowy zakres.',
        );
    } finally {
        globalThis.fetch = original;
    }
});
