<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
</head>
<body style="margin: 0; background-color: #f3f4f6; color: #111827; font-family: Arial, sans-serif; line-height: 1.6;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 32px 16px;">
                <table role="presentation" style="width: 100%; max-width: 600px; margin: 0 auto; border-collapse: collapse; background-color: #ffffff;">
                    <tr>
                        <td style="padding: 24px 32px; border-bottom: 1px solid #e5e7eb; font-size: 24px; font-weight: bold;">LeggTix</td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 32px;">@yield('content')</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
