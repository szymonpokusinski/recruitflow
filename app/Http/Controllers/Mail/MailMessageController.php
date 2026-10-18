<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\Mail\MailMessage;
use App\Services\Mail\MailBodyFetcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailMessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MailMessage::query()
            ->with([
                'mailAccount:id,name,email,provider,status',
                'matchedRule:id,name,portal_name,is_system,action,category',
            ]);

        if ($request->filled('mail_account_id')) {
            $query->where('mail_account_id', $request->query('mail_account_id'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->query('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', $search)
                    ->orWhere('sender_name', 'like', $search)
                    ->orWhere('sender_email', 'like', $search)
                    ->orWhere('sender_domain', 'like', $search);
            });
        }

        // Compute metrics based on account filter (or all accounts)
        $countsQuery = MailMessage::query();
        if ($request->filled('mail_account_id')) {
            $countsQuery->where('mail_account_id', $request->query('mail_account_id'));
        }

        $counts = [
            'total' => (clone $countsQuery)->count(),
            'new' => (clone $countsQuery)->where('status', 'new')->count(),
            'to_review' => (clone $countsQuery)->where('status', 'to_review')->count(),
            'relevant' => (clone $countsQuery)->where('status', 'relevant')->count(),
            'ignored' => (clone $countsQuery)->where('status', 'ignored')->count(),
            'job_offer' => (clone $countsQuery)->where('category', 'job_offer')->count(),
            'recruitment' => (clone $countsQuery)->where('category', 'recruitment')->count(),
        ];

        $messages = $query->orderByDesc('sent_at')->get();

        return response()->json([
            'messages' => $messages,
            'counts' => $counts,
        ]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:new,to_review,relevant,ignored',
        ]);

        $message = MailMessage::with(['mailAccount', 'matchedRule'])->findOrFail($id);
        $message->status = $validated['status'];
        $message->is_manually_classified = true;
        $message->save();

        return response()->json([
            'message' => 'Status wiadomości został zaktualizowany.',
            'mail_message' => $message,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $message = MailMessage::with(['mailAccount', 'matchedRule'])->findOrFail($id);

        return response()->json([
            'mail_message' => $message,
        ]);
    }

    public function fetchBody(string $id, MailBodyFetcherService $fetcher): JsonResponse
    {
        @set_time_limit(60);

        $message = MailMessage::with(['mailAccount', 'matchedRule'])->findOrFail($id);

        $result = $fetcher->fetchAndSaveBody($message);

        return response()->json($result);
    }
}
