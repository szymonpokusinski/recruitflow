import type {
    ActivityDTO,
    ActivityRow,
    ApplicationDTO,
    ApplicationRow,
    ApplicationStage,
    OfferDTO,
    Offer,
    OfferStatus,
    RecruitmentSnapshot,
} from '../types/recruitment.ts';

export const applicationStages: {
    value: ApplicationStage;
    label: string;
    color: string;
}[] = [
    { value: 'not_applied', label: 'Nie aplikowano', color: 'grey' },
    { value: 'planned', label: 'Planowana aplikacja', color: 'blue-grey' },
    { value: 'hr_contact', label: 'Kontakt HR', color: 'blue' },
    { value: 'hr_interview', label: 'Rozmowa HR', color: 'purple' },
    { value: 'technical_task', label: 'Zadanie techniczne', color: 'purple' },
    {
        value: 'technical_interview',
        label: 'Rozmowa techniczna',
        color: 'purple',
    },
    { value: 'final', label: 'Etap końcowy', color: 'orange' },
    { value: 'withdrawn', label: 'Wycofano', color: 'grey' },
    { value: 'sent', label: 'Wysłano CV', color: 'blue' },
    { value: 'confirmed', label: 'Potwierdzono przyjęcie', color: 'blue' },
    { value: 'new', label: 'Nowa', color: 'blue-grey' },
    { value: 'screening', label: 'Selekcja', color: 'blue' },
    { value: 'interview', label: 'Rozmowa', color: 'purple' },
    { value: 'offer', label: 'Oferta złożona', color: 'orange' },
    { value: 'hired', label: 'Zatrudniona', color: 'green' },
    { value: 'rejected', label: 'Odrzucona', color: 'grey' },
    { value: 'unknown', label: 'Nieznany etap', color: 'grey' },
];
export const offerStatuses: {
    value: OfferStatus;
    label: string;
    color: string;
}[] = [
    { value: 'unconfirmed', label: 'Niepotwierdzona', color: 'grey' },
    { value: 'open', label: 'Aktywna', color: 'green' },
    { value: 'paused', label: 'Wstrzymana', color: 'orange' },
    { value: 'closed', label: 'Zamknięta', color: 'grey' },
    { value: 'unknown', label: 'Nieznany status', color: 'grey' },
];
export const stageLabel = (stage: ApplicationStage): string =>
    applicationStages.find((item) => item.value === stage)?.label ?? stage;
export function mapOffer(dto: OfferDTO) {
    return {
        id: dto.id,
        title: dto.title,
        company: dto.company,
        department: dto.department,
        location: dto.location,
        workMode: dto.work_mode,
        employment: dto.employment,
        salaryMin: dto.salary_min,
        salaryMax: dto.salary_max,
        currency: dto.currency,
        status: dto.status,
        owner: dto.owner,
        createdAt: dto.created_at,
        description: dto.description,
        requirements: [...dto.requirements],
        aiMatchScore: dto.ai_match_score ?? null,
        aiStackScore: dto.ai_stack_score ?? null,
        aiSeniorityScore: dto.ai_seniority_score ?? null,
        aiLocationScore: dto.ai_location_score ?? null,
        aiSalaryScore: dto.ai_salary_score ?? null,
        aiFitReasons: dto.ai_fit_reasons ?? null,
        aiFitConcerns: dto.ai_fit_concerns ?? null,
        aiRecommendation: dto.ai_recommendation ?? null,
        aiCvSuggestions: dto.ai_cv_suggestions ?? null,
        aiEvaluationConfidence: dto.ai_evaluation_confidence ?? null,
        aiEvaluatedAt: dto.ai_evaluated_at ?? null,
    };
}
export function mapApplication(dto: ApplicationDTO) {
    return {
        id: dto.id,
        offerId: dto.offer_id,
        candidateName: dto.candidate_name,
        candidateEmail: dto.candidate_email,
        candidateRole: dto.candidate_role,
        location: dto.location,
        status: dto.status,
        owner: dto.owner,
        source: dto.source,
        appliedAt: dto.applied_at,
        nextStep: dto.next_step,
    };
}
export function mapActivity(dto: ActivityDTO) {
    return {
        id: dto.id,
        applicationId: dto.application_id,
        kind: dto.kind,
        actor: dto.actor,
        message: dto.message,
        occurredAt: dto.occurred_at,
    };
}
export function applicationRows(
    snapshot: RecruitmentSnapshot,
): ApplicationRow[] {
    const offersById = new Map(
        snapshot.offers.map((offer) => [offer.id, offer]),
    );
    return snapshot.applications.map((application) => {
        const offer = offersById.get(application.offerId);
        return {
            ...application,
            displayName:
                application.candidateName ||
                offer?.title ||
                application.externalKey ||
                application.id,
            offerTitle: offer?.title ?? 'Nieznana oferta',
            company: offer?.company ?? '—',
            aiMatchScore: offer?.aiMatchScore ?? null,
        };
    });
}
export function activityRows(snapshot: RecruitmentSnapshot): ActivityRow[] {
    return snapshot.activities
        .map((activity) => {
            const application = snapshot.applications.find(
                (item) => item.id === activity.applicationId,
            );
            const offer = snapshot.offers.find(
                (item) =>
                    item.id === (activity.offerId ?? application?.offerId),
            );
            return {
                ...activity,
                candidateName:
                    application?.candidateName ||
                    offer?.title ||
                    'Zdarzenie rekrutacyjne',
                offerTitle: offer?.title ?? '—',
            };
        })
        .sort((a, b) => b.occurredAt.localeCompare(a.occurredAt));
}
export const matchesSearch = (query: string, values: string[]): boolean => {
    const normalize = (value: string): string =>
        value
            .toLocaleLowerCase('pl')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/ł/g, 'l');
    return normalize(values.join(' ')).includes(normalize(query.trim()));
};
export function dashboardStats(snapshot: RecruitmentSnapshot) {
    return {
        activeOffers: snapshot.offers.filter((item) => item.status === 'open')
            .length,
        activeApplications: snapshot.applications.filter((item) =>
            [
                'planned',
                'hr_contact',
                'hr_interview',
                'technical_task',
                'technical_interview',
                'final',
                'new',
                'sent',
                'confirmed',
                'screening',
                'interview',
                'offer',
            ].includes(item.status),
        ).length,
        newApplications: snapshot.applications.filter(
            (item) => item.status === 'new',
        ).length,
        interviews: snapshot.applications.filter(
            (item) => item.status === 'interview',
        ).length,
    };
}
export const formatDate = (value: string | null): string =>
    !value || Number.isNaN(new Date(value).getTime())
        ? '—'
        : new Intl.DateTimeFormat('pl-PL', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          }).format(new Date(value));
export const formatDateTime = (value: string | null): string =>
    !value || Number.isNaN(new Date(value).getTime())
        ? '—'
        : new Intl.DateTimeFormat('pl-PL', {
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          }).format(new Date(value));
export function formatSalary(
    offer: Pick<Offer, 'salaryMin' | 'salaryMax' | 'currency'>,
): string {
    if (offer.salaryMin === null && offer.salaryMax === null) return '—';
    const amount =
        offer.salaryMin !== null && offer.salaryMax !== null
            ? `${offer.salaryMin.toLocaleString('pl-PL')}–${offer.salaryMax.toLocaleString('pl-PL')}`
            : offer.salaryMin !== null
              ? `od ${offer.salaryMin.toLocaleString('pl-PL')}`
              : `do ${offer.salaryMax!.toLocaleString('pl-PL')}`;
    return amount + (offer.currency ? ` ${offer.currency}` : '');
}
