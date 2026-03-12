#!/bin/bash

# Security Monitoring Script for Ghana eVisa Platform
# Created: March 11, 2026
# Purpose: Monitor security features and Interpol checks

echo "╔════════════════════════════════════════════════════════════╗"
echo "║   GHANA eVISA SECURITY MONITORING DASHBOARD               ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""

# Check if we're in the backend directory
if [ ! -f "artisan" ]; then
    echo "❌ Error: Must be run from backend directory"
    exit 1
fi

echo "📊 SECURITY STATUS REPORT"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Check configuration
echo "1️⃣  Configuration Status:"
php artisan tinker --execute="
echo '   Interpol Auto-Trigger: ' . (config('security.interpol.auto_trigger') ? '✅ ENABLED' : '❌ DISABLED') . PHP_EOL;
echo '   Duplicate Detection: ' . (config('security.duplicate_detection.enabled') ? '✅ ENABLED' : '❌ DISABLED') . PHP_EOL;
echo '   reCAPTCHA: ' . (config('security.recaptcha.enabled') ? '✅ ENABLED' : '⏸️  DISABLED') . PHP_EOL;
"

echo ""
echo "2️⃣  Interpol Check Statistics:"
php artisan tinker --execute="
\$pending = \App\Models\Application::where('interpol_check_status', 'pending')->count();
\$completed = \App\Models\Application::where('interpol_check_status', 'completed')->count();
\$failed = \App\Models\Application::where('interpol_check_status', 'failed')->count();
\$manual = \App\Models\Application::where('requires_manual_interpol_check', true)->count();

echo '   Pending Checks: ' . \$pending . PHP_EOL;
echo '   Completed Checks: ' . \$completed . PHP_EOL;
echo '   Failed Checks: ' . \$failed . PHP_EOL;
echo '   Requiring Manual Review: ' . \$manual . PHP_EOL;
"

echo ""
echo "3️⃣  Recent Interpol Activity (Last 10 lines):"
if [ -f "storage/logs/laravel.log" ]; then
    grep "Interpol" storage/logs/laravel.log | tail -10 || echo "   No Interpol activity found in logs"
else
    echo "   No log file found"
fi

echo ""
echo "4️⃣  Risk Assessment Status:"
php artisan tinker --execute="
\$total = \App\Models\RiskAssessment::count();
\$high = \App\Models\RiskAssessment::where('risk_level', 'high')->count();
\$critical = \App\Models\RiskAssessment::where('risk_level', 'critical')->count();

echo '   Total Risk Assessments: ' . \$total . PHP_EOL;
echo '   High Risk: ' . \$high . PHP_EOL;
echo '   Critical Risk: ' . \$critical . PHP_EOL;
"

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Security monitoring complete"
echo ""
echo "💡 Tips:"
echo "   - Run this script regularly to monitor security status"
echo "   - Check applications requiring manual review"
echo "   - Monitor Interpol check success rate"
echo ""
