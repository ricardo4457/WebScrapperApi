<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Same shape as StartScrapeRequest's single_school strategy (minus
     * `strategy` itself), since a miss here dispatches exactly that scrape.
     */
    public function rules(): array
    {
        return [
            'district'       => ['required', 'string'],
            'city'           => ['required', 'string'],
            'school'         => ['required', 'string'],
            'year'           => ['required', 'string'],
            'teaching_cycle' => ['required', 'string'],
        ];
    }
}
