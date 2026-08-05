<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a full-teaching-cycle (ciclo) scrape request.
 */
class StartTeachingCycleRequest extends FormRequest
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
            'school' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'district.required' => 'The district is required to start a teaching-cycle scrape.',
            'city.required' => 'The city is required to start a teaching-cycle scrape.',
            'year.required' => 'The year is required to start a teaching-cycle scrape.',
            'teaching_cycle.required' => 'The teaching cycle is required to start a teaching-cycle scrape.',
            'school.required' => 'The school is required to start a teaching-cycle scrape.',
        ];
    }
}
