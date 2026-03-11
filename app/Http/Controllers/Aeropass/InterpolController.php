<?php

namespace App\Http\Controllers\Aeropass;

use App\Http\Controllers\Controller;
use App\Http\Requests\Aeropass\InterpolCallbackRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class InterpolController extends Controller
{
    /**
     * Handle Interpol nominal check callback from Aeropass.
     * 
     * @param InterpolCallbackRequest $request
     * @return JsonResponse
     */
    public function callback(InterpolCallbackRequest $request): JsonResponse
    {
        try {
            $uniqueReferenceId = $request->input('uniqueReferenceId');
            $interpolNominalMatched = $request->input('interpolNominalMatched');

            // Log the Interpol result (do NOT log PII beyond uniqueReferenceId)
            Log::info('Interpol callback received', [
                'unique_reference_id' => $uniqueReferenceId,
                'interpol_nominal_matched' => $interpolNominalMatched,
            ]);

            // Persist the Interpol result to database
            \App\Models\InterpolCheck::create([
                'unique_reference_id' => $uniqueReferenceId,
                'first_name' => $request->input('firstName'),
                'surname' => $request->input('surname'),
                'date_of_birth' => \App\Helpers\DateFormatHelper::ddMMyyyyToISO($request->input('dateOfBirth')),
                'interpol_nominal_matched' => $interpolNominalMatched,
                'checked_at' => now(),
                'raw_payload' => $request->all(),
            ]);

            return response()->json([
                'uniqueReferenceId' => $uniqueReferenceId,
                'responseCode' => '200',
                'errorMessage' => null,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Interpol callback processing failed', [
                'unique_reference_id' => $request->input('uniqueReferenceId'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'uniqueReferenceId' => $request->input('uniqueReferenceId'),
                'responseCode' => '500',
                'errorMessage' => 'Internal server error',
            ], 500);
        }
    }
}
