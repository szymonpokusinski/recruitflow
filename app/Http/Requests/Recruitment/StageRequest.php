<?php

namespace App\Http\Requests\Recruitment;

use App\Recruitment\RecruitmentStages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['operationId' => ['required', 'uuid'], 'stage' => ['required', Rule::in(array_keys(RecruitmentStages::LABELS))], 'note' => ['nullable', 'string', 'max:5000'], 'actor' => ['nullable', 'string', 'max:120']];
    }
}
