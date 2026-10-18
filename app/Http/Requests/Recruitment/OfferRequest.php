<?php

namespace App\Http\Requests\Recruitment;

use App\Recruitment\OfferFields;
use App\Recruitment\OfferIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class OfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['operationId' => ['required', 'uuid'], 'expectedVersion' => ['nullable', 'integer', 'min:0'], 'actor' => ['nullable', 'string', 'max:120'], 'customAttributes' => ['sometimes', 'array', 'max:50'], 'customAttributes.*.label' => ['required', 'string', 'max:100'], 'customAttributes.*.value' => ['nullable', 'string', 'max:2000'], 'importDetails' => ['sometimes', 'array:provider,providerOfferId,canonicalUrl,locations,employmentTypes,workModes,entirelyRemoteWork,salaryVariants,parsedAt,fieldSources,warnings'], 'importDetails.provider' => ['required_with:importDetails', 'in:pracuj.pl'], 'importDetails.providerOfferId' => ['required_with:importDetails', 'regex:/^\d{8,12}$/'], 'importDetails.canonicalUrl' => ['required_with:importDetails', 'url:https', 'max:2048'], 'importDetails.locations' => ['sometimes', 'array', 'max:100'], 'importDetails.locations.*' => ['array:city,region,country,address'], 'importDetails.locations.*.*' => ['nullable', 'string', 'max:500'], 'importDetails.employmentTypes' => ['sometimes', 'array', 'max:20'], 'importDetails.employmentTypes.*' => ['string', 'max:200'], 'importDetails.workModes' => ['sometimes', 'array', 'max:10'], 'importDetails.workModes.*' => ['string', 'max:100'], 'importDetails.entirelyRemoteWork' => ['nullable', 'boolean'], 'importDetails.salaryVariants' => ['sometimes', 'array', 'max:20'], 'importDetails.salaryVariants.*' => ['array:contract,min,max,currency,basis,period'], 'importDetails.salaryVariants.*.contract' => ['nullable', 'string', 'max:200'], 'importDetails.salaryVariants.*.min' => ['nullable', 'numeric', 'min:0'], 'importDetails.salaryVariants.*.max' => ['nullable', 'numeric', 'min:0'], 'importDetails.salaryVariants.*.currency' => ['nullable', 'string', 'max:20'], 'importDetails.salaryVariants.*.basis' => ['nullable', 'string', 'max:50'], 'importDetails.salaryVariants.*.period' => ['nullable', 'string', 'max:50'], 'importDetails.parsedAt' => ['sometimes', 'date'], 'importDetails.fieldSources' => ['sometimes', 'array', 'max:100'], 'importDetails.fieldSources.*' => ['string', 'max:50'], 'importDetails.warnings' => ['sometimes', 'array', 'max:20'], 'importDetails.warnings.*' => ['string', 'max:2000']];
        foreach (OfferFields::DEFINITIONS as $field => $definition) {
            $rules[$field] = ['sometimes', 'nullable', ...match ($definition[1]) {
                'number' => ['numeric', 'min:0', 'max:1000000000'], 'boolean' => ['boolean'],
                'tags' => ['array', 'max:100'], 'date','datetime' => ['date', 'after_or_equal:2000-01-01', 'before:2101-01-01'],
                'url' => ['url:http,https', 'max:2048'], default => ['string', 'max:'.($definition[1] === 'textarea' ? 50000 : 2000)]
            }];
            if ($definition[1] === 'tags') {
                $rules[$field.'.*'] = ['string', 'max:200'];
            }
        }
        $rules['officeDays'][] = 'max:7';
        $rules['myRating'][] = 'max:100';

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->route('offer') && ! $this->filled('offerUrl') && (! $this->filled('company') || ! $this->filled('title'))) {
                $validator->errors()->add('title', 'Podaj firmę i stanowisko lub sam link do ogłoszenia.');
            }
            if ($this->filled('salaryMin') && $this->filled('salaryMax') && (float) $this->salaryMin > (float) $this->salaryMax) {
                $validator->errors()->add('salaryMax', 'Maksymalne wynagrodzenie nie może być mniejsze od minimalnego.');
            }
            if ($this->filled('publishedAt') && $this->filled('applyDeadlineAt') && strtotime($this->applyDeadlineAt) < strtotime($this->publishedAt)) {
                $validator->errors()->add('applyDeadlineAt', 'Termin aplikowania nie może poprzedzać publikacji.');
            }
            if ($this->has('importDetails') && (OfferIdentity::pracujId($this->input('offerUrl')) !== $this->input('importDetails.providerOfferId') || OfferIdentity::pracujId($this->input('importDetails.canonicalUrl')) !== $this->input('importDetails.providerOfferId'))) {
                $validator->errors()->add('importDetails', 'Dane importu nie pasują do adresu oferty.');
            }
        }];
    }
}
