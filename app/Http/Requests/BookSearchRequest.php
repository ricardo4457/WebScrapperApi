<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

class BookSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Two search modes, mutually exclusive by which field is filled:
     *
     * - `school` filled: exact school lookup. Only `school` + `year` are
     *   required — `district`/`city` are optional overrides; when
     *   omitted, BookSearchService resolves them from the school's own
     *   record so a live scrape can still be dispatched on a miss
     *   without asking the person for the school's location.
     * - `q` filled (no `school`): direct book title search across every
     *   scraped book. DB-only, never triggers a scrape.
     *
     * Search-by-city on its own (no school) was dropped — `city` still
     * exists only as an optional override alongside `school`.
     *
     * `discipline` is an optional post-filter available in both modes.
     * It never influences scraping (scrape strategies only care about
     * district/city/school/year/teaching_cycle/course) — it only narrows
     * down results that are already in the database.
     */
    public function rules(): array
    {
        return [
            'q'              => ['nullable', 'string', 'min:2'],
            'district'       => ['nullable', 'string'],
            'city'           => ['nullable', 'string'],
            'school'         => ['nullable', 'string'],
            'year'           => ['nullable', 'string'],
            'teaching_cycle' => ['nullable', 'string'],
            'course'         => ['nullable', 'string'],
            'discipline'     => ['nullable', 'string'],
            'page'           => ['nullable', 'integer', 'min:1'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            if (!$this->filled('q') && !$this->filled('school')) {
                $validator->errors()->add('q', 'Indica um termo de pesquisa (q) ou uma escola (school).');
            }

            if ($this->filled('school') && !$this->filled('year')) {
                $validator->errors()->add('year', 'Pesquisa por escola exige também year.');
            }
        });
    }
}
