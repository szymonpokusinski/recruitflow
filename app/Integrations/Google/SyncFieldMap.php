<?php

namespace App\Integrations\Google;

use App\Recruitment\OfferFields;

class SyncFieldMap
{
    public const MAPS = [
        'applications' => ['externalKey' => 'application_id', 'offerKey' => 'offer_key', 'source' => 'application_source', 'cvVersion' => 'cv_version_sent', 'coverLetterVersion' => 'cover_letter_version', 'reference' => 'application_reference', 'cvSent' => 'cv_sent', 'cvSentAt' => 'cv_sent_at', 'applicationStage' => 'application_stage', 'confirmationAt' => 'confirmation_at', 'firstReplyAt' => 'first_reply_at', 'lastReplyAt' => 'last_reply_at', 'lastReplyType' => 'last_reply_type', 'recruiterName' => 'recruiter_name', 'recruiterEmail' => 'recruiter_email', 'recruiterPhone' => 'recruiter_phone', 'nextStep' => 'next_interview_at', 'interviewLink' => 'next_interview_link', 'taskDeadlineAt' => 'task_deadline_at', 'salaryExpectation' => 'salary_expectation', 'feedbackNotes' => 'feedback_notes', 'rejectionAt' => 'rejection_at', 'rejectionReason' => 'rejection_reason'],
        'events' => ['externalKey' => 'event_id', 'offerKey' => 'offer_key', 'applicationKey' => 'application_id', 'eventType' => 'event_type', 'eventSubtype' => 'event_subtype', 'occurredAt' => 'event_at', 'message' => 'summary', 'source' => 'source', 'channel' => 'channel', 'direction' => 'direction', 'contactName' => 'contact_name', 'contactEmail' => 'contact_email', 'requiresAction' => 'requires_action', 'actionDeadlineAt' => 'action_deadline_at', 'interviewAt' => 'interview_at', 'statusBefore' => 'status_before', 'statusAfter' => 'status_after'],
        'history' => ['externalKey' => 'change_id', 'offerKey' => 'offer_key', 'occurredAt' => 'change_at', 'field' => 'field', 'oldValue' => 'old_value', 'newValue' => 'new_value', 'actor' => 'actor', 'source' => 'source', 'notes' => 'notes'],
    ];

    public const INTERNAL = ['id', 'version', 'schemaProfile', 'completionStatus', 'createdAt', 'offerId', 'applicationId', 'candidateName', 'candidateEmail', 'candidateRole', 'owner', 'location', 'status', 'rawStatus', 'appliedAt', 'cvSentPrecision', 'kind', 'actor', 'extraData', 'department'];

    public static function fields(string $role): array
    {
        if ($role !== 'offers') {
            return self::MAPS[$role] ?? [];
        }
        $map = [];
        foreach (OfferFields::DEFINITIONS as $key => $definition) {
            if ($definition[3]) {
                $map[$key] = $definition[3];
            }
        }

        return $map + ['externalKey' => 'offer_key', 'cvSent' => 'cv_sent', 'cvSentAt' => 'cv_sent_at', 'applicationStage' => 'application_stage', 'foundBy' => 'found_by'];
    }

    public static function isDate(string $field): bool
    {
        return in_array($field, ['cvSentAt', 'occurredAt', 'confirmationAt', 'firstReplyAt', 'lastReplyAt', 'nextStep', 'taskDeadlineAt', 'rejectionAt', 'interviewAt', 'actionDeadlineAt'], true) || in_array(OfferFields::DEFINITIONS[$field][1] ?? '', ['date', 'datetime'], true);
    }
}
