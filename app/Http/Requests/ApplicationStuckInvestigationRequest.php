<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplicationStuckInvestigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search_type' => 'required|string|in:application_id,applicationId,mobile,order_id,grn',
            'search_value' => 'required|string|max:100',
            'issue_text' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'search_type.required' => 'Search type is required.',
            'search_type.in' => 'Search type must be application_id, applicationId, mobile, order_id, or grn.',
            'search_value.required' => 'Search value is required.',
        ];
    }
}