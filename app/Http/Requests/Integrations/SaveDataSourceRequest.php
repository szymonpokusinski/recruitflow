<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class SaveDataSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'spreadsheet' => ['required', 'string', 'max:2048'], 'integration_account_id' => ['required', 'uuid', 'exists:integration_accounts,id'], 'sync_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'], 'read_only' => ['required', 'accepted']];
    }
}
