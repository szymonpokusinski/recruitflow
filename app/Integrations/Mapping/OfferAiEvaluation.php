<?php

namespace App\Integrations\Mapping;

use App\Integrations\IntegrationException;

class OfferAiEvaluation
{
    public const SCORES = [
        'ai_match_score' => 'aiMatchScore',
        'ai_stack_score' => 'aiStackScore',
        'ai_seniority_score' => 'aiSeniorityScore',
        'ai_location_score' => 'aiLocationScore',
        'ai_salary_score' => 'aiSalaryScore',
    ];

    public const TEXT = [
        'ai_fit_reasons' => 'aiFitReasons',
        'ai_fit_concerns' => 'aiFitConcerns',
        'ai_recommendation' => 'aiRecommendation',
        'ai_cv_suggestions' => 'aiCvSuggestions',
    ];

    private static function score(mixed $value, string $field, bool $strict): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $number = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
        if (! is_bool($number) && is_numeric($number) && is_finite((float) $number) && (float) $number >= 0 && (float) $number <= 100) {
            return (float) $number;
        }
        if ($strict) {
            throw new IntegrationException('Pole '.$field.' wymaga oceny od 0 do 100.', 'row_invalid');
        }

        return null;
    }

    private static function optional(mixed $value): mixed
    {
        return $value === null || (is_string($value) && trim($value) === '') ? null : $value;
    }

    public static function map(array $values, string $timezone): array
    {
        $data = [];
        foreach (self::SCORES as $field => $property) {
            $data[$property] = self::score($values[$field] ?? null, $field, true);
        }
        foreach (self::TEXT as $field => $property) {
            // Preserve original text, including newlines and indentation.
            $data[$property] = self::optional($values[$field] ?? null);
        }
        $data['aiEvaluationConfidence'] = self::optional($values['ai_evaluation_confidence'] ?? null);
        $data['aiEvaluatedAt'] = SheetValueNormalizer::date($values['ai_evaluated_at'] ?? null, $timezone, 'ai_evaluated_at');

        return $data;
    }

    public static function fromCache(array $offer): array
    {
        // Older caches already contain these columns in extraData. Project them
        // without rewriting the database or requiring a network synchronization.
        $raw = $offer['extraData'] ?? [];
        foreach (self::SCORES as $field => $property) {
            $offer[$property] = self::score(array_key_exists($property, $offer) ? $offer[$property] : ($raw[$field] ?? null), $field, false);
        }
        foreach (self::TEXT + ['ai_evaluation_confidence' => 'aiEvaluationConfidence', 'ai_evaluated_at' => 'aiEvaluatedAt'] as $field => $property) {
            if (! array_key_exists($property, $offer)) {
                $offer[$property] = self::optional($raw[$field] ?? null);
            }
        }

        return $offer;
    }
}
