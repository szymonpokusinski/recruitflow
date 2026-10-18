<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Models\MailAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailSyncRule extends Model
{
    use HasUuids;

    protected $table = 'mail_sync_rules';

    protected $attributes = [
        'is_system' => false,
        'category' => 'recruitment',
        'action' => 'qualify',
        'priority' => 100,
        'is_active' => true,
    ];

    protected $fillable = [
        'system_key',
        'name',
        'is_system',
        'mail_account_id',
        'portal_name',
        'sender_email',
        'sender_domain',
        'subject_keywords',
        'category',
        'action',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'subject_keywords' => 'array',
        ];
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    public function matchedMessages(): HasMany
    {
        return $this->hasMany(MailMessage::class, 'matched_rule_id');
    }

    /**
     * Checks if this rule matches a given email and subject.
     */
    public function matches(string $senderEmail, string $subject): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $senderEmail = strtolower(trim($senderEmail));
        $senderDomain = '';
        if (str_contains($senderEmail, '@')) {
            $senderDomain = substr(strrchr($senderEmail, '@') ?: '', 1);
        }

        $hasSenderCondition = ! empty($this->sender_email) || ! empty($this->sender_domain);
        $hasSubjectCondition = ! empty($this->subject_keywords);

        // If no conditions defined, cannot match
        if (! $hasSenderCondition && ! $hasSubjectCondition) {
            return false;
        }

        // Evaluate sender condition if defined
        $senderMatches = true;
        if ($hasSenderCondition) {
            $senderMatches = false;
            if (! empty($this->sender_email) && strtolower(trim($this->sender_email)) === $senderEmail) {
                $senderMatches = true;
            } elseif (! empty($this->sender_domain)) {
                $domainPattern = strtolower(trim($this->sender_domain));
                if ($senderDomain === $domainPattern || str_ends_with($senderDomain, '.' . $domainPattern)) {
                    $senderMatches = true;
                }
            }
        }

        // Evaluate subject condition if defined
        $subjectMatches = true;
        if ($hasSubjectCondition) {
            $subjectMatches = false;
            $subjectLower = mb_strtolower($subject, 'UTF-8');
            $keywords = (array) $this->subject_keywords;

            foreach ($keywords as $keyword) {
                $keywordLower = mb_strtolower(trim((string) $keyword), 'UTF-8');
                if ($keywordLower !== '' && str_contains($subjectLower, $keywordLower)) {
                    $subjectMatches = true;
                    break;
                }
            }
        }

        // If both conditions are defined, both must match
        if ($hasSenderCondition && $hasSubjectCondition) {
            return $senderMatches && $subjectMatches;
        }

        // If only sender is defined
        if ($hasSenderCondition) {
            return $senderMatches;
        }

        // If only subject is defined
        return $subjectMatches;
    }
}
