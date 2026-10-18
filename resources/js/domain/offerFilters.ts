import type { Offer } from '../types/recruitment.ts';
import { matchesSearch } from './recruitment.ts';
import { meetsMinimumAiScore } from './aiMatching.ts';
import { indexApplications, matchesApplicationScope, offerApplicationState } from './offerInbox.ts';
import type { OfferApplicationScope } from './offerInbox.ts';
import type { Application } from '../types/recruitment.ts';
export const emptyOfferFilters = (applicationScope: OfferApplicationScope = 'all') => ({
    applicationScope,
    search: '',
    status: null as string | null,
    company: null as string | null,
    industry: null as string | null,
    jobCategory: null as string | null,
    seniority: null as string | null,
    workMode: null as string | null,
    location: '',
    salaryMin: null as number | string | null,
    employment: null as string | null,
    stage: null as string | null,
    cvSent: null as boolean | null,
    favorite: false,
    minimumAiScore: null as number | string | null,
    technology: '',
    showHidden: false,
    showArchived: false,
});
export type OfferFilters = ReturnType<typeof emptyOfferFilters>;
export function filterOffers(offers: Offer[], f: OfferFilters, applications: readonly Application[] = []): Offer[] {
    const byOffer = indexApplications(applications);
    return offers.filter(
        (o) =>
            matchesApplicationScope(offerApplicationState(o, byOffer.get(o.id)), f.applicationScope ?? 'all') &&
            (f.showHidden || !o.hidden) &&
            (f.showArchived || !o.archived) &&
            (!f.status || o.status === f.status) &&
            (!f.company || o.company === f.company) &&
            (!f.industry || o.industry === f.industry) &&
            (!f.jobCategory || o.jobCategory === f.jobCategory) &&
            (!f.seniority || o.seniority === f.seniority) &&
            (!f.workMode || o.workMode === f.workMode) &&
            (!f.location ||
                matchesSearch(f.location, [
                    o.location,
                    o.region ?? '',
                    o.country ?? '',
                ])) &&
            (!f.employment ||
                o.employment === f.employment ||
                o.contractTypes?.includes(f.employment)) &&
            (!f.stage || o.applicationStage === f.stage) &&
            (f.cvSent === null || o.cvSent === f.cvSent) &&
            (!f.favorite || o.favorite === true) &&
            (f.salaryMin === null ||
                f.salaryMin === '' ||
                Math.max(o.salaryMin ?? -1, o.salaryMax ?? -1) >=
                    Number(f.salaryMin)) &&
            meetsMinimumAiScore(o.aiMatchScore, f.minimumAiScore) &&
            (!f.technology ||
                matchesSearch(f.technology, [
                    ...(o.programmingLanguages ?? []),
                    ...(o.requiredSkills ?? []),
                    ...(o.requirements ?? []),
                ])) &&
            matchesSearch(f.search, [
                o.title,
                o.company,
                o.location,
                o.externalKey ?? '',
                o.myNotes ?? '',
                o.industry ?? '',
            ]),
    );
}
