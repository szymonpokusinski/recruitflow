<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMappingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['sheets' => ['required', 'array', 'min:1', 'max:20'], 'sheets.*.schema_profile' => ['sometimes', Rule::in(['generic', 'recruitflow'])], 'sheets.*.sheet_id' => ['required', 'integer', 'min:0', 'distinct'], 'sheets.*.sheet_title' => ['required', 'string', 'max:255'], 'sheets.*.role' => ['required', Rule::in(['offers', 'applications', 'events', 'profiles', 'other'])], 'sheets.*.header_row' => ['required', 'integer', 'min:1', 'max:100'], 'sheets.*.mapping' => ['present', 'array'], 'sheets.*.mapping.*' => ['required', 'string', 'max:255']];
    }
}
