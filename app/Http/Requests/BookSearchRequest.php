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
     * - `school` / `school_id`: searches within a specific school and may
     *   trigger a scrape when data is missing or outdated. `school_id` is
     *   preferred to avoid name-matching issues.
     * - `q`: searches book titles across all cached books and never scrapes.
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
            'school_id'      => ['nullable', 'integer', 'exists:schools,id'],
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
            if (!$this->filled('q') && !$this->filled('school') && !$this->filled('school_id')) {
                $validator->errors()->add('q', 'Indica um termo de pesquisa (q) ou uma escola (school/school_id).');
            }

            if (($this->filled('school') || $this->filled('school_id')) && !$this->filled('year')) {
                $validator->errors()->add('year', 'Pesquisa por escola exige também year.');
            }
        });
    }
}
