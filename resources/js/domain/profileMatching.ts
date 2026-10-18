import type {
    Offer,
    RecruitmentSnapshot,
    OfferAiEvaluation,
} from '../types/recruitment.ts';
const scoreFields = [
    'aiMatchScore',
    'aiStackScore',
    'aiSeniorityScore',
    'aiLocationScore',
    'aiSalaryScore',
] as const;
const textFields = [
    'aiFitReasons',
    'aiFitConcerns',
    'aiRecommendation',
    'aiCvSuggestions',
    'aiEvaluationConfidence',
    'aiEvaluatedAt',
] as const;
export function profileOffers(snapshot: RecruitmentSnapshot): Offer[] {
    if (!snapshot.activeProfileId) return snapshot.offers;
    return snapshot.offers.map((offer) => {
        const evaluation = snapshot.evaluations?.find(
            (e) =>
                e.offerId === offer.id &&
                e.profileId === snapshot.activeProfileId,
        );
        if (evaluation)
            return {
                ...offer,
                ...evaluation.data,
                aiProfileId: evaluation.profileId,
            };
        if (
            !offer.aiProfileId ||
            offer.aiProfileId === snapshot.activeProfileId
        )
            return offer;
        const absent: OfferAiEvaluation = {};
        for (const field of [...scoreFields, ...textFields])
            absent[field] = null;
        return { ...offer, ...absent };
    });
}
