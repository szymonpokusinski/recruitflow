<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\Mail\MailSyncRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MailSyncRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $rules = MailSyncRule::query()
            ->with('mailAccount:id,name,email,provider')
            ->withCount('matchedMessages')
            ->orderBy('is_system') // User rules first (0 before 1)
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        return response()->json([
            'rules' => $rules,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'mail_account_id' => 'nullable|uuid|exists:mail_accounts,id',
            'portal_name' => 'nullable|string|max:100',
            'sender_email' => 'nullable|email|max:255',
            'sender_domain' => 'nullable|string|max:255',
            'subject_keywords' => 'nullable|array',
            'subject_keywords.*' => 'string|max:100',
            'category' => 'required|in:job_offer,recruitment,other',
            'action' => 'required|in:qualify,ignore',
            'priority' => 'nullable|integer|min:1|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        $hasSender = ! empty($validated['sender_email']) || ! empty($validated['sender_domain']);
        $hasSubject = ! empty($validated['subject_keywords']) && count($validated['subject_keywords']) > 0;

        if (! $hasSender && ! $hasSubject) {
            throw ValidationException::withMessages([
                'conditions' => ['Musisz podać co najmniej jeden warunek: adres e-mail nadawcy, domenę lub słowa kluczowe tematu.'],
            ]);
        }

        // Clean domain if provided
        if (! empty($validated['sender_domain'])) {
            $validated['sender_domain'] = strtolower(ltrim(trim($validated['sender_domain']), '@.'));
        }

        $rule = MailSyncRule::create([
            'name' => trim($validated['name']),
            'is_system' => false,
            'mail_account_id' => $validated['mail_account_id'] ?? null,
            'portal_name' => ! empty($validated['portal_name']) ? trim($validated['portal_name']) : null,
            'sender_email' => ! empty($validated['sender_email']) ? strtolower(trim($validated['sender_email'])) : null,
            'sender_domain' => $validated['sender_domain'] ?? null,
            'subject_keywords' => $validated['subject_keywords'] ?? null,
            'category' => $validated['category'],
            'action' => $validated['action'],
            'priority' => $validated['priority'] ?? 100,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $rule->load('mailAccount:id,name,email,provider');
        $rule->loadCount('matchedMessages');

        return response()->json([
            'message' => 'Reguła została dodana.',
            'rule' => $rule,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $rule = MailSyncRule::findOrFail($id);

        if ($rule->is_system) {
            // System rules only permit toggling is_active
            $validated = $request->validate([
                'is_active' => 'required|boolean',
            ]);

            $rule->update([
                'is_active' => $validated['is_active'],
            ]);

            return response()->json([
                'message' => 'Status reguły systemowej został zaktualizowany.',
                'rule' => $rule->load('mailAccount:id,name,email,provider')->loadCount('matchedMessages'),
            ]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'mail_account_id' => 'nullable|uuid|exists:mail_accounts,id',
            'portal_name' => 'nullable|string|max:100',
            'sender_email' => 'nullable|email|max:255',
            'sender_domain' => 'nullable|string|max:255',
            'subject_keywords' => 'nullable|array',
            'subject_keywords.*' => 'string|max:100',
            'category' => 'required|in:job_offer,recruitment,other',
            'action' => 'required|in:qualify,ignore',
            'priority' => 'nullable|integer|min:1|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        $hasSender = ! empty($validated['sender_email']) || ! empty($validated['sender_domain']);
        $hasSubject = ! empty($validated['subject_keywords']) && count($validated['subject_keywords']) > 0;

        if (! $hasSender && ! $hasSubject) {
            throw ValidationException::withMessages([
                'conditions' => ['Musisz podać co najmniej jeden warunek: adres e-mail nadawcy, domenę lub słowa kluczowe tematu.'],
            ]);
        }

        if (! empty($validated['sender_domain'])) {
            $validated['sender_domain'] = strtolower(ltrim(trim($validated['sender_domain']), '@.'));
        }

        $rule->update([
            'name' => trim($validated['name']),
            'mail_account_id' => $validated['mail_account_id'] ?? null,
            'portal_name' => ! empty($validated['portal_name']) ? trim($validated['portal_name']) : null,
            'sender_email' => ! empty($validated['sender_email']) ? strtolower(trim($validated['sender_email'])) : null,
            'sender_domain' => $validated['sender_domain'] ?? null,
            'subject_keywords' => $validated['subject_keywords'] ?? null,
            'category' => $validated['category'],
            'action' => $validated['action'],
            'priority' => $validated['priority'] ?? 100,
            'is_active' => $validated['is_active'] ?? $rule->is_active,
        ]);

        return response()->json([
            'message' => 'Reguła została zaktualizowana.',
            'rule' => $rule->load('mailAccount:id,name,email,provider')->loadCount('matchedMessages'),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $rule = MailSyncRule::findOrFail($id);

        if ($rule->is_system) {
            return response()->json([
                'message' => 'Reguły systemowe nie mogą zostać usunięte. Możesz je jednak wyłączyć przełącznikiem.',
            ], 422);
        }

        $rule->delete();

        return response()->json([
            'message' => 'Reguła została usunięta.',
        ]);
    }

    public function toggle(string $id): JsonResponse
    {
        $rule = MailSyncRule::findOrFail($id);
        $rule->is_active = ! $rule->is_active;
        $rule->save();

        return response()->json([
            'message' => $rule->is_active ? 'Reguła została włączona.' : 'Reguła została wyłączona.',
            'rule' => $rule->load('mailAccount:id,name,email,provider')->loadCount('matchedMessages'),
        ]);
    }
}
