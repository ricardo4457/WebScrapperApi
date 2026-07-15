<?php

namespace App\Http\Requests;

use App\Models\ScrapeRun;
use Illuminate\Foundation\Http\FormRequest;

class ScrapeCallbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Only allow payloads with a valid, active transaction token.
     */
    public function authorize(): bool
    {
        $runToken = $this->input('run_token');

        if (!$runToken) {
            return false;
        }

        // Validate that this token exists and belongs to a run that isn't already finished
        return ScrapeRun::where('token', $runToken)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'run_token' => ['required', 'string'],
            'job_token' => ['required', 'string'],
            'status'    => ['required', 'string', 'in:completed,failed'],
            'books'     => ['nullable', 'array'],
            'error'     => ['nullable', 'string'],
        ];
    }
}
