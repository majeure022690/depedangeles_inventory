@extends('emails.layouts.branded')

@section('title', 'Reset your password')

@section('preheader')
Use this link to reset your password. It expires in {{ $expiresInMinutes }} minutes.
@endsection

@section('content')
    <tr>
        <td style="padding:32px 32px 8px;">
            <h1 style="margin:0 0 16px; font-size:22px; color:#00295e;">Reset your password</h1>
            <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">Hello {{ $name }},</p>
            <p style="margin:0 0 24px; font-size:15px; line-height:1.6;">We received a request to reset the password for your account. Click the button below to choose a new password.</p>
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:0 32px 24px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td align="center" style="border-radius:6px; background-color:#0052bc;">
                        <a href="{{ $url }}" target="_blank" style="display:inline-block; padding:14px 28px; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:6px;">Reset password</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td style="padding:0 32px 24px;">
            <p style="margin:0 0 16px; font-size:14px; line-height:1.6; color:#334155;">This link expires in <strong>{{ $expiresInMinutes }} minutes</strong>. If you did not request a password reset, you can ignore this email; your password will not change.</p>
            <p style="margin:0 0 8px; font-size:13px; line-height:1.6; color:#64748b;">If the button does not work, copy and paste this link into your browser:</p>
            <p style="margin:0; font-size:13px; line-height:1.6; word-break:break-all;"><a href="{{ $url }}" target="_blank" style="color:#0052bc;">{{ $url }}</a></p>
        </td>
    </tr>
@endsection

@section('footer', 'This is an automated message from the Schools Division Office. Please do not reply to this email. For security, never share this link with anyone.')
