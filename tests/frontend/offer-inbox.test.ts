import { test } from 'node:test';
import assert from 'node:assert/strict';
import { demoOffers, demoApplications } from '../../resources/js/data/demo.ts';
import { mapOffer, mapApplication } from '../../resources/js/domain/recruitment.ts';
import { emptyOfferFilters, filterOffers } from '../../resources/js/domain/offerFilters.ts';
import { offerApplicationState, offerRows, compareOfferAddedAt, offerAddedAt } from '../../resources/js/domain/offerInbox.ts';
import type { Application } from '../../resources/js/types/recruitment.ts';

const base = { ...mapOffer(demoOffers[0]!), cvSent: false, applicationStage: 'Nie aplikowano' };
const application: Application = { ...mapApplication(demoApplications[0]!), offerId: base.id, status: 'planned', appliedAt: null };

test('the inbox hides sent CVs and keeps them in the applied and all views without deleting data', () => {
    const offers = [{ ...base, id: 'pending' }, { ...base, id: 'sent', cvSent: true }];
    const original = structuredClone(offers);
    assert.deepEqual(filterOffers(offers, emptyOfferFilters('pending')).map(offer => offer.id), ['pending']);
    assert.deepEqual(filterOffers(offers, emptyOfferFilters('applied')).map(offer => offer.id), ['sent']);
    assert.equal(filterOffers(offers, emptyOfferFilters('all')).length, 2);
    assert.deepEqual(offers, original);
});
test('a planned application record does not remove an offer from the inbox', () => {
    assert.equal(offerApplicationState(base, [application]), 'not_applied');
    assert.equal(filterOffers([base], emptyOfferFilters('pending'), [application]).length, 1);
    assert.equal(offerRows([base], [application])[0]?.applicationCount, 1);
});
test('CV dates, sent applications and advanced stages override an inconsistent false or blank CV flag', () => {
    for (const stage of ['Kontakt HR', 'Odrzucono', 'Wycofano', 'Zatrudniono', 'Wysłano CV']) {
        assert.equal(offerApplicationState({ ...base, applicationStage: stage }), 'applied');
    }
    assert.equal(offerApplicationState({ ...base, cvSentAt: '2026-10-14T08:00:00Z' }), 'applied');
    for (const linked of [{ ...application, status: 'sent' as const }, { ...application, cvSent: true },
        { ...application, appliedAt: '2026-10-14T08:00:00Z' }]) {
        assert.equal(filterOffers([base], emptyOfferFilters('pending'), [linked]).length, 0);
    }
    assert.equal(offerApplicationState(base, [{ ...application, offerId: 'other', cvSent: true }]), 'not_applied');
});
test('unknown application data stays visible and does not pretend a CV was or was not sent', () => {
    const unknown = { ...base, cvSent: null, applicationStage: '', cvSentAt: null };
    assert.equal(offerApplicationState(unknown), 'unknown');
    assert.equal(filterOffers([unknown], emptyOfferFilters('pending')).length, 1);
    assert.equal(filterOffers([unknown], emptyOfferFilters('applied')).length, 0);
    assert.equal(offerApplicationState({ ...unknown, cvSentAt: 'invalid-date' }), 'unknown');
});
test('marking a sent CV removes an offer from the inbox immediately; a valid correction brings it back', () => {
    assert.equal(filterOffers([base], emptyOfferFilters('pending')).length, 1);
    const sent = { ...base, cvSent: true, cvSentAt: '2026-10-14T08:00:00Z', applicationStage: 'Wysłano CV' };
    assert.equal(filterOffers([sent], emptyOfferFilters('pending')).length, 0);
    assert.equal(filterOffers([{ ...sent, cvSent: false, cvSentAt: null, applicationStage: 'Nie aplikowano' }], emptyOfferFilters('pending')).length, 1);
});
test('search, AI filtering and visibility flags compose with the inbox and zero remains a real score', () => {
    const candidates = [{ ...base, id: 'accountant', industry: 'Finanse', aiMatchScore: 0 },
        { ...base, id: 'sent', industry: 'Finanse', aiMatchScore: 99, cvSent: true },
        { ...base, id: 'hidden', hidden: true }, { ...base, id: 'archived', archived: true }];
    assert.deepEqual(filterOffers(candidates, { ...emptyOfferFilters('pending'), industry: 'Finanse', minimumAiScore: 0 }).map(offer => offer.id), ['accountant']);
});
test('newest sort uses stable detection dates and leaves unknown dates last without inventing them', () => {
    const values = [{ ...base, id: 'unknown', createdAt: null },
        { ...base, id: 'new', createdAt: '2026-10-14T08:00:00Z' },
        { ...base, id: 'old', firstDetectedAt: '2026-10-01T08:00:00Z', createdAt: '2026-10-14T08:00:00Z' }];
    assert.deepEqual([...values].sort((a, b) => compareOfferAddedAt(b, a)).map(offer => offer.id), ['new', 'old', 'unknown']);
    assert.equal(offerAddedAt(values[0]!), null);
});
