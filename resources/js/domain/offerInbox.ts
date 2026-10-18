import type { Application, Offer } from '../types/recruitment.ts';

export type OfferApplicationState = 'not_applied' | 'applied' | 'unknown';
export type OfferApplicationScope = 'pending' | 'applied' | 'all';
export type OfferRow = Offer & { applicationCount: number; applicationState: OfferApplicationState };

const submittedStages = new Set([
    'sent', 'confirmed', 'screening', 'interview', 'hr_contact', 'hr_interview',
    'technical_task', 'technical_interview', 'final', 'offer', 'hired', 'rejected', 'withdrawn',
    'wysłano cv', 'potwierdzono przyjęcie', 'kontakt hr', 'rozmowa hr',
    'zadanie techniczne', 'rozmowa techniczna', 'etap końcowy', 'oferta pracy',
    'odrzucono', 'wycofano', 'zatrudniono',
]);
const pendingStages = new Set(['not_applied', 'planned', 'nie aplikowano', 'planowana aplikacja']);
const stage = (value?: string): string => value?.trim().toLocaleLowerCase('pl') ?? '';
const hasDate = (value?: string | null): boolean => Boolean(value && Number.isFinite(Date.parse(value)));

/** A placeholder/planned application row is not evidence that a CV was sent. */
export function offerApplicationState(offer: Offer, applications: readonly Application[] = []): OfferApplicationState {
    const linked = applications.filter(application => application.offerId === offer.id);
    if (offer.cvSent === true || hasDate(offer.cvSentAt) || submittedStages.has(stage(offer.applicationStage))
        || linked.some(application => application.cvSent === true || hasDate(application.cvSentAt)
            || hasDate(application.appliedAt) || submittedStages.has(stage(application.applicationStage))
            || submittedStages.has(stage(application.status)))) return 'applied';
    if (offer.cvSent === false || pendingStages.has(stage(offer.applicationStage))
        || linked.some(application => application.cvSent === false
            || pendingStages.has(stage(application.applicationStage)) || pendingStages.has(stage(application.status)))) return 'not_applied';
    return 'unknown';
}

export function matchesApplicationScope(state: OfferApplicationState, scope: OfferApplicationScope): boolean {
    // Missing data stays visible in the inbox with a warning, rather than losing a new opportunity.
    return scope === 'all' || (scope === 'applied' ? state === 'applied' : state !== 'applied');
}

export function indexApplications(applications: readonly Application[]): Map<string, Application[]> {
    const byOffer = new Map<string, Application[]>();
    for (const application of applications) {
        const linked = byOffer.get(application.offerId) ?? [];
        linked.push(application);
        byOffer.set(application.offerId, linked);
    }
    return byOffer;
}

export function offerRows(offers: readonly Offer[], applications: readonly Application[]): OfferRow[] {
    const byOffer = indexApplications(applications);
    return offers.map(offer => {
        const linked = byOffer.get(offer.id) ?? [];
        return { ...offer, applicationCount: linked.length, applicationState: offerApplicationState(offer, linked) };
    });
}

export const applicationStatePresentation: Record<OfferApplicationState, { label: string; color: string }> = {
    not_applied: { label: 'Jeszcze nie aplikowano', color: 'primary' },
    applied: { label: 'Aplikowano', color: 'grey' },
    unknown: { label: 'Sprawdź aplikowanie', color: 'warning' },
};

export function offerAddedAt(offer: Offer): string | null {
    return [offer.firstDetectedAt, offer.createdAt, offer.foundAt].find(value => hasDate(value)) ?? null;
}

export function compareOfferAddedAt(a: Offer, b: Offer): number {
    const first = offerAddedAt(a), second = offerAddedAt(b);
    return (first ? Date.parse(first) : 0) - (second ? Date.parse(second) : 0);
}
