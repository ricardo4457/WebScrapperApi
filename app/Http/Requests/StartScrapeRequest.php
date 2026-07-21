<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartScrapeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Strategies handled by this endpoint. full_district has its own
     * endpoint + FormRequest (StartDistrictScrapeRequest) now, since its
     * shape no longer matches this one — no city/school needed, the Node
     * strategy discovers those itself. Keep this list in sync with
     * StrategyFactory.js (minus full_district).
     */
    private const IMPLEMENTED_STRATEGIES = ['single_school', 'single_school_tooltip'];

    public function rules(): array
    {
        return [
            'strategy' => [
                'required',
                'string',
                Rule::in(self::IMPLEMENTED_STRATEGIES),
            ],
            'year' => ['required', 'string'],
            'teaching_cycle' => ['nullable', 'string'],
            'district' => ['required', 'string'],
            'city' => ['required', 'string'],
            'school' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'strategy.in' => 'Invalid or not-yet-implemented scraping strategy.',
            'school.required' => 'The school name is required for this strategy.',
            'district.required' => 'The district is required for this strategy.',
            'city.required' => 'The city is required for this strategy.',
        ];
    }
}
