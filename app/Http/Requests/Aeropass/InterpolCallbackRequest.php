<?php

namespace App\Http\Requests\Aeropass;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class InterpolCallbackRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'uniqueReferenceId' => 'required|string',
            'firstName' => 'required|string',
            'surname' => 'required|string',
            'dateOfBirth' => ['required', 'string', 'regex:/^\d{2}\/\d{2}\/\d{4}$/'],
            'interpolNominalMatched' => 'required|string|in:Yes,No',
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'uniqueReferenceId.required' => 'uniqueReferenceId is required',
            'firstName.required' => 'firstName is required',
            'surname.required' => 'surname is required',
            'dateOfBirth.required' => 'dateOfBirth is required',
            'dateOfBirth.regex' => 'dateOfBirth must be in dd/MM/yyyy format',
            'interpolNominalMatched.required' => 'interpolNominalMatched is required',
            'interpolNominalMatched.in' => 'interpolNominalMatched must be Yes or No',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        $missingFields = [];
        
        foreach ($validator->errors()->keys() as $field) {
            $missingFields[] = $field;
        }

        throw new HttpResponseException(
            response()->json([
                'uniqueReferenceId' => $this->input('uniqueReferenceId'),
                'responseCode' => '400',
                'errorMessage' => 'Missing Mandatory Field(s) - ' . implode(', ', $missingFields),
            ], 400)
        );
    }
}
