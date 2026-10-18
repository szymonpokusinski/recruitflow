<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['operationId' => ['required', 'uuid'], 'eventType' => ['required', 'string', 'max:100'], 'eventSubtype' => ['nullable', 'string', 'max:100'], 'occurredAt' => ['required', 'date', 'after_or_equal:2000-01-01', 'before:2101-01-01'], 'message' => ['required', 'string', 'max:10000'], 'channel' => ['nullable', 'string', 'max:100'], 'contactName' => ['nullable', 'string', 'max:200'], 'contactEmail' => ['nullable', 'email', 'max:254'], 'requiresAction' => ['sometimes', 'boolean'], 'actionDeadlineAt' => ['nullable', 'date', 'after_or_equal:2000-01-01', 'before:2101-01-01'], 'interviewAt' => ['nullable', 'date', 'after_or_equal:2000-01-01', 'before:2101-01-01'], 'actor' => ['nullable', 'string', 'max:120']];
    }
}
