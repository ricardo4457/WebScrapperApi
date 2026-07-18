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
     * Strategies currently implemented in the Node worker.
     * Keep this list in sync with StrategyFactory.js.
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

     /*
             * Required for single school strategies.
             */
            'district' => [
                'nullable',
                'string',
                Rule::requiredIf(in_array($this->input('strategy'), ['single_school', 'single_school_tooltip'], true)),
            ],
            'city' => [
                'nullable',
                'string',
                Rule::requiredIf(in_array($this->input('strategy'), ['single_school', 'single_school_tooltip'], true)),
            ],

          /*
             * Required for single school strategies.
             */
            'school' => [
                'nullable',
                'string',
                Rule::requiredIf(in_array($this->input('strategy'), ['single_school', 'single_school_tooltip'], true)),
            ],


            /*
             * Optional list of schools for batch scraping.
             * Reserved for future strategies.
             */
            'schools' => ['nullable', 'array'],
            'schools.*' => ['string'],
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
