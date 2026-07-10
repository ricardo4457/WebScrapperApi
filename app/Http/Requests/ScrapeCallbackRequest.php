<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ScrapeCallbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'run_token' => 'required|string|exists:scrape_runs,token',
            'job_token' => 'required|string',
            'status'    => 'required|in:completed,failed',
            'error'     => 'nullable|string',

            'books' => 'required_if:status,completed|array',

            // School
            'books.*.school.name'     => 'required_with:books|string|max:255',
            'books.*.school.district' => 'nullable|string|max:255',
            'books.*.school.city'     => 'nullable|string|max:255',

            // Books
            'books.*.items' => 'required_with:books|array',

            'books.*.items.*.title'       => 'required|string|max:500',
            'books.*.items.*.publisher'   => 'nullable|string|max:255',
            'books.*.items.*.cover_path'  => 'nullable|string|max:500',
            'books.*.items.*.price'       => 'nullable|numeric|min:0',
            'books.*.items.*.discipline'  => 'nullable|string|max:255',
            'books.*.items.*.type'        => 'nullable|string|max:255',

            // Pivot (SchoolBook)
            'books.*.items.*.year'            => 'required|string|max:20',
            'books.*.items.*.teaching_cycle'  => 'required|string|max:50',
        ];
    }
}
