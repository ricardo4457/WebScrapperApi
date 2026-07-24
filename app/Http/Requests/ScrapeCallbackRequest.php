<?php

namespace App\Http\Requests;

use App\Models\ScrapeRun;
use Illuminate\Foundation\Http\FormRequest;

class ScrapeCallbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Only allow payloads with a token that belongs to a known run.
     * NOTE: whether the run is still pending/running is a business-logic
     * concern, not an authorization one — it's checked in the controller
     * so a late/duplicate callback gets a normal JSON response instead of
     * a 403 AccessDeniedHttpException.
     */
    public function authorize(): bool
    {
        $runToken = $this->input('run_token');

        if (!$runToken) {
            return false;
        }

        return ScrapeRun::where('token', $runToken)->exists();
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
