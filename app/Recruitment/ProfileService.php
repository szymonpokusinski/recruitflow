<?php

namespace App\Recruitment;

use App\Integrations\Mapping\RecruitmentMapper;
use App\Models\Recruitment\RecruitmentSetting;
use App\Models\Recruitment\SearchProfile;

class ProfileService
{
    public function __construct(private CommandRunner $commands, private LocalRecruitmentService $recruitment) {}

    public function save(array $values, ?SearchProfile $profile = null): array
    {
        return $this->commands->run($values['operationId'], 'profile.save', $values, function () use ($values, $profile) {
            if (! $profile) {
                $key = 'LOCAL_PROFILE_'.$values['operationId'];
                $profile = SearchProfile::create(['id' => RecruitmentMapper::stableId('profiles', $key), 'external_key' => $key, 'origin' => 'local', 'data' => []]);
            } else {
                $profile->refresh();
            }
            $data = collect($values)->except(['operationId', 'actor'])->all() + ['id' => $profile->id, 'externalKey' => $profile->external_key];
            $data['salary_min'] = $values['salaryMin'] ?? $profile->data['salary_min'] ?? null;
            $data['min_match_score'] = $values['minAiScore'] ?? $profile->data['min_match_score'] ?? null;
            $data['enabled'] = $values['enabled'] ?? true;
            $this->recruitment->recordChange($profile, 'profiles', $data, $values['operationId'], $values['actor'] ?? 'Użytkownik lokalny', 'Zmieniono profil wyszukiwania', null);

            return ['id' => $profile->id];
        });
    }

    public function select(?string $id): void
    {
        RecruitmentSetting::updateOrCreate(['key' => 'active_profile'], ['value' => ['id' => $id]]);
    }
}
