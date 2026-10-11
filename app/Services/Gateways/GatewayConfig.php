<?php

namespace App\Services\Gateways;

use App\Models\Setting;

/**
 * Reads gateway configuration out of the settings table.
 *
 * Every gateway carries two complete key sets - test and live - plus its own
 * switch. `mode` decides which pair is used; `enabled` decides whether the
 * gateway may be used at all. Both are edited from the Payments screen, so a
 * code or .env change is never needed to go from sandbox to production.
 *
 * Secrets never leave this class in a response body: AdminPaymentGateway
 * reports only whether a key is present and its last four characters.
 */
class GatewayConfig
{
    public const PAYSTACK = 'paystack';
    public const FLUTTERWAVE = 'flutterwave';

    public const GATEWAYS = [self::PAYSTACK, self::FLUTTERWAVE];

    public const MODES = ['test', 'live'];

    public static function exists(string $gateway): bool
    {
        return in_array($gateway, self::GATEWAYS, true);
    }

    public static function enabled(string $gateway): bool
    {
        return (bool) Setting::getValue($gateway.'.enabled', false);
    }

    /** 'test' | 'live' */
    public static function mode(string $gateway): string
    {
        $mode = (string) Setting::getValue($gateway.'.mode', 'test');

        return in_array($mode, self::MODES, true) ? $mode : 'test';
    }

    public static function secretKey(string $gateway, ?string $mode = null): ?string
    {
        return self::readSecret($gateway, ($mode ?? self::mode($gateway)).'_secret_key');
    }

    public static function publicKey(string $gateway, ?string $mode = null): ?string
    {
        $value = trim((string) Setting::getValue(
            $gateway.'.'.($mode ?? self::mode($gateway)).'_public_key',
            ''
        ));

        return $value === '' ? null : $value;
    }

    /**
     * The key webhooks are signed with. Providers allow a dedicated webhook
     * secret; when it is not set the signing key is the secret key itself,
     * which is what the dashboard documents.
     */
    public static function webhookSecret(string $gateway, ?string $mode = null): ?string
    {
        $mode = $mode ?? self::mode($gateway);
        $suffix = $gateway === self::PAYSTACK ? '_webhook_secret' : '_webhook_hash';

        $value = trim((string) Setting::getValue($gateway.'.'.$mode.$suffix, ''));

        if ($value !== '') {
            return $value;
        }

        return self::secretKey($gateway, $mode);
    }

    /** True when the active environment has a usable signing key. */
    public static function ready(string $gateway): bool
    {
        return self::secretKey($gateway) !== null;
    }

    /* ---------------------------------------------------------------------- */
    /* Status surface for the admin Payments screen                           */
    /* ---------------------------------------------------------------------- */

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function status(): array
    {
        return array_map(function (string $gateway) {
            $enabled = self::enabled($gateway);
            $mode = self::mode($gateway);

            return [
                'gateway' => $gateway,
                'label' => $gateway === self::PAYSTACK ? 'Paystack' : 'Flutterwave',
                'enabled' => $enabled,
                'mode' => $mode,
                'ready' => self::ready($gateway),
                'active' => $enabled && self::ready($gateway),
                'keys' => [
                    'test' => [
                        'public_key' => self::mask(self::publicKey($gateway, 'test')),
                        'secret_key' => self::mask(self::secretKey($gateway, 'test')),
                        'webhook_secret' => self::mask(self::webhookSecretOnly($gateway, 'test')),
                        'configured' => self::secretKey($gateway, 'test') !== null,
                    ],
                    'live' => [
                        'public_key' => self::mask(self::publicKey($gateway, 'live')),
                        'secret_key' => self::mask(self::secretKey($gateway, 'live')),
                        'webhook_secret' => self::mask(self::webhookSecretOnly($gateway, 'live')),
                        'configured' => self::secretKey($gateway, 'live') !== null,
                    ],
                ],
            ];
        }, self::GATEWAYS);
    }

    /** The one gateway currently taking money, or null when none is ready. */
    public static function activeGateway(): ?string
    {
        foreach (self::GATEWAYS as $gateway) {
            if (self::enabled($gateway) && self::ready($gateway)) {
                return $gateway;
            }
        }

        return null;
    }

    /* ---------------------------------------------------------------------- */

    private static function readSecret(string $gateway, string $suffix): ?string
    {
        $value = trim((string) Setting::getValue($gateway.'.'.$suffix, ''));

        return $value === '' ? null : $value;
    }

    private static function webhookSecretOnly(string $gateway, string $mode): ?string
    {
        $suffix = $gateway === self::PAYSTACK ? '_webhook_secret' : '_webhook_hash';
        $value = trim((string) Setting::getValue($gateway.'.'.$mode.$suffix, ''));

        return $value === '' ? null : $value;
    }

    /**
     * sk_live_1234…9876 -> sk_live_…9876. Enough for an admin to recognise
     * which key is saved without the value ever reaching the browser.
     */
    private static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (strlen($value) <= 8) {
            return str_repeat('•', strlen($value));
        }

        return substr($value, 0, 7).'…'.substr($value, -4);
    }
}
