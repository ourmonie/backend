<?php

namespace App\Support;

/**
 * Transactional email bodies, written as inline-styled HTML so every mail
 * client renders them without a stylesheet. Restrained, mobile-first and
 * flat - the same visual rules the app follows: brand blue on white,
 * no gradients, generous spacing, one clear action per message.
 *
 * Colours are pulled into local variables first: PHP only interpolates
 * simple `{$var}` expressions inside heredocs, not `self::CONST`.
 */
class EmailTemplates
{
    private const BRAND = '#1f66f0';
    private const INK = '#162756';
    private const MUTED = '#5b6b8c';

    public static function welcome(string $name, string $memberNumber, string $referralCode): string
    {
        $brand = self::BRAND;
        $ink = self::INK;
        $muted = self::MUTED;
        $frontend = self::frontend();
        $firstName = trim((string) preg_split('/\s+/', $name)[0]);

        return self::wrap(
            'Welcome to OurMonie',
            <<<HTML
            <h1 style="margin:0 0 8px;font-size:24px;line-height:1.25;color:{$ink};font-weight:800;">Welcome, {$firstName}.</h1>
            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:{$muted};">
              Your application has been received and your membership is being processed.
              Here is what happens next.
            </p>

            <table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:separate;border:1px solid #e4eaf4;border-radius:16px;overflow:hidden;margin:0 0 24px;">
              <tr>
                <td style="padding:16px 20px;background:#f7faff;border-bottom:1px solid #e4eaf4;">
                  <span style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:{$muted};font-weight:700;">Membership number</span>
                  <div style="margin-top:4px;font-size:20px;font-weight:800;color:{$ink};letter-spacing:.04em;">{$memberNumber}</div>
                </td>
              </tr>
              <tr>
                <td style="padding:16px 20px;">
                  <span style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:{$muted};font-weight:700;">Your referral code</span>
                  <div style="margin-top:4px;font-size:20px;font-weight:800;color:{$brand};letter-spacing:.1em;">{$referralCode}</div>
                  <p style="margin:8px 0 0;font-size:13px;line-height:1.55;color:{$muted};">
                    Share it with friends - every member who joins with your code grows the cooperative and earns you reward points.
                  </p>
                </td>
              </tr>
            </table>

            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:{$muted};">
              Sign in to finish your KYC, upload your passport photograph and fund your wallet.
              Your dedicated account number appears on your dashboard the moment provisioning completes.
            </p>

            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:8px 0 8px;">
              <tr>
                <td style="border-radius:12px;background:{$brand};">
                  <a href="{$frontend}/login" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Open your dashboard</a>
                </td>
              </tr>
            </table>
            HTML
        );
    }

    public static function resetPassword(string $name, string $token, string $email): string
    {
        $brand = self::BRAND;
        $ink = self::INK;
        $muted = self::MUTED;
        $frontend = self::frontend();
        $firstName = trim((string) preg_split('/\s+/', $name)[0]);
        $url = $frontend.'/reset-password?token='.rawurlencode($token).'&email='.rawurlencode($email);

        return self::wrap(
            'Reset your password',
            <<<HTML
            <h1 style="margin:0 0 8px;font-size:24px;line-height:1.25;color:{$ink};font-weight:800;">Reset your password</h1>
            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:{$muted};">
              Hello {$firstName}, we received a request to reset the password on your OurMonie account.
              Choose a new one with the button below.
            </p>

            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:8px 0 20px;">
              <tr>
                <td style="border-radius:12px;background:{$brand};">
                  <a href="{$url}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Set a new password</a>
                </td>
              </tr>
            </table>

            <p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:{$muted};">
              This link expires in <strong style="color:{$ink};">60 minutes</strong> and can be used once.
              The button does not work? Paste this address into your browser:
            </p>
            <p style="margin:0 0 24px;font-size:12.5px;line-height:1.5;color:{$muted};word-break:break-all;">
              {$url}
            </p>

            <p style="margin:0;font-size:13.5px;line-height:1.6;color:{$muted};">
              If you did not request this, you can ignore this email - your password stays unchanged.
              For your safety we sign out every device after a password change.
            </p>
            HTML
        );
    }

    public static function test(): string
    {
        $ink = self::INK;
        $muted = self::MUTED;
        $sentAt = now()->format('d M Y, H:i');

        return self::wrap(
            'SMTP settings verified',
            <<<HTML
            <h1 style="margin:0 0 8px;font-size:24px;line-height:1.25;color:{$ink};font-weight:800;">Your email settings work.</h1>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:{$muted};">
              This test message was sent through the SMTP server you saved on the OurMonie
              admin dashboard. New members will receive their welcome email and password
              reset links through the same channel.
            </p>
            <p style="margin:0;font-size:14px;line-height:1.6;color:{$muted};">
              Sent at {$sentAt} (server time).
            </p>
            HTML
        );
    }

    /* ------------------------------- Internals ------------------------------ */

    public static function frontend(): string
    {
        $origins = config('cors.allowed_origins');
        $origin = is_array($origins) && $origins !== [] ? (string) reset($origins) : 'http://localhost:5173';

        return rtrim($origin, '/');
    }

    /** Shared shell: fluid, mobile-first, single column, flat brand header. */
    private static function wrap(string $preheader, string $body): string
    {
        $ink = self::INK;
        $muted = self::MUTED;
        $year = now()->year;

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <meta name="color-scheme" content="light dark">
          <title>{$preheader}</title>
        </head>
        <body style="margin:0;padding:0;background:#eef2f9;-webkit-font-smoothing:antialiased;">
          <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{$preheader} &zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f9;">
            <tr>
              <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;">
                  <tr>
                    <td style="padding:0 4px 20px;">
                      <span style="font-size:20px;font-weight:800;color:{$ink};letter-spacing:-.02em;">OurMonie</span>
                      <span style="font-size:12px;color:{$muted};">&nbsp;· Digital Cooperative</span>
                    </td>
                  </tr>
                  <tr>
                    <td style="background:#ffffff;border:1px solid #e4eaf4;border-radius:20px;padding:32px 28px;">
                      {$body}
                    </td>
                  </tr>
                  <tr>
                    <td style="padding:20px 4px 0;text-align:center;font-size:12px;line-height:1.6;color:{$muted};">
                      © {$year} OurMonie Digital Cooperative · Every member. Every naira. Accounted for.<br>
                      You received this because an OurMonie account uses this address.
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>
        </body>
        </html>
        HTML;
    }
}
