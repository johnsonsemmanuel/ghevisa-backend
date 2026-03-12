<?php

namespace App\Services;

use App\Models\Application;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class EVisaPdfService
{
    /**
     * Generate an official eVisa PDF for an approved application.
     */
    public function generate(Application $application): string
    {
        if ($application->status !== 'approved') {
            throw new \RuntimeException('Cannot generate eVisa for non-approved application.');
        }

        // Generate QR code content with checksum for verification
        $qrCodeText = $this->generateQrCode($application);
        
        // Generate QR code as SVG (no image libraries required) - larger size for better scanning
        $qrCodeSvg = QrCode::format('svg')->size(160)->encoding('UTF-8')->errorCorrection('H')->generate($qrCodeText);
        $qrCodeBase64 = 'data:image/svg+xml;base64,' . base64_encode($qrCodeSvg);

        // Load reviewing officer if not already loaded
        if (!$application->relationLoaded('reviewingOfficer')) {
            $application->load('reviewingOfficer:id,first_name,last_name');
        }

        $reviewerName = $application->reviewingOfficer 
            ? $application->reviewingOfficer->first_name . ' ' . $application->reviewingOfficer->last_name
            : 'N/A';

        $data = [
            'reference'      => $application->reference_number,
            'full_name'      => $application->first_name . ' ' . $application->last_name,
            'passport_number'=> $application->passport_number,
            'nationality'    => $application->nationality,
            'visa_type'      => $application->visaType->name,
            'arrival_date'   => $application->intended_arrival ? $application->intended_arrival->format('d M Y') : 'N/A',
            'duration'       => $application->duration_days,
            'issued_at'      => now()->format('d M Y'),
            'valid_until'    => $application->intended_arrival ? $application->intended_arrival->copy()->addDays($application->duration_days)->format('d M Y') : 'N/A',
            'qr_code'        => $qrCodeText,
            'qr_image'       => $qrCodeBase64,
            'qr_data'        => $qrCodeText, // For backward compatibility
            'reviewed_by'    => $reviewerName,
            'reviewed_at'    => $application->reviewed_at ? $application->reviewed_at->format('d M Y') : null,
        ];

        $pdf = Pdf::loadView('pdf.evisa', $data)
            ->setPaper('a4', 'portrait');

        $filename = "evisa_{$application->reference_number}.pdf";
        $path = "evisas/{$filename}";

        Storage::disk('secure')->put($path, $pdf->output());

        // Store QR code and file path
        $application->evisa_file_path = $path;
        $application->evisa_qr_code = $qrCodeText;
        $application->save();

        return $path;
    }

    /**
     * Generate QR code content for eVisa verification.
     * Format: GHEVISA:GH-2026-000001:CHECKSUM
     */
    protected function generateQrCode(Application $application): string
    {
        $data = $application->reference_number . 
                $application->passport_number . 
                $application->decided_at?->timestamp;
        
        $checksum = strtoupper(hash_hmac('sha256', $data, config('app.key')));
        
        return "GHEVISA:{$application->reference_number}:{$checksum}";
    }

    /**
     * Get the eVisa PDF content for download.
     */
    public function download(Application $application): ?string
    {
        if (!$application->evisa_file_path) {
            return null;
        }

        if (Storage::disk('secure')->exists($application->evisa_file_path)) {
            return Storage::disk('secure')->get($application->evisa_file_path);
        }

        return null;
    }
}
