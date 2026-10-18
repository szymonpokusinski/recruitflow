import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DeferredRecruitFlowLink, validatedRecruitFlowLink } from '../../native/desktop/deep-links.mjs';

const offer = 'recruitflow://offer/00000000-0000-4000-8000-000000000001';
test('accepts only internal UUID, new offers and activity destinations', () => {
    for (const value of [offer, 'recruitflow://offers/new', 'recruitflow://activity', 'recruitflow://refresh']) assert.equal(validatedRecruitFlowLink(value), value);
    for (const value of ['https://evil.test', 'recruitflow://offer/../../.env', 'recruitflow://user@activity',
        'recruitflow://activity?url=x', 'recruitflow://activity#x', 'recruitflow://activity/',
        'recruitflow://offer/%2f'+offer, 'recruitflow://offer/not-a-uuid', null, 'x'.repeat(257)]) assert.equal(validatedRecruitFlowLink(value), null);
});
test('retains the cold-start link until the first NativePHP page is ready', async () => {
    const delivered = [];
    const links = new DeferredRecruitFlowLink(async value => delivered.push(value));
    assert.equal(links.receive(offer), true);
    assert.deepEqual(delivered, []);
    await links.markReady();
    assert.deepEqual(delivered, [offer]);
    await links.markReady();
    assert.equal(delivered.length, 1);
});
test('warm links use the same relay; invalid URLs never replace a pending valid link', async () => {
    const delivered = [];
    const links = new DeferredRecruitFlowLink(async value => delivered.push(value));
    links.receive(offer);
    assert.equal(links.receive('https://evil.test'), false);
    await links.markReady();
    links.receive('recruitflow://activity');
    await links.flush();
    assert.deepEqual(delivered, [offer, 'recruitflow://activity']);
});
test('a failed delivery is retained for the next readiness/activation attempt', async () => {
    let fails = true;
    const delivered = [];
    const links = new DeferredRecruitFlowLink(async value => { if (fails) throw new Error('not ready'); delivered.push(value); });
    links.receive(offer);
    await links.markReady();
    assert.equal(links.pending, offer);
    fails = false;
    await links.markReady();
    assert.deepEqual(delivered, [offer]);
});
