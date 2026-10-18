<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Mail\MailSyncRule;
use Illuminate\Database\Seeder;

class MailSyncRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Idempotent seeder that updates system rule definitions without overriding
     * user modifications (such as is_active toggles).
     */
    public function run(): void
    {
        $defaultRules = [
            // 1. Pracuj.pl
            [
                'system_key' => 'system:pracuj',
                'name' => 'Pracuj.pl — wiadomości i oferty z portalu',
                'portal_name' => 'Pracuj.pl',
                'sender_domain' => 'pracuj.pl',
                'sender_email' => null,
                'subject_keywords' => null,
                'category' => 'recruitment',
                'action' => 'qualify',
                'priority' => 70,
            ],
            // 2. OLX Praca
            [
                'system_key' => 'system:olx',
                'name' => 'OLX Praca — powiadomienia i aplikacje',
                'portal_name' => 'OLX Praca',
                'sender_domain' => 'olx.pl',
                'sender_email' => null,
                'subject_keywords' => null,
                'category' => 'recruitment',
                'action' => 'qualify',
                'priority' => 65,
            ],
            // 3. RocketJobs
            [
                'system_key' => 'system:rocketjobs',
                'name' => 'RocketJobs — oferty i powiadomienia',
                'portal_name' => 'RocketJobs',
                'sender_domain' => 'rocketjobs.pl',
                'sender_email' => null,
                'subject_keywords' => null,
                'category' => 'job_offer',
                'action' => 'qualify',
                'priority' => 70,
            ],
            // 4. Just Join IT
            [
                'system_key' => 'system:justjoinit',
                'name' => 'Just Join IT — powiadomienia i oferty pracy',
                'portal_name' => 'Just Join IT',
                'sender_domain' => 'justjoin.it',
                'sender_email' => null,
                'subject_keywords' => null,
                'category' => 'job_offer',
                'action' => 'qualify',
                'priority' => 70,
            ],
            // 5. No Fluff Jobs
            [
                'system_key' => 'system:nofluffjobs',
                'name' => 'No Fluff Jobs — powiadomienia rekrutacyjne i oferty',
                'portal_name' => 'No Fluff Jobs',
                'sender_domain' => 'nofluffjobs.com',
                'sender_email' => null,
                'subject_keywords' => null,
                'category' => 'job_offer',
                'action' => 'qualify',
                'priority' => 70,
            ],
            // 6. LinkedIn Jobs
            [
                'system_key' => 'system:linkedin',
                'name' => 'LinkedIn Jobs — wiadomości o aplikacjach i rekrutacji',
                'portal_name' => 'LinkedIn Jobs',
                'sender_domain' => 'linkedin.com',
                'sender_email' => null,
                'subject_keywords' => null,
                'category' => 'recruitment',
                'action' => 'qualify',
                'priority' => 65,
            ],
            // 7. Indeed
            [
                'system_key' => 'system:indeed',
                'name' => 'Indeed — powiadomienia i status aplikacji',
                'portal_name' => 'Indeed',
                'sender_domain' => 'indeed.com',
                'sender_email' => null,
                'subject_keywords' => null,
                'category' => 'recruitment',
                'action' => 'qualify',
                'priority' => 65,
            ],
            // 8. the:protocol
            [
                'system_key' => 'system:theprotocol',
                'name' => 'the:protocol — powiadomienia i oferty technologiczne',
                'portal_name' => 'the:protocol',
                'sender_domain' => 'theprotocol.it',
                'sender_email' => null,
                'subject_keywords' => null,
                'category' => 'job_offer',
                'action' => 'qualify',
                'priority' => 70,
            ],
            // 9. Bulldogjob
            [
                'system_key' => 'system:bulldogjob',
                'name' => 'Bulldogjob — wiadomości i powiadomienia IT',
                'portal_name' => 'Bulldogjob',
                'sender_domain' => 'bulldogjob.pl',
                'sender_email' => null,
                'subject_keywords' => null,
                'category' => 'job_offer',
                'action' => 'qualify',
                'priority' => 70,
            ],
            // 10. Praca.pl
            [
                'system_key' => 'system:pracapl',
                'name' => 'Praca.pl — oferty pracy i statusy rekrutacji',
                'portal_name' => 'Praca.pl',
                'sender_domain' => 'praca.pl',
                'sender_email' => null,
                'subject_keywords' => null,
                'category' => 'recruitment',
                'action' => 'qualify',
                'priority' => 65,
            ],

            // 11. Potwierdzenie aplikacji (PL)
            [
                'system_key' => 'system:subj_thanks_pl',
                'name' => 'Dziękujemy za aplikację (PL)',
                'portal_name' => 'Powiadomienia rekrutacyjne',
                'sender_domain' => null,
                'sender_email' => null,
                'subject_keywords' => [
                    'Dziękujemy za aplikację',
                    'Dziękujemy za przesłanie aplikacji',
                    'Potwierdzenie aplikacji',
                    'Twoja aplikacja została przesłana',
                    'Twoja aplikacja na stanowisko',
                    'Aplikacja na stanowisko',
                ],
                'category' => 'recruitment',
                'action' => 'qualify',
                'priority' => 120,
            ],
            // 12. Zaproszenie na rozmowę (PL)
            [
                'system_key' => 'system:subj_interview_pl',
                'name' => 'Zaproszenie na rozmowę (PL)',
                'portal_name' => 'Powiadomienia rekrutacyjne',
                'sender_domain' => null,
                'sender_email' => null,
                'subject_keywords' => [
                    'Zaproszenie na rozmowę',
                    'Zaproszenie na spotkanie rekrutacyjne',
                    'Rozmowa kwalifikacyjna',
                    'Spotkanie rekrutacyjne',
                ],
                'category' => 'recruitment',
                'action' => 'qualify',
                'priority' => 150,
            ],
            // 13. Niestety nie możemy zaprosić (PL)
            [
                'system_key' => 'system:subj_rejection_pl',
                'name' => 'Status rekrutacji / informacja o decyzji (PL)',
                'portal_name' => 'Powiadomienia rekrutacyjne',
                'sender_domain' => null,
                'sender_email' => null,
                'subject_keywords' => [
                    'Niestety nie możemy zaprosić',
                    'nie możemy zaprosić Cię do kolejnego etapu',
                    'Dziękujemy za udział w rekrutacji',
                    'informacja o statusie rekrutacji',
                    'Status Twojej aplikacji',
                ],
                'category' => 'recruitment',
                'action' => 'qualify',
                'priority' => 130,
            ],
            // 14. Application received (EN)
            [
                'system_key' => 'system:subj_application_en',
                'name' => 'Application received / confirmation (EN)',
                'portal_name' => 'Powiadomienia rekrutacyjne',
                'sender_domain' => null,
                'sender_email' => null,
                'subject_keywords' => [
                    'Application received',
                    'Thank you for applying',
                    'We received your application',
                    'Application confirmation',
                    'Confirmation of your application',
                ],
                'category' => 'recruitment',
                'action' => 'qualify',
                'priority' => 120,
            ],
            // 15. Interview invitation (EN)
            [
                'system_key' => 'system:subj_interview_en',
                'name' => 'Interview invitation (EN)',
                'portal_name' => 'Powiadomienia rekrutacyjne',
                'sender_domain' => null,
                'sender_email' => null,
                'subject_keywords' => [
                    'Interview invitation',
                    'Invitation to interview',
                    'Invitation to an interview',
                    'Schedule your interview',
                    'Interview with',
                ],
                'category' => 'recruitment',
                'action' => 'qualify',
                'priority' => 150,
            ],
            // 16. Unfortunately / Decision (EN)
            [
                'system_key' => 'system:subj_rejection_en',
                'name' => 'Application status / decision (EN)',
                'portal_name' => 'Powiadomienia rekrutacyjne',
                'sender_domain' => null,
                'sender_email' => null,
                'subject_keywords' => [
                    'Unfortunately we cannot',
                    'not moving forward with your application',
                    'Thank you for your interest in',
                    'Update on your application',
                ],
                'category' => 'recruitment',
                'action' => 'qualify',
                'priority' => 130,
            ],
        ];

        foreach ($defaultRules as $ruleData) {
            $existing = MailSyncRule::where('system_key', $ruleData['system_key'])->first();

            if ($existing) {
                // Update definitions without overwriting user's is_active choice
                $existing->update([
                    'name' => $ruleData['name'],
                    'portal_name' => $ruleData['portal_name'],
                    'sender_domain' => $ruleData['sender_domain'],
                    'sender_email' => $ruleData['sender_email'],
                    'subject_keywords' => $ruleData['subject_keywords'],
                    'category' => $ruleData['category'],
                    'action' => $ruleData['action'],
                    'priority' => $ruleData['priority'],
                    'is_system' => true,
                ]);
            } else {
                MailSyncRule::create(array_merge($ruleData, [
                    'is_system' => true,
                    'is_active' => true,
                ]));
            }
        }
    }
}
