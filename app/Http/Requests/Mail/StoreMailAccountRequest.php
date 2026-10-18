<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use Illuminate\Foundation\Http\FormRequest;

class StoreMailAccountRequest extends FormRequest
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
        return [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:mail_accounts,email'],
            'password' => [$this->input('auth_type') === 'oauth' ? 'nullable' : 'required', 'string', 'min:1', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'auth_type' => ['nullable', 'string', 'in:password,oauth'],
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
            'password.required' => 'Hasło jest wymagane.',
        ];
    }
}
