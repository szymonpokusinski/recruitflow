import type { Offer } from '../types/recruitment.ts';

export function validAiScore(value: number | null | undefined): number | null {
    return typeof value === 'number' &&
        Number.isFinite(value) &&
        value >= 0 &&
        value <= 100
        ? value
        : null;
}

export function aiScorePresentation(value: number | null | undefined) {
    const score = validAiScore(value);
    return {
        score,
        label:
            score === null
                ? 'Brak oceny'
                : score >= 85
                  ? 'Wysokie dopasowanie'
                  : score >= 70
                    ? 'Dobre dopasowanie'
                    : 'Niższe dopasowanie',
        color:
            score === null
                ? 'grey'
                : score >= 85
                  ? 'positive'
                  : score >= 70
                    ? 'amber-8'
                    : 'negative',
        formatted:
            score === null
                ? 'Brak oceny'
                : `${score.toLocaleString('pl-PL')}/100`,
    };
}

export function compareAiScores(
    a: number | null | undefined,
    b: number | null | undefined,
): number {
    return (validAiScore(a) ?? -1) - (validAiScore(b) ?? -1);
}

export function meetsMinimumAiScore(
    score: number | null | undefined,
    minimum: number | string | null | undefined,
): boolean {
    if (minimum === null || minimum === undefined || minimum === '')
        return true;
    const threshold = Number(minimum);
    const value = validAiScore(score);
    return (
        Number.isFinite(threshold) &&
        threshold >= 0 &&
        threshold <= 100 &&
        value !== null &&
        value >= threshold
    );
}

export function bestMatchedOffers(offers: Offer[], limit = 5): Offer[] {
    return offers
        .filter((offer) => validAiScore(offer.aiMatchScore) !== null)
        .sort(
            (a, b) =>
                compareAiScores(b.aiMatchScore, a.aiMatchScore) ||
                a.title.localeCompare(b.title, 'pl') ||
                a.id.localeCompare(b.id),
        )
        .slice(0, limit);
}

export function formatAiEvaluationDate(
    value: string | number | null | undefined,
): string {
    if (value === null || value === undefined || value === '')
        return 'Brak daty oceny';
    // Legacy cache may contain the original date. Do not guess its timezone or format.
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T/.test(value))
        return String(value);
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat('pl-PL', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          }).format(date);
}
