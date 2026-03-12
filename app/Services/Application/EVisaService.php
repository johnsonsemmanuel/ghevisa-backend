<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;

class EVisaService
{
    /**
     * Look up visa record by traveler details.
     * 
     * @param array $traveler Traveler details from Aeropass
     * @return array|null Visa record or null if not found
     */
    public function lookupVisa(array $traveler): ?array
    {
        Log::info('E-Visa lookup requested', [
            'unique_reference_id' => $traveler['uniqueReferenceId'],
            'travel_doc_number' => $traveler['travelDocNumber'],
            'date_of_birth' => $traveler['dateOfBirth'],
        ]);

        // Query for issued visa matching passport number and date of birth
        // Note: Encrypted fields need to be decrypted for comparison
        $applications = \App\Models\Application::where('status', 'issued')
            ->whereNotNull('passport_number_encrypted')
            ->whereNotNull('date_of_birth_encrypted')
            ->with(['visaType', 'documents'])
            ->get();

        $visa = null;
        foreach ($applications as $app) {
            try {
                $decryptedPassport = Crypt::decryptString($app->passport_number_encrypted);
                $decryptedDob = Crypt::decryptString($app->date_of_birth_encrypted);

                if (strtoupper($decryptedPassport) === strtoupper($traveler['travelDocNumber']) 
                    && $decryptedDob === $traveler['dateOfBirth']) {
                    $visa = $app;
                    break;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to decrypt application data', [
                    'application_id' => $app->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if (!$visa) {
            Log::info('E-Visa not found', [
                'unique_reference_id' => $traveler['uniqueReferenceId'],
            ]);
            return null;
        }

        Log::info('E-Visa found', [
            'unique_reference_id' => $traveler['uniqueReferenceId'],
            'application_id' => $visa->id,
            'reference_number' => $visa->reference_number,
        ]);

        // Build response with decrypted data
        try {
            $firstName = Crypt::decryptString($visa->first_name_encrypted);
            $surname = Crypt::decryptString($visa->last_name_encrypted);
            $nationality = Crypt::decryptString($visa->nationality_encrypted);
            $email = Crypt::decryptString($visa->email_encrypted);
            $phone = $visa->phone_encrypted ? Crypt::decryptString($visa->phone_encrypted) : '';

            // Calculate departure date
            $plannedDepartureDate = null;
            if ($visa->intended_arrival && $visa->duration_days) {
                $plannedDepartureDate = \Carbon\Carbon::parse($visa->intended_arrival)
                    ->addDays($visa->duration_days)
                    ->format('Y-m-d');
            }

            return [
                'uniqueReferenceId' => $traveler['uniqueReferenceId'],
                'firstName' => $firstName,
                'surname' => $surname,
                'dateOfBirth' => $traveler['dateOfBirth'],
                'nationality' => $nationality,
                'travelDocNumber' => $traveler['travelDocNumber'],
                'visaType' => $visa->visaType->name ?? 'Unknown',
                'emailAddress' => $email,
                'contactNumber' => $phone,
                'plannedArrivalDate' => $visa->intended_arrival ? \Carbon\Carbon::parse($visa->intended_arrival)->format('Y-m-d') : '',
                'plannedDepartureDate' => $plannedDepartureDate ?? '',
                'purposeOfVisit' => $visa->purpose_of_visit ?? '',
                'passportPhoto' => $this->getBase64Document($visa, 'passport_photo'),
                'passportCopy' => $this->getBase64Document($visa, 'passport_copy'),
                'supportingDocs' => $this->getBase64SupportingDocs($visa),
                'errorMessage' => '',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to build E-Visa response', [
                'unique_reference_id' => $traveler['uniqueReferenceId'],
                'application_id' => $visa->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get Base64-encoded document from application.
     * 
     * @param mixed $application Application model instance
     * @param string $documentType Document type identifier
     * @return string Base64-encoded document or empty string
     */
    protected function getBase64Document($application, string $documentType): string
    {
        try {
            $document = $application->documents()
                ->where('document_type', $documentType)
                ->first();

            if (!$document || !$document->stored_path) {
                Log::debug('Document not found', [
                    'application_id' => $application->id,
                    'document_type' => $documentType,
                ]);
                return '';
            }

            // Check if file exists
            if (!Storage::disk('private')->exists($document->stored_path)) {
                Log::warning('Document file not found on disk', [
                    'application_id' => $application->id,
                    'document_type' => $documentType,
                    'path' => $document->stored_path,
                ]);
                return '';
            }

            $fileContent = Storage::disk('private')->get($document->stored_path);
            return base64_encode($fileContent);
        } catch (\Exception $e) {
            Log::error('Failed to encode document', [
                'application_id' => $application->id,
                'document_type' => $documentType,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Get Base64-encoded supporting documents from application.
     * 
     * @param mixed $application Application model instance
     * @return array Array of Base64-encoded documents
     */
    protected function getBase64SupportingDocs($application): array
    {
        try {
            $documents = $application->documents()
                ->whereIn('document_type', [
                    'supporting_document',
                    'additional_document',
                    'invitation_letter',
                    'hotel_booking',
                    'flight_itinerary',
                    'bank_statement',
                    'employment_letter',
                ])
                ->get();

            $encodedDocs = [];
            foreach ($documents as $doc) {
                if (!$doc->stored_path) {
                    continue;
                }

                if (!Storage::disk('private')->exists($doc->stored_path)) {
                    Log::warning('Supporting document file not found', [
                        'application_id' => $application->id,
                        'document_id' => $doc->id,
                        'path' => $doc->stored_path,
                    ]);
                    continue;
                }

                $fileContent = Storage::disk('private')->get($doc->stored_path);
                $encodedDocs[] = base64_encode($fileContent);
            }

            return $encodedDocs;
        } catch (\Exception $e) {
            Log::error('Failed to encode supporting documents', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
