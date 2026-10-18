import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    aiScorePresentation,
    bestMatchedOffers,
    compareAiScores,
    meetsMinimumAiScore,
    validAiScore,
} from '../../resources/js/domain/aiMatching.ts';
import {
    applicationRows,
    mapOffer,
} from '../../resources/js/domain/recruitment.ts';
import { demoOffers } from '../../resources/js/data/demo.ts';
import type {
    Offer,
    RecruitmentSnapshot,
} from '../../resources/js/types/recruitment.ts';

const offer = (id: string, score: number | null): Offer => ({
    ...mapOffer(demoOffers[0]!),
    id,
    aiMatchScore: score,
});

test('zero is a valid AI score; missing and invalid scores never become zero', () => {
    assert.equal(aiScorePresentation(0).formatted, '0/100');
    assert.equal(aiScorePresentation(0).label, 'Niższe dopasowanie');
    for (const missing of [null, undefined, NaN, Infinity, -1, 101]) {
        assert.equal(validAiScore(missing), null);
        assert.equal(aiScorePresentation(missing).formatted, 'Brak oceny');
    }
    assert.equal(aiScorePresentation(69).color, 'negative');
    assert.equal(aiScorePresentation(70).color, 'amber-8');
    assert.equal(aiScorePresentation(84).label, 'Dobre dopasowanie');
    assert.equal(aiScorePresentation(85).color, 'positive');
    assert.equal(aiScorePresentation(100).formatted, '100/100');
});

test('AI sorting is numeric in both directions and minimum zero excludes missing evaluations', () => {
    const scores = [null, 87, 0, 100, 70];
    assert.deepEqual([...scores].sort(compareAiScores), [null, 0, 70, 87, 100]);
    assert.deepEqual(
        [...scores].sort((a, b) => compareAiScores(b, a)),
        [100, 87, 70, 0, null],
    );
    assert.deepEqual(
        scores.filter((score) => meetsMinimumAiScore(score, 85)),
        [87, 100],
    );
    assert.equal(meetsMinimumAiScore(0, '0'), true);
    assert.equal(meetsMinimumAiScore(null, 0), false);
    assert.equal(meetsMinimumAiScore(null, ''), true);
    assert.equal(meetsMinimumAiScore(100, 101), false);
});

test('dashboard selects five highest scored offers, including zero and excluding unscored offers', () => {
    const offers = [
        offer('missing', null),
        offer('a', 0),
        offer('b', 30),
        offer('c', 70),
        offer('d', 85),
        offer('e', 87),
        offer('f', 100),
    ];
    assert.deepEqual(
        bestMatchedOffers(offers).map((item) => item.aiMatchScore),
        [100, 87, 85, 70, 30],
    );
    assert.deepEqual(
        bestMatchedOffers([offer('missing', null), offer('zero', 0)]).map(
            (item) => item.id,
        ),
        ['zero'],
    );
    assert.deepEqual(
        offers.map((item) => item.id),
        ['missing', 'a', 'b', 'c', 'd', 'e', 'f'],
    );
});

test('application scores follow the related offer without duplicating or mutating applications', () => {
    const snapshot: RecruitmentSnapshot = {
        offers: [offer('offer', 0)],
        applications: [
            {
                id: 'app',
                offerId: 'offer',
                candidateName: '',
                candidateEmail: '',
                candidateRole: '',
                location: '',
                status: 'sent',
                owner: '',
                source: '',
                appliedAt: null,
                nextStep: null,
            },
        ],
        activities: [],
    };
    assert.equal(applicationRows(snapshot)[0]?.aiMatchScore, 0);
    snapshot.offers[0]!.aiMatchScore = 87;
    assert.equal(applicationRows(snapshot)[0]?.aiMatchScore, 87);
    assert.equal(snapshot.applications.length, 1);
    assert.equal(
        Object.hasOwn(snapshot.applications[0]!, 'aiMatchScore'),
        false,
    );
    snapshot.offers = [];
    assert.equal(applicationRows(snapshot)[0]?.aiMatchScore, null);
});

test('DTO maps every optional AI field while preserving original multiline text', () => {
    const dto = {
        ...demoOffers[0]!,
        ai_match_score: 0,
        ai_stack_score: 90,
        ai_fit_reasons: '  PHP\nLaravel\n',
        ai_evaluation_confidence: 'wysoka',
    };
    const mapped = mapOffer(dto);
    assert.equal(mapped.aiMatchScore, 0);
    assert.equal(mapped.aiStackScore, 90);
    assert.equal(mapped.aiSeniorityScore, null);
    assert.equal(mapped.aiFitReasons, dto.ai_fit_reasons);
    assert.equal(mapped.aiEvaluationConfidence, 'wysoka');
    assert.equal(mapped.aiEvaluatedAt, null);
});
