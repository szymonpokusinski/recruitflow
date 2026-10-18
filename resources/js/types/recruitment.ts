export type OfferStatus =
    | 'open'
    | 'paused'
    | 'closed'
    | 'unknown'
    | 'unconfirmed';
export type ApplicationStage =
    | 'new'
    | 'not_applied'
    | 'planned'
    | 'hr_contact'
    | 'hr_interview'
    | 'technical_task'
    | 'technical_interview'
    | 'final'
    | 'withdrawn'
    | 'sent'
    | 'confirmed'
    | 'screening'
    | 'interview'
    | 'offer'
    | 'hired'
    | 'rejected'
    | 'unknown';
export type ActivityKind = 'applied' | 'status' | 'note' | 'unknown';
export type ThemePreference = 'system' | 'light' | 'dark';

export interface OfferAiEvaluation {
    aiMatchScore?: number | null;
    aiStackScore?: number | null;
    aiSeniorityScore?: number | null;
    aiLocationScore?: number | null;
    aiSalaryScore?: number | null;
    aiFitReasons?: string | null;
    aiFitConcerns?: string | null;
    aiRecommendation?: string | null;
    aiCvSuggestions?: string | null;
    aiEvaluationConfidence?: string | number | null;
    aiEvaluatedAt?: string | number | null;
}

// DTOs form the boundary for a future remote adapter. Views use domain models.
export interface OfferDTO {
    ai_match_score?: number | null;
    ai_stack_score?: number | null;
    ai_seniority_score?: number | null;
    ai_location_score?: number | null;
    ai_salary_score?: number | null;
    ai_fit_reasons?: string | null;
    ai_fit_concerns?: string | null;
    ai_recommendation?: string | null;
    ai_cv_suggestions?: string | null;
    ai_evaluation_confidence?: string | number | null;
    ai_evaluated_at?: string | null;
    id: string;
    title: string;
    company: string;
    department: string;
    location: string;
    work_mode: string;
    employment: string;
    salary_min: number | null;
    salary_max: number | null;
    currency: string;
    status: OfferStatus;
    owner: string;
    created_at: string | null;
    description: string;
    requirements: string[];
}
export interface ApplicationDTO {
    id: string;
    offer_id: string;
    candidate_name: string;
    candidate_email: string;
    candidate_role: string;
    location: string;
    status: ApplicationStage;
    owner: string;
    source: string;
    applied_at: string | null;
    next_step: string | null;
}
export interface ActivityDTO {
    id: string;
    application_id: string;
    kind: ActivityKind;
    actor: string;
    message: string;
    occurred_at: string;
}
export interface Offer extends OfferAiEvaluation {
    firstDetectedAt?: string | null;
    foundAt?: string | null;
    origin?: 'local' | 'google_sheets';
    sourceId?: string | null;
    syncStatus?: string;
    localOnlyFields?: string[];
    remoteMissing?: boolean;
    version?: number;
    industry?: string;
    jobCategory?: string;
    specialization?: string;
    completionStatus?: string;
    archived?: boolean;
    hidden?: boolean;
    watchOffer?: boolean;
    nextAction?: string;
    nextActionAt?: string | null;
    platform?: string;
    salaryBasis?: string;
    requiredSkills?: string[];
    programmingLanguages?: string[];
    contractTypes?: string[];
    customAttributes?: { label: string; value: string }[];
    aiProfileId?: string | null;

    schemaProfile?: string;
    offerUrl?: string;
    portalStatus?: string;
    effectiveStatus?: string;
    applicationStage?: string;
    cvSent?: boolean | null;
    cvSentAt?: string | null;
    salaryPeriod?: string;
    seniority?: string;
    region?: string;
    country?: string;
    priority?: string;
    favorite?: boolean | null;
    myInterest?: string | boolean | null;
    myNotes?: string;
    applyDeadlineAt?: string | null;
    extraData?: Record<string, unknown>;
    id: string;
    externalKey?: string;
    rawStatus?: string;
    title: string;
    company: string;
    department: string;
    location: string;
    workMode: string;
    employment: string;
    salaryMin: number | null;
    salaryMax: number | null;
    currency: string;
    status: OfferStatus;
    owner: string;
    createdAt: string | null;
    description: string;
    requirements: string[];
}
export interface Application {
    applicationStage?: string;
    cvVersion?: string;
    coverLetterVersion?: string;
    reference?: string;
    recruiterName?: string;
    recruiterEmail?: string;
    recruiterPhone?: string;
    feedbackNotes?: string;
    interviewLink?: string;
    taskDeadlineAt?: string | null;
    salaryExpectation?: number | null;

    schemaProfile?: string;
    cvSent?: boolean | null;
    cvSentAt?: string | null;
    confirmationAt?: string | null;
    firstReplyAt?: string | null;
    lastReplyAt?: string | null;
    rejectionAt?: string | null;
    extraData?: Record<string, unknown>;
    id: string;
    externalKey?: string;
    rawStatus?: string;
    offerId: string;
    candidateName: string;
    candidateEmail: string;
    candidateRole: string;
    location: string;
    status: ApplicationStage;
    owner: string;
    source: string;
    appliedAt: string | null;
    nextStep: string | null;
}
export interface Activity {
    offerId?: string | null;
    eventType?: string;
    eventSubtype?: string;
    source?: string;
    gmailUrl?: string;
    requiresAction?: boolean | null;
    actionDeadlineAt?: string | null;
    interviewAt?: string | null;
    direction?: string;
    channel?: string;
    extraData?: Record<string, unknown>;
    id: string;
    applicationId: string | null;
    kind: ActivityKind;
    actor: string;
    message: string;
    occurredAt: string;
}
export interface ApplicationRow extends Application {
    // Derived at read time from the linked offer, never persisted on applications.
    aiMatchScore: number | null;
    displayName: string;
    offerTitle: string;
    company: string;
}
export interface ActivityRow extends Activity {
    candidateName: string;
    offerTitle: string;
}
export interface SearchProfile {
    industry?: string;
    jobCategory?: string;
    keywords?: string[];
    excludedKeywords?: string[];
    skills?: string[];
    preferredSkills?: string[];
    location?: string;
    workMode?: string;
    employment?: string;
    salaryMin?: number | null;
    minAiScore?: number | null;

    id: string;
    externalKey: string;
    enabled: boolean | null;
    name?: string;
    main_language?: string;
    role_keywords?: string;
    seniority?: string;
    required_stack?: string;
    nice_stack?: string;
    country?: string;
    city_or_region?: string;
    remote_mode?: string;
    salary_min: number | null;
    min_match_score: number | null;
    extraData?: Record<string, unknown>;
}
export interface RecruitmentSnapshot {
    activeProfileId?: string | null;
    evaluations?: {
        offerId: string;
        profileId: string;
        data: OfferAiEvaluation;
    }[];

    profiles?: SearchProfile[];
    offers: Offer[];
    applications: Application[];
    activities: Activity[];
}
export interface RecruitmentService {
    getSnapshot(): Promise<RecruitmentSnapshot>;
    changeStatus(
        id: string,
        status: ApplicationStage,
        actor: string,
    ): Promise<void>;
    addNote(id: string, note: string, actor: string): Promise<void>;
    resetDemo(): Promise<void>;
}
export interface SharedProps {
    [key: string]: unknown;
    appName: string;
    runtime: 'native' | 'browser';
    demo: boolean;
}
