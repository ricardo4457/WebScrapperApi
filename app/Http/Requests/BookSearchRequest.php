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
     * Three search modes, mutually exclusive by which field is filled:
     *
     * - `school` filled: exact school lookup. Only `school` + `year` are
     *   required — `district`/`city` are optional overrides; when
     *   omitted, BookSearchService resolves them from the school's own
     *   record (or from any existing school in `city`, for the `city`
     *   mode below) so a live scrape can still be dispatched on a miss
     *   without asking the person for the school's location.
     * - `city` filled (no `school`): every book already scraped for any
     *   school in that concelho. Only `city` + `year` are required —
     *   `district` is resolved the same way as above when omitted.
     * - `q` filled (no `school`/`city`): direct book title search across
     *   every scraped book. DB-only, never triggers a scrape.
     *
     * `discipline` is an optional post-filter available in all three modes.
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
            if (!$this->filled('q') && !$this->filled('school') && !$this->filled('city')) {
                $validator->errors()->add('q', 'Indica um termo de pesquisa (q), uma escola (school) ou um concelho (city).');
            }

            if ($this->filled('school') && !$this->filled('year')) {
                $validator->errors()->add('year', 'Pesquisa por escola exige também year.');
            }

            if ($this->filled('city') && !$this->filled('school') && !$this->filled('year')) {
                $validator->errors()->add('year', 'Pesquisa por concelho exige também year.');
            }
        });
    }
}
