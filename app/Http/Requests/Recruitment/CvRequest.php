<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class CvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['operationId' => ['required', 'uuid'], 'sent' => ['required', 'boolean'], 'date' => ['required_if:sent,true', 'nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2101-01-01'], 'time' => ['nullable', 'date_format:H:i'], 'timezone' => ['required', 'timezone:all'], 'channel' => ['nullable', 'string', 'max:100'], 'cvVersion' => ['nullable', 'string', 'max:200'], 'coverLetterVersion' => ['nullable', 'string', 'max:200'], 'reference' => ['nullable', 'string', 'max:200'], 'note' => ['nullable', 'string', 'max:5000'], 'actor' => ['nullable', 'string', 'max:120']];
    }
}
