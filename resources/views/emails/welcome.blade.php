<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to ExpenzAI</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            line-height: 1.6;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background-color: #171717;
            padding: 40px;
            text-align: center;
            color: white;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }
        .header p {
            margin: 10px 0 0;
            font-size: 16px;
            opacity: 0.9;
        }
        .content {
            padding: 40px 30px;
            text-align: center;
        }
        .greeting {
            font-size: 18px;
            font-weight: 900;
            margin-bottom: 20px;
            color: #171717;
        }
        .message {
            font-size: 16px;
            margin-bottom: 30px;
            color: #171717;
        }
        .cta-button {
            display: inline-block;
            background: #FFEB3B;
            color: #171717 !important;
            text-decoration: none;
            padding: 16px 32px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 16px;
            margin: 20px 0;
            box-shadow: 0 4px 6px rgba(59, 130, 246, 0.3);
            transition: transform 0.2s;
        }
        .cta-button:hover {
            transform: translateY(-2px);
        }
        .features {
            background-color: #FFEB3B;
            border-radius: 8px;
            padding: 24px;
            margin: 30px 0;
            color: #171717;
        }
        .features h3 {  
            color: #171717;
            margin: 0 0 16px;
            font-size: 18px;
        }
        .feature-list {
            color: #171717;
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .feature-list li {
            color: #171717;
            margin-bottom: 8px;
            position: relative;
            padding-left: 24px;
            text-decoration: none;
        }
        .footer {
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
        }
        .footer p {
            margin: 0;
            color: #171717;
            font-size: 14px;
        }
        .footer a {
            color: #171717;
            text-decoration: none;
        }
        .social-links {
            margin: 20px 0;
        }
        .social-links a {
            display: inline-block;
            margin: 0 10px;
            color: #171717;
            text-decoration: none;
        }
        @media (max-width: 600px) {
            .content {
                padding: 30px 20px;
            }
            .header {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header with Logo -->
        <div class="header">
            <h1>Welcome to ExpenzAI</h1>
            <p>AI-Powered Expense Management</p>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="greeting">
                Hi {{ $user->name }}
            </div>

            <div class="message">
                Welcome to ExpenzAI! We're excited to help you revolutionize how you track and manage your expenses. 
                With our AI-powered receipt scanning, you'll never lose track of your spending again.
            </div>

            <div style="text-align: center;">
                <a href="{{ $verificationUrl }}" class="cta-button">
                    Verify Your Email Address
                </a>
            </div>

            <div class="features">
                <h3>What you can do with ExpenzAI:</h3>
                <ul class="feature-list">
                    <li>Scan receipts with your camera or upload images</li>
                    <li>AI automatically extracts and categorizes items</li>
                    <li>View detailed spending analytics and trends</li>
                    <li>Track weekly and monthly expense summaries</li>
                    <li>Edit and correct categorizations manually</li>
                </ul>
            </div>

            <div class="message">
                <strong>Important:</strong> Please verify your email within 24 hours to access all features. 
                Until verified, you'll have limited access to the dashboard.
            </div>

            <div class="message">
                If you didn't create this account, you can safely ignore this email.
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="social-links">
                <a href="https://www.expenzai.app">Visit Website</a> •
                <a href="mailto:contact@expenzai.app">Contact Support</a>
            </div>
            <p>
                © 2025 ExpenzAI. Made with ❤️ by BoylerPlate.<br>
                <a href="https://www.expenzai.app/privacy">Privacy Policy</a> • 
                <a href="https://www.expenzai.app/terms">Terms of Service</a>
            </p>
        </div>
    </div>
</body>
</html>