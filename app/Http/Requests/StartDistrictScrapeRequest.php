<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a full-district scrape request.
 *
 * Kept separate from StartScrapeRequest because full_district now takes
 * a different, smaller shape: { year, district, teaching_cycle } only.
 * No city/school/schools — the Node FullDistrictStrategy discovers every
 * city and school in the district itself, live, on the wook.pt combos.
 */
class StartDistrictScrapeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'district' => ['required', 'string'],
            'year' => ['required', 'string'],
            'teaching_cycle' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'district.required' => 'The district is required to start a district-wide scrape.',
            'year.required' => 'The year is required to start a district-wide scrape.',
            'teaching_cycle.required' => 'The teaching cycle is required to start a district-wide scrape.',
        ];
    }
}
