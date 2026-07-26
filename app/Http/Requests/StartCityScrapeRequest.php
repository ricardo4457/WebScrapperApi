<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a full-city (concelho) scrape request.
 */
class StartCityScrapeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'district' => ['required', 'string'],
            'city' => ['required', 'string'],
            'year' => ['required', 'string'],
            'teaching_cycle' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'district.required' => 'The district is required to start a city-wide scrape.',
            'city.required' => 'The city is required to start a city-wide scrape.',
            'year.required' => 'The year is required to start a city-wide scrape.',
            'teaching_cycle.required' => 'The teaching cycle is required to start a city-wide scrape.',
        ];
    }
}
