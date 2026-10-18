import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mergeImportedFields } from '../../resources/js/domain/importOffer.ts';

test('import fills empty fields but preserves typed values and source URL', () => {
    const original = { offerUrl: 'https://www.pracuj.pl/praca/example,oferta,1005120488', company: 'Moja poprawiona firma', title: '', salaryMin: null, requirements: [] as string[] };
    const incoming = { offerUrl: 'https://www.pracuj.pl/praca/other,oferta,1005120488', company: 'Firma źródłowa', title: 'Księgowy', salaryMin: 5600, requirements: ['Excel'] };
    const result = mergeImportedFields(original, incoming);
    assert.equal(result.values.offerUrl, original.offerUrl);
    assert.equal(result.values.company, original.company);
    assert.equal(result.values.title, 'Księgowy');
    assert.equal(result.values.salaryMin, 5600);
    assert.deepEqual(result.values.requirements, ['Excel']);
    assert.deepEqual(result.skipped, ['company']);
    assert.equal(original.title, '');
});

test('explicit replacement changes imported values without changing the URL', () => {
    const result = mergeImportedFields({ offerUrl: 'https://example.org/a', company: 'Wpisana firma' }, { offerUrl: 'https://example.org/b', company: 'Firma z ogłoszenia' }, true);
    assert.equal(result.values.offerUrl, 'https://example.org/a');
    assert.equal(result.values.company, 'Firma z ogłoszenia');
    assert.deepEqual(result.skipped, []);
});
