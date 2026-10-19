<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title')</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
    <span style="display:none; max-height:0; overflow:hidden; opacity:0;">@yield('preheader')</span>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; background-color:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e2e8f0;">
                    <tr>
                        <td style="background-color:#003d8d; padding:24px; border-bottom:4px solid #dc2626;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="72" valign="middle">
                                        <img src="{{ $message->embed($sealPath) }}" width="64" height="64" alt="Seal of the Department of Education, Schools Division of Angeles City" style="display:block; border:0;">
                                    </td>
                                    <td valign="middle" align="center" style="padding:0 8px; color:#ffffff;">
                                        <div style="font-size:12px; letter-spacing:1px; text-transform:uppercase; color:#cce0fb;">Department of Education</div>
                                        <div style="font-size:18px; font-weight:bold; margin-top:4px;">Schools Division Office</div>
                                        <div style="font-size:12px; margin-top:4px; color:#cce0fb;">{{ config('app.name') }}</div>
                                    </td>
                                    <td width="72" valign="middle"></td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    @yield('content')

                    <tr>
                        <td style="background-color:#f8fafc; padding:20px 32px; border-top:1px solid #e2e8f0;">
                            <p style="margin:0; font-size:12px; line-height:1.6; color:#64748b;">@yield('footer')</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
