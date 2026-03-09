<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email - Ghana eVisa</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #1e40af 0%, #3730a3 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 16px;
        }
        .content {
            padding: 40px 30px;
        }
        .logo {
            width: 60px;
            height: 60px;
            background: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            font-weight: bold;
            font-size: 12px;
            color: #1e40af;
        }
        .greeting {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #333;
        }
        .message {
            line-height: 1.6;
            color: #666;
            margin-bottom: 30px;
        }
        .verify-button {
            display: inline-block;
            background: linear-gradient(135deg, #1e40af 0%, #3730a3 100%);
            color: white;
            text-decoration: none;
            padding: 16px 32px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            text-align: center;
            margin: 20px auto;
            display: block;
            max-width: 300px;
        }
        .verify-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(30, 64, 175, 0.3);
        }
        .footer {
            background: #f8f9fa;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        .footer p {
            margin: 0;
            font-size: 14px;
            color: #666;
        }
        .footer a {
            color: #1e40af;
            text-decoration: none;
        }
        .footer a:hover {
            text-decoration: underline;
        }
        .security-note {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            font-size: 14px;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>Verify Your Email Address</h1>
            <p>Ghana Electronic Visa Portal</p>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="logo">
                GH-eVISA
            </div>

            <h2 class="greeting">Hello {{ $user->first_name }},</h2>

            <div class="message">
                <p>Thank you for registering with the Ghana Electronic Visa Portal. To complete your registration and secure your account, please verify your email address by clicking the button below.</p>
                
                <p>This verification step ensures that you have access to this email address and helps us keep your account secure.</p>
            </div>

            <a href="{{ $verificationUrl }}" class="verify-button">
                Verify Email Address
            </a>

            <div class="security-note">
                <strong>Security Notice:</strong> This verification link will expire in 24 hours. If you didn't create an account with us, please ignore this email.
            </div>

            <div class="message">
                <p>If the button above doesn't work, you can copy and paste this link into your browser:</p>
                <p style="word-break: break-all; background: #f8f9fa; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px;">
                    {{ $verificationUrl }}
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>This email was sent to {{ $user->email }} because you registered for a Ghana eVisa account.</p>
            <p>© {{ date('Y') }} Ghana Immigration Service. All rights reserved.</p>
            <p>Need help? <a href="mailto:support@ghevisa.gov.gh">Contact Support</a></p>
        </div>
    </div>
</body>
</html>
