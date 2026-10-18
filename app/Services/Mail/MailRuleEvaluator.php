<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Mail\MailMessage;
use App\Models\Mail\MailSyncRule;

class MailRuleEvaluator
{
    /**
     * Evaluates a message against active rules respecting the priority hierarchy:
     * 1. Manual user decision (never overridden if is_manually_classified is true)
     * 2. User-defined custom rules (sorted by priority DESC)
     * 3. System rules (sorted by priority DESC)
     *
     * @return array{category: string, status: string, matched_rule: ?MailSyncRule, source: string}
     */
    public function evaluate(
        string $senderEmail,
        string $subject,
        ?string $mailAccountId = null,
        ?MailMessage $existingMessage = null
    ): array {
        // 1. Manual user decision takes absolute precedence
        if ($existingMessage !== null && $existingMessage->is_manually_classified) {
            return [
                'category' => $existingMessage->category,
                'status' => $existingMessage->status,
                'matched_rule' => $existingMessage->matchedRule,
                'source' => 'manual',
            ];
        }

        // 2. User-defined custom rules (is_system = false, is_active = true)
        $userRules = MailSyncRule::query()
            ->where('is_system', false)
            ->where('is_active', true)
            ->where(function ($q) use ($mailAccountId) {
                $q->whereNull('mail_account_id');
                if ($mailAccountId !== null) {
                    $q->orWhere('mail_account_id', $mailAccountId);
                }
            })
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->get();

        foreach ($userRules as $rule) {
            if ($rule->matches($senderEmail, $subject)) {
                return [
                    'category' => $rule->category,
                    'status' => $rule->action === 'ignore' ? 'ignored' : 'to_review',
                    'matched_rule' => $rule,
                    'source' => 'user_rule',
                ];
            }
        }

        // 3. System default rules (is_system = true, is_active = true)
        $systemRules = MailSyncRule::query()
            ->where('is_system', true)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        foreach ($systemRules as $rule) {
            if ($rule->matches($senderEmail, $subject)) {
                return [
                    'category' => $rule->category,
                    'status' => $rule->action === 'ignore' ? 'ignored' : 'to_review',
                    'matched_rule' => $rule,
                    'source' => 'system_rule',
                ];
            }
        }

        // Default: unclassified
        return [
            'category' => 'other',
            'status' => 'new',
            'matched_rule' => null,
            'source' => 'none',
        ];
    }
}
