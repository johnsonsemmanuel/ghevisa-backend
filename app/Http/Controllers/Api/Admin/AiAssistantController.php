<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAiAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiAssistantController extends Controller
{
    public function __construct(
        protected AdminAiAssistantService $aiService
    ) {}

    /**
     * Process AI assistant query
     */
    public function query(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|max:1000',
        ]);

        $result = $this->aiService->processQuery($validated['query']);

        return response()->json($result);
    }

    /**
     * Export query results to CSV
     */
    public function exportResults(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|max:1000',
        ]);

        $result = $this->aiService->processQuery($validated['query']);
        $csv = $this->aiService->exportQueryResults($result['data'] ?? []);
        
        $filename = 'ai_query_export_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
