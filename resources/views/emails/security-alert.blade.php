<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .alert-box { background: #dc3545; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .details { background: #f8f9fa; padding: 15px; border-left: 4px solid #dc3545; margin-bottom: 15px; }
        .detail-row { margin-bottom: 10px; }
        .label { font-weight: bold; color: #495057; }
        .value { color: #212529; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; font-size: 12px; color: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <div class="alert-box">
            <h2 style="margin: 0;">🚨 SECURITY ALERT</h2>
            <p style="margin: 10px 0 0 0; font-size: 18px;">{{ strtoupper(str_replace('_', ' ', $event)) }}</p>
        </div>

        <div class="details">
            <h3 style="margin-top: 0;">Event Details</h3>
            
            <div class="detail-row">
                <span class="label">Event:</span>
                <span class="value">{{ $event }}</span>
            </div>

            <div class="detail-row">
                <span class="label">Timestamp:</span>
                <span class="value">{{ $auditLog->created_at->format('Y-m-d H:i:s T') }}</span>
            </div>

            <div class="detail-row">
                <span class="label">Audit Log ID:</span>
                <span class="value">{{ $auditLog->id }}</span>
            </div>
        </div>

        @if($user)
        <div class="details">
            <h3 style="margin-top: 0;">User Information</h3>
            
            <div class="detail-row">
                <span class="label">User ID:</span>
                <span class="value">{{ $user->id }}</span>
            </div>

            <div class="detail-row">
                <span class="label">Name:</span>
                <span class="value">{{ $user->first_name }} {{ $user->last_name }}</span>
            </div>

            <div class="detail-row">
                <span class="label">Email:</span>
                <span class="value">{{ $user->email }}</span>
            </div>

            <div class="detail-row">
                <span class="label">Role:</span>
                <span class="value">{{ $user->role }}</span>
            </div>

            <div class="detail-row">
                <span class="label">IP Address:</span>
                <span class="value">{{ $auditLog->ip_address }}</span>
            </div>
        </div>
        @endif

        @if(!empty($context))
        <div class="details">
            <h3 style="margin-top: 0;">Additional Context</h3>
            @foreach($context as $key => $value)
                @if(!in_array($key, ['old_values', 'new_values']))
                <div class="detail-row">
                    <span class="label">{{ ucfirst(str_replace('_', ' ', $key)) }}:</span>
                    <span class="value">{{ is_array($value) ? json_encode($value) : $value }}</span>
                </div>
                @endif
            @endforeach
        </div>
        @endif

        <div class="details">
            <h3 style="margin-top: 0;">Recommended Actions</h3>
            <ul style="margin: 10px 0;">
                <li>Review the audit log immediately</li>
                <li>Investigate user activity patterns</li>
                <li>Check for related security events</li>
                <li>Contact the user if necessary</li>
                <li>Escalate to CISO if critical</li>
            </ul>
        </div>

        <div class="footer">
            <p><strong>Ghana eVisa Security Team</strong></p>
            <p>This is an automated security alert. Do not reply to this email.</p>
            <p>For urgent security issues, contact: security@ghevisa.gov.gh</p>
        </div>
    </div>
</body>
</html>
