<?php

namespace App\Http\Controllers;

use App\Http\Requests\Recruitment\CvRequest;
use App\Http\Requests\Recruitment\EventRequest;
use App\Http\Requests\Recruitment\OfferRequest;
use App\Http\Requests\Recruitment\StageRequest;
use App\Integrations\OutboxSynchronizer;
use App\Jobs\SynchronizeRecruitment;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Offer;
use App\Models\Recruitment\RecruitmentAudit;
use App\Models\Recruitment\SearchProfile;
use App\Models\Recruitment\SyncConflict;
use App\Models\Recruitment\SyncOperation;
use App\Recruitment\ConflictResolver;
use App\Recruitment\LocalRecruitmentService;
use App\Recruitment\OfferFields;
use App\Recruitment\OfferIdentity;
use App\Recruitment\OfferMergeService;
use App\Recruitment\ProfileService;
use App\Recruitment\RecruitmentStages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RecruitmentController extends Controller
{
    private function saved(array $result): JsonResponse
    {
        SynchronizeRecruitment::dispatch();

        return response()->json($result);
    }

    public function store(OfferRequest $request, LocalRecruitmentService $service): JsonResponse
    {
        return $this->saved($service->createOffer($request->validated()));
    }

    public function update(OfferRequest $request, Offer $offer, LocalRecruitmentService $service): JsonResponse
    {
        return $this->saved($service->updateOffer($offer, $request->validated()));
    }

    public function cv(CvRequest $request, Offer $offer, LocalRecruitmentService $service): JsonResponse
    {
        return $this->saved($service->markCv($offer, $request->validated()));
    }

    public function stage(StageRequest $request, Offer $offer, LocalRecruitmentService $service): JsonResponse
    {
        return $this->saved($service->changeStage($offer, $request->validated()));
    }

    public function applicationStage(StageRequest $request, Application $application, LocalRecruitmentService $service): JsonResponse
    {
        return $this->saved($service->changeStage(Offer::findOrFail($application->data['offerId']), $request->validated()));
    }

    public function event(EventRequest $request, Application $application, LocalRecruitmentService $service): JsonResponse
    {
        return $this->saved($service->addEvent($application, $request->validated()));
    }

    public function updateApplication(Request $request, Application $application, LocalRecruitmentService $service): JsonResponse
    {
        $rules = ['operationId' => ['required', 'uuid'], 'actor' => ['nullable', 'string', 'max:120']];
        foreach (['source', 'cvVersion', 'coverLetterVersion', 'reference', 'recruiterName', 'recruiterPhone', 'feedbackNotes', 'rejectionReason', 'lastReplyType'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:5000'];
        }
        $rules['recruiterEmail'] = ['sometimes', 'nullable', 'email', 'max:254'];
        $rules['interviewLink'] = ['sometimes', 'nullable', 'url:http,https', 'max:2048'];
        foreach (['confirmationAt', 'firstReplyAt', 'lastReplyAt', 'nextStep', 'taskDeadlineAt', 'rejectionAt'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'date', 'after_or_equal:2000-01-01', 'before:2101-01-01'];
        }
        $rules['salaryExpectation'] = ['sometimes', 'nullable', 'numeric', 'min:0'];

        return $this->saved($service->updateApplication($application, $request->validate($rules)));
    }

    public function state(OutboxSynchronizer $sync, OfferIdentity $identity): JsonResponse
    {
        return response()->json(['sync' => $sync->state(), 'conflicts' => SyncConflict::where('status', 'open')->latest()->get(), 'operations' => SyncOperation::latest('id')->limit(100)->get(), 'duplicates' => $identity->duplicates(), 'offerFields' => OfferFields::publicFields(), 'stages' => collect(RecruitmentStages::LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all()]);
    }

    public function synchronize(Request $request): JsonResponse
    {
        $values = $request->validate(['read' => ['sometimes', 'boolean']]);
        SynchronizeRecruitment::dispatch($values['read'] ?? false);

        return response()->json(['queued' => true], 202);
    }

    public function retry(SyncOperation $operation): JsonResponse
    {
        abort_if(in_array($operation->status, ['synced', 'conflict', 'superseded', 'processing'], true), 422, 'Operacja nie może być teraz ponowiona.');
        $operation->update(['status' => 'pending', 'next_retry_at' => null, 'last_error' => null, 'error_code' => null]);

        return $this->saved(['queued' => true]);
    }

    public function resolve(Request $request, SyncConflict $conflict, ConflictResolver $resolver): JsonResponse
    {
        $values = $request->validate(['operationId' => ['required', 'uuid'], 'choice' => ['nullable', Rule::in(['local', 'remote'])], 'fields' => ['nullable', 'array'], 'fields.*' => [Rule::in(['local', 'remote'])], 'actor' => ['nullable', 'string', 'max:120']]);

        return $this->saved($resolver->resolve($conflict, $values));
    }

    public function merge(Request $request, OfferMergeService $merger): JsonResponse
    {
        $values = $request->validate(['operationId' => ['required', 'uuid'], 'localId' => ['required', 'uuid', 'exists:offers,id'], 'remoteId' => ['required', 'uuid', 'exists:offers,id']]);

        return $this->saved($merger->merge(Offer::findOrFail($values['localId']), Offer::findOrFail($values['remoteId']), $values));
    }

    public function profiles(): Response
    {
        return Inertia::render('Profiles');
    }

    public function saveProfile(Request $request, ProfileService $service, ?SearchProfile $profile = null): JsonResponse
    {
        $rules = ['operationId' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:200'], 'enabled' => ['sometimes', 'boolean'], 'actor' => ['nullable', 'string', 'max:120']];
        foreach (['industry', 'jobCategory', 'seniority', 'location', 'workMode', 'employment'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:1000'];
        }
        foreach (['keywords', 'excludedKeywords', 'skills', 'preferredSkills'] as $field) {
            $rules[$field] = ['nullable', 'array', 'max:100'];
            $rules[$field.'.*'] = ['string', 'max:200'];
        }
        $rules['salaryMin'] = ['nullable', 'numeric', 'min:0'];
        $rules['minAiScore'] = ['nullable', 'numeric', 'min:0', 'max:100'];

        return $this->saved($service->save($request->validate($rules), $profile));
    }

    public function selectProfile(Request $request, ProfileService $service): JsonResponse
    {
        $values = $request->validate(['profileId' => ['nullable', 'uuid', 'exists:search_profiles,id']]);
        $service->select($values['profileId'] ?? null);

        return response()->json(['saved' => true]);
    }

    public function audits(): JsonResponse
    {
        return response()->json(RecruitmentAudit::latest()->limit(1000)->get());
    }
}
