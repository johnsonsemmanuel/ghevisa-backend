<?php

namespace App\Services\Verification;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * SECURITY FIX: MRZ (Machine Readable Zone) Validation Service
 * 
 * Validates passport MRZ data to detect fake/tampered passports.
 * Implements ICAO Document 9303 standards.
 */
class MrzValidationService
{
    /**
     * Validate TD3 (Passport) MRZ format.
     * 
     * @param string $line1 First line of MRZ (44 characters)
     * @param string $line2 Second line of MRZ (44 characters)
     * @return array Validation result with parsed data
     */
    public function validate(string $line1, string $line2): array
    {
        // Validate format
        if (strlen($line1) !== 44 || strlen($line2) !== 44) {
            return [
                'valid' => false,
                'error' => 'Invalid MRZ format: Lines must be exactly 44 characters',
            ];
        }
        
        try {
            // Parse Line 1: P<ISSUING_COUNTRY<SURNAME<<FIRST_NAME
            $documentType = substr($line1, 0, 1);
            $issuingCountry = substr($line1, 2, 3);
            $names = substr($line1, 5, 39);
            
            // Parse Line 2
            $passportNumber = trim(substr($line2, 0, 9), '<');
            $passportCheck = substr($line2, 9, 1);
            $nationality = substr($line2, 10, 3);
            $dob = substr($line2, 13, 6);
            $dobCheck = substr($line2, 19, 1);
            $sex = substr($line2, 20, 1);
            $expiry = substr($line2, 21, 6);
            $expiryCheck = substr($line2, 27, 1);
            $personalNumber = trim(substr($line2, 28, 14), '<');
            $personalCheck = substr($line2, 42, 1);
            $finalCheck = substr($line2, 43, 1);
            
            // Validate check digits
            if (!$this->validateCheckDigit($passportNumber, $passportCheck)) {
                return ['valid' => false, 'error' => 'Invalid passport number check digit'];
            }
            
            if (!$this->validateCheckDigit($dob, $dobCheck)) {
                return ['valid' => false, 'error' => 'Invalid date of birth check digit'];
            }
            
            if (!$this->validateCheckDigit($expiry, $expiryCheck)) {
                return ['valid' => false, 'error' => 'Invalid expiry date check digit'];
            }
            
            // Validate final check digit (composite)
            $composite = $passportNumber . $passportCheck . $dob . $dobCheck . $expiry . $expiryCheck;
            if ($personalNumber) {
                $composite .= $personalNumber . $personalCheck;
            } else {
                $composite .= str_repeat('<', 14) . $personalCheck;
            }
            
            if (!$this->validateCheckDigit($composite, $finalCheck)) {
                return ['valid' => false, 'error' => 'Invalid final check digit'];
            }
            
            // Parse names
            $nameParts = explode('<<', $names);
            $surname = trim(str_replace('<', ' ', $nameParts[0] ?? ''));
            $givenNames = trim(str_replace('<', ' ', $nameParts[1] ?? ''));
            
            return [
                'valid' => true,
                'data' => [
                    'document_type' => $documentType,
                    'issuing_country' => $issuingCountry,
                    'surname' => $surname,
                    'given_names' => $givenNames,
                    'passport_number' => $passportNumber,
                    'nationality' => $nationality,
                    'date_of_birth' => $this->parseMrzDate($dob),
                    'sex' => $sex,
                    'expiry_date' => $this->parseMrzDate($expiry),
                    'personal_number' => $personalNumber ?: null,
                ],
            ];
            
        } catch (\Exception $e) {
            Log::error('MRZ validation exception', [
                'error' => $e->getMessage(),
                'line1' => $line1,
                'line2' => $line2,
            ]);
            
            return [
                'valid' => false,
                'error' => 'MRZ parsing failed: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Validate MRZ check digit using ICAO algorithm.
     * 
     * @param string $data Data to validate
     * @param string $checkDigit Expected check digit
     * @return bool True if valid
     */
    protected function validateCheckDigit(string $data, string $checkDigit): bool
    {
        $weights = [7, 3, 1];
        $sum = 0;
        
        for ($i = 0; $i < strlen($data); $i++) {
            $char = $data[$i];
            $value = $this->getCharValue($char);
            $sum += $value * $weights[$i % 3];
        }
        
        $calculatedCheck = $sum % 10;
        return (string)$calculatedCheck === $checkDigit;
    }
    
    /**
     * Get numeric value for MRZ character.
     * 
     * @param string $char Character to convert
     * @return int Numeric value
     */
    protected function getCharValue(string $char): int
    {
        if ($char === '<') {
            return 0;
        }
        
        if (is_numeric($char)) {
            return (int)$char;
        }
        
        // A=10, B=11, ..., Z=35
        return ord(strtoupper($char)) - ord('A') + 10;
    }
    
    /**
     * Parse MRZ date format (YYMMDD) to ISO format.
     * 
     * @param string $mrzDate Date in YYMMDD format
     * @return string Date in Y-m-d format
     */
    protected function parseMrzDate(string $mrzDate): string
    {
        $year = substr($mrzDate, 0, 2);
        $month = substr($mrzDate, 2, 2);
        $day = substr($mrzDate, 4, 2);
        
        // Determine century (assume 1900s if > current year, else 2000s)
        $currentYear = (int)date('y');
        $century = ((int)$year > $currentYear) ? '19' : '20';
        
        return "{$century}{$year}-{$month}-{$day}";
    }
    
    /**
     * Compare MRZ data with application data.
     * 
     * @param array $mrzData Parsed MRZ data
     * @param array $applicationData Application data to compare
     * @return array Comparison result with mismatches
     */
    public function compareWithApplication(array $mrzData, array $applicationData): array
    {
        $mismatches = [];
        
        // Compare passport number
        if (isset($mrzData['passport_number']) && isset($applicationData['passport_number'])) {
            if (strtoupper($mrzData['passport_number']) !== strtoupper($applicationData['passport_number'])) {
                $mismatches[] = [
                    'field' => 'passport_number',
                    'mrz_value' => $mrzData['passport_number'],
                    'application_value' => $applicationData['passport_number'],
                ];
            }
        }
        
        // Compare date of birth
        if (isset($mrzData['date_of_birth']) && isset($applicationData['date_of_birth'])) {
            $mrzDob = Carbon::parse($mrzData['date_of_birth'])->format('Y-m-d');
            $appDob = Carbon::parse($applicationData['date_of_birth'])->format('Y-m-d');
            
            if ($mrzDob !== $appDob) {
                $mismatches[] = [
                    'field' => 'date_of_birth',
                    'mrz_value' => $mrzDob,
                    'application_value' => $appDob,
                ];
            }
        }
        
        // Compare nationality
        if (isset($mrzData['nationality']) && isset($applicationData['nationality'])) {
            if (strtoupper($mrzData['nationality']) !== strtoupper($applicationData['nationality'])) {
                $mismatches[] = [
                    'field' => 'nationality',
                    'mrz_value' => $mrzData['nationality'],
                    'application_value' => $applicationData['nationality'],
                ];
            }
        }
        
        // Compare names (fuzzy match - allow minor differences)
        if (isset($mrzData['surname']) && isset($applicationData['last_name'])) {
            $mrzSurname = strtoupper(trim($mrzData['surname']));
            $appSurname = strtoupper(trim($applicationData['last_name']));
            
            similar_text($mrzSurname, $appSurname, $percent);
            
            if ($percent < 80) {
                $mismatches[] = [
                    'field' => 'surname',
                    'mrz_value' => $mrzData['surname'],
                    'application_value' => $applicationData['last_name'],
                    'similarity' => round($percent, 2),
                ];
            }
        }
        
        return [
            'matches' => empty($mismatches),
            'mismatches' => $mismatches,
        ];
    }
}
