<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CountryVisaEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EligibilityController extends Controller
{
    /**
     * Get eligibility information for a specific country
     */
    public function getCountryEligibility(Request $request, string $countryCode): JsonResponse
    {
        $eligibility = CountryVisaEligibility::getByCountryCode($countryCode);

        if (!$eligibility) {
            // Default to eVisa for unknown countries
            return response()->json([
                'country_code' => strtoupper($countryCode),
                'authorization_type' => 'evisa',
                'requires_evisa' => true,
                'eta_eligible' => false,
                'fee' => 60.00,
                'max_stay_days' => 90,
                'message' => 'This country requires a full eVisa application.',
            ]);
        }

        return response()->json([
            'country_code' => $eligibility->country_code,
            'country_name' => $eligibility->country_name,
            'region' => $eligibility->region,
            'bloc' => $eligibility->bloc,
            'authorization_type' => $eligibility->authorization_type,
            'current_policy' => $eligibility->current_policy,
            'requires_evisa' => $eligibility->authorization_type === 'evisa',
            'eta_eligible' => $eligibility->authorization_type === 'eta',
            'fee' => $eligibility->authorization_type === 'eta' 
                ? (float) $eligibility->eta_fee 
                : (float) $eligibility->evisa_fee,
            'max_stay_days' => $eligibility->max_stay_days,
            'yellow_fever_required' => $eligibility->yellow_fever_required,
            'special_conditions' => $eligibility->special_conditions,
        ]);
    }

    /**
     * Get all ETA eligible countries
     */
    public function getEtaEligibleCountries(): JsonResponse
    {
        $countries = CountryVisaEligibility::getEtaEligibleCountries();

        return response()->json([
            'countries' => $countries->map(function ($c) {
                return [
                    'code' => $c->country_code,
                    'name' => $c->country_name,
                    'region' => $c->region,
                    'bloc' => $c->bloc,
                    'fee' => (float) $c->eta_fee,
                    'max_stay_days' => $c->max_stay_days,
                ];
            }),
            'total' => $countries->count(),
        ]);
    }

    /**
     * Get all eVisa required countries
     */
    public function getEvisaRequiredCountries(): JsonResponse
    {
        $countries = CountryVisaEligibility::getEvisaRequiredCountries();

        return response()->json([
            'countries' => $countries->map(function ($c) {
                return [
                    'code' => $c->country_code,
                    'name' => $c->country_name,
                    'region' => $c->region,
                    'fee' => (float) $c->evisa_fee,
                    'max_stay_days' => $c->max_stay_days,
                ];
            }),
            'total' => $countries->count(),
        ]);
    }

    /**
     * Get countries grouped by bloc
     */
    public function getCountriesByBloc(): JsonResponse
    {
        $ecowas = CountryVisaEligibility::getEcowasCountries();
        $au = CountryVisaEligibility::getAuCountries();
        $caribbean = CountryVisaEligibility::getCaribbeanCountries();
        $evisa = CountryVisaEligibility::getEvisaRequiredCountries();

        return response()->json([
            'ecowas' => [
                'name' => 'ECOWAS',
                'description' => 'Economic Community of West African States - Visa-Free with ETA',
                'countries' => $ecowas->pluck('country_name', 'country_code'),
                'eta_fee' => 10,
            ],
            'au' => [
                'name' => 'African Union',
                'description' => 'African Union Countries - Visa-on-Arrival replaced with ETA',
                'countries' => $au->pluck('country_name', 'country_code'),
                'eta_fee' => 20,
            ],
            'caribbean' => [
                'name' => 'Caribbean / CARICOM',
                'description' => 'Caribbean Community - Visa Waiver with ETA',
                'countries' => $caribbean->pluck('country_name', 'country_code'),
                'eta_fee' => 15,
            ],
            'evisa_required' => [
                'name' => 'eVisa Required',
                'description' => 'Countries requiring full eVisa application',
                'countries' => $evisa->pluck('country_name', 'country_code'),
                'evisa_fee' => 60,
            ],
        ]);
    }

    /**
     * Get all eligibility data for frontend
     */
    public function getAllEligibility(): JsonResponse
    {
        $all = CountryVisaEligibility::where('is_active', true)->get();

        return response()->json([
            'eligibility' => $all->map(function ($c) {
                return [
                    'code' => $c->country_code,
                    'name' => $c->country_name,
                    'region' => $c->region,
                    'bloc' => $c->bloc,
                    'authorization_type' => $c->authorization_type,
                    'current_policy' => $c->current_policy,
                    'eta_fee' => (float) $c->eta_fee,
                    'evisa_fee' => (float) $c->evisa_fee,
                    'max_stay_days' => $c->max_stay_days,
                    'yellow_fever_required' => $c->yellow_fever_required,
                ];
            })->keyBy('code'),
        ]);
    }
}
