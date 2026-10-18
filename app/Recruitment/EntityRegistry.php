<?php

namespace App\Recruitment;

use App\Models\Recruitment\Application;
use App\Models\Recruitment\Offer;
use App\Models\Recruitment\RecruitmentEvent;
use App\Models\Recruitment\SearchProfile;

class EntityRegistry
{
    public const MODELS = ['offers' => Offer::class, 'applications' => Application::class, 'events' => RecruitmentEvent::class, 'profiles' => SearchProfile::class];
}
