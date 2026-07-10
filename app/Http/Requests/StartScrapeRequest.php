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
                Rule::in(['single_school','full_district','full_curriculum']),
            ],
            'year' => ['required','string'],
            'teaching_cycle' => ['nullable','string'
            ],


            /*
             * Dados usados na tabela schools
             */
            'district' => ['nullable','string','required_if:strategy,full_district'
            ],

            'city' => ['nullable','string'
            ],

            'name' => ['nullable','string','required_if:strategy,single_school'],
        ];
    }


    public function messages(): array
    {
        return [
            'name.required_if' =>
                'The school name is required when strategy is single_school.',

            'district.required_if' =>
                'The district is required when strategy is full_district.',

            'strategy.in' =>
                'Invalid scraping strategy.',
        ];
    }
}
