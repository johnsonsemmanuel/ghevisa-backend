<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdminAiAssistantService
{
    protected string $openAiApiKey;
    protected string $model;

    public function __construct()
    {
        $this->openAiApiKey = config('services.openai.api_key', '');
        $this->model = config('services.openai.model', 'gpt-4o');
    }

    /**
     * Process admin query and return AI-generated response with data
     */
    public function processQuery(string $query): array
    {
        // First, analyze the query to determine what data to fetch
        $dataContext = $this->gatherDataContext($query);
        
        // If we couldn't connect to OpenAI, use rule-based responses
        if (empty($this->openAiApiKey)) {
            return $this->generateRuleBasedResponse($query, $dataContext);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->openAiApiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->getSystemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => "User query: {$query}\n\nSystem data context:\n" . json_encode($dataContext, JSON_PRETTY_PRINT),
                    ],
                ],
                'temperature' => 0.3,
                'max_tokens' => 1500,
            ]);

            if ($response->successful()) {
                $aiResponse = $response->json('choices.0.message.content');
                return [
                    'success' => true,
                    'response' => $aiResponse,
                    'data' => $dataContext,
                    'query' => $query,
                ];
            }

            Log::error('OpenAI API error', ['response' => $response->body()]);
            return $this->generateRuleBasedResponse($query, $dataContext);

        } catch (\Exception $e) {
            Log::error('AI Assistant error', ['error' => $e->getMessage()]);
            return $this->generateRuleBasedResponse($query, $dataContext);
        }
    }

    /**
     * Gather relevant data based on query keywords
     */
    protected function gatherDataContext(string $query): array
    {
        $query = strtolower($query);
        $context = [];

        // Time period detection
        $period = $this->detectTimePeriod($query);
        $startDate = $period['start'];
        $endDate = $period['end'];
        $context['period'] = $period;

        // Application statistics
        if ($this->matchesKeywords($query, ['application', 'applications', 'visa', 'visas', 'submitted', 'total', 'how many', 'count', 'statistics', 'stats'])) {
            $context['applications'] = [
                'total' => Application::whereBetween('created_at', [$startDate, $endDate])->count(),
                'by_status' => Application::whereBetween('created_at', [$startDate, $endDate])
                    ->groupBy('status')
                    ->pluck('count', 'status')
                    ->toArray(),
                'pending' => Application::whereIn('status', ['submitted', 'under_review', 'pending_approval'])->count(),
            ];
            
            // Also include country statistics for general statistics queries
            $encryptedNationalities = DB::table('applications')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('nationality_encrypted')
                ->pluck('nationality_encrypted');
            
            $countryCounts = [];
            foreach ($encryptedNationalities as $encrypted) {
                try {
                    $countryCode = Crypt::decryptString($encrypted);
                    $countryName = $this->getCountryName($countryCode);
                    $countryCounts[$countryName] = ($countryCounts[$countryName] ?? 0) + 1;
                } catch (\Exception $e) {
                    // If decryption fails, skip this record
                    continue;
                }
            }
            
            // Sort by count descending
            arsort($countryCounts);
            
            // Convert to array format expected by response formatter
            $visitors = [];
            foreach ($countryCounts as $country => $count) {
                $visitors[] = ['country' => $country, 'count' => $count];
            }
            $context['visitors_by_country'] = array_slice($visitors, 0, 20);
        }

        // Visitor/Country statistics
        if ($this->matchesKeywords($query, ['visitor', 'visitors', 'country', 'countries', 'nationality', 'nationalities', 'where', 'coming from'])) {
            $encryptedNationalities = DB::table('applications')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('nationality_encrypted')
                ->pluck('nationality_encrypted');
            
            $countryCounts = [];
            foreach ($encryptedNationalities as $encrypted) {
                try {
                    $countryCode = Crypt::decryptString($encrypted);
                    $countryName = $this->getCountryName($countryCode);
                    $countryCounts[$countryName] = ($countryCounts[$countryName] ?? 0) + 1;
                } catch (\Exception $e) {
                    // If decryption fails, skip this record
                    continue;
                }
            }
            
            // Sort by count descending
            arsort($countryCounts);
            
            // Convert to array format expected by response formatter
            $visitors = [];
            foreach ($countryCounts as $country => $count) {
                $visitors[] = ['country' => $country, 'count' => $count];
            }
            $context['visitors_by_country'] = array_slice($visitors, 0, 20);
        }

        // Approval/Denial statistics
        if ($this->matchesKeywords($query, ['approved', 'denied', 'rejected', 'issued', 'approval', 'denial', 'rate'])) {
            $issued = Application::where('status', 'issued')->whereBetween('decided_at', [$startDate, $endDate])->count();
            $denied = Application::where('status', 'denied')->whereBetween('decided_at', [$startDate, $endDate])->count();
            $approved = Application::where('status', 'approved')->whereBetween('decided_at', [$startDate, $endDate])->count();
            $total = $issued + $denied + $approved;
            
            $context['decisions'] = [
                'issued' => $issued,
                'approved' => $approved,
                'denied' => $denied,
                'total_decided' => $total,
                'approval_rate' => $total > 0 ? round((($issued + $approved) / $total) * 100, 1) : 0,
                'denial_rate' => $total > 0 ? round(($denied / $total) * 100, 1) : 0,
            ];
        }

        // Revenue/Payment statistics
        if ($this->matchesKeywords($query, ['revenue', 'payment', 'payments', 'money', 'income', 'earned', 'collected', 'financial'])) {
            $context['revenue'] = [
                'total' => Payment::where('status', 'completed')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->sum('amount'),
                'transaction_count' => Payment::where('status', 'completed')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->count(),
                'by_method' => Payment::where('status', 'completed')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->select('payment_option', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
                    ->groupBy('payment_option')
                    ->get()
                    ->toArray(),
                'currency' => 'GHS',
            ];
        }

        // User statistics
        if ($this->matchesKeywords($query, ['user', 'users', 'applicant', 'applicants', 'registered', 'account'])) {
            $context['users'] = [
                'total' => User::where('role', 'applicant')->count(),
                'registered_in_period' => User::where('role', 'applicant')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->count(),
                'by_role' => User::select('role', DB::raw('COUNT(*) as count'))
                    ->groupBy('role')
                    ->pluck('count', 'role')
                    ->toArray(),
            ];
        }

        // Processing time / SLA
        if ($this->matchesKeywords($query, ['processing', 'time', 'sla', 'average', 'wait', 'duration', 'fast', 'slow'])) {
            $context['processing'] = [
                'pending_count' => Application::whereIn('status', ['submitted', 'under_review', 'pending_approval'])->count(),
                'at_risk' => Application::whereIn('status', ['submitted', 'under_review', 'pending_approval'])
                    ->whereNotNull('sla_deadline')
                    ->where('sla_deadline', '<', now()->addHours(8))
                    ->count(),
                'breached' => Application::whereIn('status', ['submitted', 'under_review', 'pending_approval'])
                    ->whereNotNull('sla_deadline')
                    ->where('sla_deadline', '<', now())
                    ->count(),
            ];
        }

        // Always include general overview if context is sparse
        if (count($context) <= 1) {
            $context['overview'] = [
                'total_applications_all_time' => Application::count(),
                'total_applications_period' => Application::whereBetween('created_at', [$startDate, $endDate])->count(),
                'total_issued_period' => Application::where('status', 'issued')->whereBetween('decided_at', [$startDate, $endDate])->count(),
                'total_denied_period' => Application::where('status', 'denied')->whereBetween('decided_at', [$startDate, $endDate])->count(),
                'total_revenue_period' => Payment::where('status', 'completed')->whereBetween('created_at', [$startDate, $endDate])->sum('amount'),
                'pending_applications' => Application::whereIn('status', ['submitted', 'under_review', 'pending_approval'])->count(),
            ];
        }

        return $context;
    }

    /**
     * Detect time period from query
     */
    protected function detectTimePeriod(string $query): array
    {
        $now = now();
        
        if (preg_match('/last\s+(\d+)\s+days?/i', $query, $matches)) {
            return [
                'start' => $now->copy()->subDays((int) $matches[1])->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'description' => "Last {$matches[1]} days",
            ];
        }
        
        if (preg_match('/last\s+(\d+)\s+months?/i', $query, $matches)) {
            return [
                'start' => $now->copy()->subMonths((int) $matches[1])->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'description' => "Last {$matches[1]} months",
            ];
        }

        if (preg_match('/last\s+week/i', $query)) {
            return [
                'start' => $now->copy()->subWeek()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'description' => 'Last week',
            ];
        }

        if (preg_match('/last\s+month/i', $query)) {
            return [
                'start' => $now->copy()->subMonth()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'description' => 'Last month',
            ];
        }

        if (preg_match('/this\s+month/i', $query)) {
            return [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfDay(),
                'description' => 'This month',
            ];
        }

        if (preg_match('/this\s+week/i', $query)) {
            return [
                'start' => $now->copy()->startOfWeek(),
                'end' => $now->copy()->endOfDay(),
                'description' => 'This week',
            ];
        }

        if (preg_match('/today/i', $query)) {
            return [
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'description' => 'Today',
            ];
        }

        if (preg_match('/yesterday/i', $query)) {
            return [
                'start' => $now->copy()->subDay()->startOfDay(),
                'end' => $now->copy()->subDay()->endOfDay(),
                'description' => 'Yesterday',
            ];
        }

        if (preg_match('/this\s+year/i', $query)) {
            return [
                'start' => $now->copy()->startOfYear(),
                'end' => $now->copy()->endOfDay(),
                'description' => 'This year',
            ];
        }

        // Default to last 30 days
        return [
            'start' => $now->copy()->subDays(30)->startOfDay(),
            'end' => $now->copy()->endOfDay(),
            'description' => 'Last 30 days (default)',
        ];
    }

    /**
     * Check if query matches any of the keywords
     */
    protected function matchesKeywords(string $query, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($query, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get system prompt for AI
     */
    protected function getSystemPrompt(): string
    {
        return <<<PROMPT
You are an AI assistant for the Ghana e-Visa System administration dashboard. Your role is to help administrators understand system data and provide insights.

Guidelines:
1. Answer questions based on the provided system data context
2. Be concise and professional
3. Format numbers clearly (e.g., use commas for thousands)
4. When discussing revenue, always mention the currency (GHS - Ghanaian Cedi)
5. Provide actionable insights when relevant
6. If the data doesn't contain information to answer the question, say so clearly
7. Use bullet points or numbered lists for clarity when appropriate
8. Round percentages to one decimal place

You have access to real-time data from the Ghana e-Visa processing system including:
- Application statistics (submitted, approved, denied, issued)
- Revenue and payment data
- Country/nationality breakdown of applicants
- Processing times and SLA compliance
- User registration data

Always base your answers on the actual data provided in the context.
PROMPT;
    }

    /**
     * Generate rule-based response when OpenAI is unavailable
     */
    protected function generateRuleBasedResponse(string $query, array $context): array
    {
        $query = strtolower($query);
        $response = "";
        $period = $context['period']['description'] ?? 'the selected period';

        // Applications query
        if (isset($context['applications'])) {
            $apps = $context['applications'];
            $response .= "**Application Statistics ({$period}):**\n";
            $response .= "- Total applications: " . number_format($apps['total']) . "\n";
            if (!empty($apps['by_status'])) {
                $response .= "- Status breakdown:\n";
                foreach ($apps['by_status'] as $status => $count) {
                    $response .= "  - " . ucfirst(str_replace('_', ' ', $status)) . ": " . number_format($count) . "\n";
                }
            }
            $response .= "- Currently pending: " . number_format($apps['pending']) . "\n\n";
        }

        // Visitors by country
        if (isset($context['visitors_by_country']) && !empty($context['visitors_by_country'])) {
            $response .= "**Top Countries by Applications ({$period}):**\n";
            foreach (array_slice($context['visitors_by_country'], 0, 10) as $item) {
                $response .= "- {$item['country']}: " . number_format($item['count']) . " applications\n";
            }
            $response .= "\n";
        }

        // Decisions
        if (isset($context['decisions'])) {
            $dec = $context['decisions'];
            $response .= "**Decision Statistics ({$period}):**\n";
            $response .= "- Visas issued: " . number_format($dec['issued']) . "\n";
            $response .= "- Applications approved: " . number_format($dec['approved']) . "\n";
            $response .= "- Applications denied: " . number_format($dec['denied']) . "\n";
            $response .= "- Approval rate: {$dec['approval_rate']}%\n";
            $response .= "- Denial rate: {$dec['denial_rate']}%\n\n";
        }

        // Revenue
        if (isset($context['revenue'])) {
            $rev = $context['revenue'];
            $response .= "**Revenue Statistics ({$period}):**\n";
            $response .= "- Total revenue: GHS " . number_format($rev['total'], 2) . "\n";
            $response .= "- Successful transactions: " . number_format($rev['transaction_count']) . "\n";
            if (!empty($rev['by_method'])) {
                $response .= "- By payment method:\n";
                foreach ($rev['by_method'] as $method) {
                    $methodName = $method['payment_option'] ?? 'Unknown';
                    $response .= "  - " . ucfirst($methodName) . ": GHS " . number_format($method['total'], 2) . " ({$method['count']} transactions)\n";
                }
            }
            $response .= "\n";
        }

        // Users
        if (isset($context['users'])) {
            $users = $context['users'];
            $response .= "**User Statistics:**\n";
            $response .= "- Total applicants: " . number_format($users['total']) . "\n";
            $response .= "- New registrations ({$period}): " . number_format($users['registered_in_period']) . "\n\n";
        }

        // Processing/SLA
        if (isset($context['processing'])) {
            $proc = $context['processing'];
            $response .= "**Processing Status:**\n";
            $response .= "- Pending applications: " . number_format($proc['pending_count']) . "\n";
            $response .= "- At risk of SLA breach: " . number_format($proc['at_risk']) . "\n";
            $response .= "- SLA breached: " . number_format($proc['breached']) . "\n\n";
        }

        // Overview fallback
        if (isset($context['overview'])) {
            $ov = $context['overview'];
            $response .= "**System Overview ({$period}):**\n";
            $response .= "- Total applications (all time): " . number_format($ov['total_applications_all_time']) . "\n";
            $response .= "- Applications in period: " . number_format($ov['total_applications_period']) . "\n";
            $response .= "- Visas issued in period: " . number_format($ov['total_issued_period']) . "\n";
            $response .= "- Applications denied in period: " . number_format($ov['total_denied_period']) . "\n";
            $response .= "- Revenue in period: GHS " . number_format($ov['total_revenue_period'], 2) . "\n";
            $response .= "- Currently pending: " . number_format($ov['pending_applications']) . "\n";
        }

        if (empty($response)) {
            $response = "I couldn't find specific data matching your query. Please try asking about:\n";
            $response .= "- Applications (e.g., 'How many applications last month?')\n";
            $response .= "- Revenue (e.g., 'What is the total revenue this week?')\n";
            $response .= "- Countries (e.g., 'Which countries are most visitors from?')\n";
            $response .= "- Approvals/Denials (e.g., 'What is the approval rate?')\n";
            $response .= "- Processing (e.g., 'How many applications are pending?')";
        }

        return [
            'success' => true,
            'response' => $response,
            'data' => $context,
            'query' => $query,
            'ai_powered' => false,
        ];
    }

    /**
     * Convert country code to full country name
     */
    protected function getCountryName(string $countryCode): string
    {
        $countries = [
            'GH' => 'Ghana',
            'NG' => 'Nigeria',
            'US' => 'United States',
            'UK' => 'United Kingdom',
            'CA' => 'Canada',
            'DE' => 'Germany',
            'FR' => 'France',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
            'IE' => 'Ireland',
            'AU' => 'Australia',
            'NZ' => 'New Zealand',
            'ZA' => 'South Africa',
            'KE' => 'Kenya',
            'UG' => 'Uganda',
            'TZ' => 'Tanzania',
            'CI' => 'Ivory Coast',
            'SN' => 'Senegal',
            'BF' => 'Burkina Faso',
            'ML' => 'Mali',
            'NE' => 'Niger',
            'TD' => 'Chad',
            'CM' => 'Cameroon',
            'GA' => 'Gabon',
            'CG' => 'Congo',
            'CD' => 'Democratic Republic of Congo',
            'AO' => 'Angola',
            'ZM' => 'Zambia',
            'MW' => 'Malawi',
            'MZ' => 'Mozambique',
            'ZW' => 'Zimbabwe',
            'BW' => 'Botswana',
            'NA' => 'Namibia',
            'SZ' => 'Eswatini',
            'LS' => 'Lesotho',
            'MG' => 'Madagascar',
            'MU' => 'Mauritius',
            'SC' => 'Seychelles',
            'KM' => 'Comoros',
            'RE' => 'Réunion',
            'CV' => 'Cape Verde',
            'ST' => 'São Tomé and Príncipe',
            'GW' => 'Guinea-Bissau',
            'GN' => 'Guinea',
            'SL' => 'Sierra Leone',
            'LR' => 'Liberia',
            'BJ' => 'Benin',
            'TG' => 'Togo',
            'GM' => 'Gambia',
            'MR' => 'Mauritania',
            'DZ' => 'Algeria',
            'TN' => 'Tunisia',
            'LY' => 'Libya',
            'EG' => 'Egypt',
            'SD' => 'Sudan',
            'ET' => 'Ethiopia',
            'ER' => 'Eritrea',
            'DJ' => 'Djibouti',
            'SO' => 'Somalia',
            'IN' => 'India',
            'PK' => 'Pakistan',
            'BD' => 'Bangladesh',
            'LK' => 'Sri Lanka',
            'NP' => 'Nepal',
            'BT' => 'Bhutan',
            'MM' => 'Myanmar',
            'TH' => 'Thailand',
            'VN' => 'Vietnam',
            'KH' => 'Cambodia',
            'LA' => 'Laos',
            'PH' => 'Philippines',
            'MY' => 'Malaysia',
            'SG' => 'Singapore',
            'ID' => 'Indonesia',
            'BN' => 'Brunei',
            'TL' => 'Timor-Leste',
            'CN' => 'China',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'KP' => 'North Korea',
            'MN' => 'Mongolia',
            'RU' => 'Russia',
            'UA' => 'Ukraine',
            'BY' => 'Belarus',
            'PL' => 'Poland',
            'CZ' => 'Czech Republic',
            'SK' => 'Slovakia',
            'HU' => 'Hungary',
            'RO' => 'Romania',
            'BG' => 'Bulgaria',
            'RS' => 'Serbia',
            'HR' => 'Croatia',
            'BA' => 'Bosnia and Herzegovina',
            'ME' => 'Montenegro',
            'MK' => 'North Macedonia',
            'AL' => 'Albania',
            'GR' => 'Greece',
            'TR' => 'Turkey',
            'CY' => 'Cyprus',
            'IL' => 'Israel',
            'JO' => 'Jordan',
            'SY' => 'Syria',
            'LB' => 'Lebanon',
            'IQ' => 'Iraq',
            'IR' => 'Iran',
            'AF' => 'Afghanistan',
            'PK' => 'Pakistan',
            'SA' => 'Saudi Arabia',
            'YE' => 'Yemen',
            'OM' => 'Oman',
            'AE' => 'United Arab Emirates',
            'QA' => 'Qatar',
            'BH' => 'Bahrain',
            'KW' => 'Kuwait',
            'MX' => 'Mexico',
            'GT' => 'Guatemala',
            'BZ' => 'Belize',
            'SV' => 'El Salvador',
            'HN' => 'Honduras',
            'NI' => 'Nicaragua',
            'CR' => 'Costa Rica',
            'PA' => 'Panama',
            'CO' => 'Colombia',
            'VE' => 'Venezuela',
            'EC' => 'Ecuador',
            'PE' => 'Peru',
            'BO' => 'Bolivia',
            'CL' => 'Chile',
            'AR' => 'Argentina',
            'UY' => 'Uruguay',
            'PY' => 'Paraguay',
            'BR' => 'Brazil',
            'GY' => 'Guyana',
            'SR' => 'Suriname',
            'GF' => 'French Guiana',
        ];

        return $countries[strtoupper($countryCode)] ?? $countryCode;
    }

    /**
     * Export query results to CSV
     */
    public function exportQueryResults(array $data, string $type = 'general'): string
    {
        $csv = "";
        
        if (isset($data['visitors_by_country'])) {
            $csv .= "Country Analytics\n";
            $csv .= "Country Code,Application Count\n";
            foreach ($data['visitors_by_country'] as $item) {
                $csv .= "{$item['country']},{$item['count']}\n";
            }
            $csv .= "\n";
        }

        if (isset($data['applications'])) {
            $csv .= "Application Statistics\n";
            $csv .= "Metric,Value\n";
            $csv .= "Total,{$data['applications']['total']}\n";
            $csv .= "Pending,{$data['applications']['pending']}\n";
            if (isset($data['applications']['by_status'])) {
                foreach ($data['applications']['by_status'] as $status => $count) {
                    $csv .= ucfirst($status) . ",{$count}\n";
                }
            }
            $csv .= "\n";
        }

        if (isset($data['revenue'])) {
            $csv .= "Revenue Statistics\n";
            $csv .= "Metric,Value\n";
            $csv .= "Total Revenue (GHS),{$data['revenue']['total']}\n";
            $csv .= "Transaction Count,{$data['revenue']['transaction_count']}\n";
            $csv .= "\n";
        }

        return $csv;
    }
}
