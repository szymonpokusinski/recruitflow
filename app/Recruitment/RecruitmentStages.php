<?php

namespace App\Recruitment;

class RecruitmentStages
{
    public const LABELS = [
        'not_applied' => 'Nie aplikowano', 'planned' => 'Planowana aplikacja',
        'sent' => 'Wysłano CV', 'confirmed' => 'Potwierdzono przyjęcie',
        'hr_contact' => 'Kontakt HR', 'hr_interview' => 'Rozmowa HR',
        'technical_task' => 'Zadanie techniczne', 'technical_interview' => 'Rozmowa techniczna',
        'final' => 'Etap końcowy', 'offer' => 'Oferta pracy', 'rejected' => 'Odrzucono',
        'withdrawn' => 'Wycofano', 'hired' => 'Zatrudniono',
    ];

    public static function afterCv(string $current): string
    {
        return in_array($current, ['', 'new', 'unknown', 'not_applied', 'planned'], true) ? 'sent' : $current;
    }
}
