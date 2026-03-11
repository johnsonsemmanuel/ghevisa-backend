<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Locked</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 8px 8px 0 0;
            text-align: center;
        }
        .content {
            background: #ffffff;
            padding: 30px;
            border: 1px solid #e5e7eb;
            border-top: none;
        }
        .alert-box {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .info-table {
            width: 100%;
            margin: 20px 0;
            border-collapse: collapse;
        }
        .info-table td {
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        .info-table td:first-child {
            font-weight: 600;
            width: 40%;
            color: #6b7280;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #6b7280;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin: 0;">🔒 Security Alert</h1>
        <p style="margin: 10px 0 0 0; opacity: 0.9;">Account Temporarily Locked</p>
    </div>
    
    <div class="content">
        <p>Hello {{ $user->first_name }},</p>
        
        <div class="alert-box">
            <strong>⚠️ Your account has been temporarily locked</strong>
            <p style="margin: 10px 0 0 0;">
                We detected multiple failed login attempts on your account. As a security measure, 
                your account has been temporarily locked for 15 minutes.
            </p>
        </div>

        <h3>Login Attempt Details:</h3>
        <table class="info-table">
            <tr>
                <td>Time:</td>
                <td>{{ $locked_until->subMinutes(15)->format('F j, Y g:i A') }}</td>
            </tr>
            <tr>
                <td>IP Address:</td>
                <td>{{ $ip_address }}</td>
            </tr>
            <tr>
                <td>Location:</td>
                <td>{{ $location }}</td>
            </tr>
            <tr>
                <td>Device:</td>
                <td>{{ $user_agent }}</td>
            </tr>
            <tr>
                <td>Account Unlocks:</td>
                <td><strong>{{ $locked_until->format('F j, Y g:i A') }}</strong></td>
            </tr>
        </table>

        <h3>What should you do?</h3>
        
        <p><strong>If this was you:</strong></p>
        <ul>
            <li>Wait 15 minutes and try logging in again</li>
            <li>Make sure you're using the correct password</li>
            <li>Consider resetting your password if you've forgotten it</li>
        </ul>

        <p><strong>If this wasn't you:</strong></p>
        <ul>
            <li>Someone may be trying to access your account</li>
            <li>Reset your password immediately after the lockout period</li>
            <li>Enable two-factor authentication for added security</li>
            <li>Contact support if you need assistance</li>
        </ul>

        <a href="{{ config('app.frontend_url') }}/forgot-password" class="button">
            Reset Password
        </a>

        <p style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 14px;">
            <strong>Security Tip:</strong> Never share your password with anyone. Ghana Immigration Service 
            will never ask for your password via email or phone.
        </p>
    </div>

    <div class="footer">
        <p>
            This is an automated security notification from<br>
            <strong>Ghana Immigration Service - eVisa Platform</strong>
        </p>
        <p style="font-size: 12px; color: #9ca3af;">
            If you have questions, contact support at support@ghevisa.gov.gh
        </p>
    </div>
</body>
</html>
