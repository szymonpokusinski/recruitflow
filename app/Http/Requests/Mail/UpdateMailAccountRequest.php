<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use App\Models\MailAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMailAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->route('mailAccount') ?? $this->route('mail_account') ?? $this->route('id');
        $accountId = $target instanceof MailAccount ? $target->id : $target;

        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('mail_accounts', 'email')->ignore($accountId),
            ],
            'password' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'auto_sync' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Adres e-mail jest wymagany.',
            'email.email' => 'Podaj prawidłowy adres e-mail.',
            'email.unique' => 'Konto pocztowe z tym adresem e-mail już istnieje.',
        ];
    }
}
