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

    public function rules(): array
    {
        return [
            'strategy' => [
                'required',
                'string',
            ],
            'year' => ['required', 'string'],
            'teaching_cycle' => ['nullable', 'string'],

            /*
             * District & Location rules
             */
            'district' => [
                'nullable',
                'string',
            ],
            'city' => ['nullable', 'string'],

            /*
             * School rules (renamed 'name' to 'school' to match your API contract)
             */
            'school' => [
                'nullable',
                'string',
            ],

            /*
             * Optional array of schools for batch processing
             */
            'schools' => ['nullable', 'array'],
            'schools.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'school.required_if' => 'The school name is required when strategy is single_school.',
            'district.required_if' => 'The district is required when strategy is full_district.',
            'strategy.string' => 'Invalid scraping strategy.',
        ];
    }
}
