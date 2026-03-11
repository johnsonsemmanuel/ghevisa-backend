<?php

namespace App\Http\Controllers\Aeropass;

use App\Http\Controllers\Controller;
use App\Http\Requests\Aeropass\EVisaCheckRequest;
use App\Services\EVisaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class EVisaController extends Controller
{
    public function __construct(
        protected EVisaService $eVisaService
    ) {}

    /**
     * Check E-Visa record for traveler.
     * 
     * @param EVisaCheckRequest $request
     * @return JsonResponse
     */
    public function visaCheck(EVisaCheckRequest $request): JsonResponse
    {
        try {
            $traveler = [
                'uniqueReferenceId' => $request->input('uniqueReferenceId'),
                'firstName' => $request->input('firstName'),
                'surname' => $request->input('surname'),
                'dateOfBirth' => $request->input('dateOfBirth'),
                'nationality' => $request->input('nationality'),
                'travelDocNumber' => $request->input('travelDocNumber'),
            ];

            Log::info('E-Visa check requested', [
                'unique_reference_id' => $traveler['uniqueReferenceId'],
            ]);

            $visaRecord = $this->eVisaService->lookupVisa($traveler);

            if (!$visaRecord) {
                return response()->json([
                    'uniqueReferenceId' => $traveler['uniqueReferenceId'],
                    'errorMessage' => 'Traveler not found in E-Visa system',
                ], 200);
            }

            return response()->json($visaRecord, 200);
        } catch (\Exception $e) {
            Log::error('E-Visa check processing failed', [
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
