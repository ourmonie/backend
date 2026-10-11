<?php

namespace App\Services\Gateways;

/**
 * Push money out to a Nigerian bank account through whichever gateway is
 * active.
 *
 * A payout is deliberately more conservative than an inbound credit. The
 * inbound path can recover from a missed webhook by requerying the provider;
 * an outbound path that fires twice sends real money twice. So every guard
 * here is front-loaded: the destination is name-enumerated against the
 * provider's own directory before a cent moves, and the provider's own
 * reference is what the ledger records afterwards.
 *
 * No ledger posting happens in this class. A caller may only record a
 * settlement once it holds a transfer identifier it can requery - which is
 * what makes a transfer that dies mid-flight recoverable instead of lost.
 */
class PayoutService
{
    /**
     * Confirm the destination exists and belongs to the name given.
     *
     * Providers resolve against their own bank directory, so this is the
     * single check that stops a payout being sent to an account number that
     * was mistyped. Providers disagree on how hard to fail: Paystack throws
     * when a name does not match, Flutterwave only reports it. Both are
     * treated as fatal here, because a payout to a stranger is unrecoverable
     * and a rejected payout is not.
     *
     * @return array{name:string,account_number:string,bank_code:string,bank_name:string}
     */
    public function resolve(string $gateway, string $accountNumber, string $bankCode, string $expectedName): array
    {
        $this->guard($gateway);

        $resolved = $this->driver($gateway)->resolveAccount($accountNumber, $bankCode);

        $returned = trim((string) ($resolved['account_name'] ?? ''));
        $expected = trim($expectedName);

        if ($returned === '') {
            throw new GatewayException(
                'That account number could not be resolved with that bank. Check both and try again.'
            );
        }

        if (! $this->namesAgree($returned, $expected)) {
            throw new GatewayException(sprintf(
                'The bank returned "%s" for that account number, which does not match "%s". Payout cancelled.',
                $returned,
                $expected,
            ));
        }

        return [
            'name' => $returned,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'bank_name' => $resolved['bank_name'] ?? $bankCode,
        ];
    }

    /**
     * Start a transfer and hand back the identifier to requery on.
     *
     * @param  array{account_number:string,bank_code:string,name:string}  $destination
     * @return array{provider:string,reference:string,transfer_code:string,status:string}
     */
    public function send(
        string $gateway,
        array $destination,
        float $amount,
        string $reference,
        string $reason = 'OurMonie withdrawal',
    ): array {
        $this->guard($gateway);

        if ($amount <= 0) {
            throw new GatewayException('A payout must move a positive amount.');
        }

        $driver = $this->driver($gateway);

        // Paystack needs a recipient registered before it will accept a
        // transfer; Flutterwave takes the destination inline. Both tolerate
        // a repeat call for a destination that already exists, so this is
        // safe to run on every payout rather than cached.
        $recipient = $driver->createTransferRecipient([
            'name' => $destination['name'],
            'account_number' => $destination['account_number'],
            'bank_code' => $destination['bank_code'],
        ]);

        $transfer = $driver->initiateTransfer([
            'amount' => $amount,
            'reference' => $reference,
            'reason' => $reason,
            'bank_code' => $destination['bank_code'],
            'account_number' => $destination['account_number'],
            'recipient_code' => $recipient['recipient_code'] ?? null,
        ]);

        if ($transfer['transfer_code'] === null || $transfer['transfer_code'] === '') {
            throw new GatewayException(
                'The gateway accepted the request but returned no transfer identifier, so the payout cannot be traced. Nothing was recorded.'
            );
        }

        return [
            'provider' => $gateway,
            'reference' => $reference,
            'transfer_code' => $transfer['transfer_code'],
            'status' => $transfer['status'],
        ];
    }

    /** Re-read a transfer by the identifier the gateway issued. */
    public function status(string $gateway, string $transferCode): array
    {
        $this->guard($gateway);

        return $this->driver($gateway)->fetchTransfer($transferCode);
    }

    /**
     * The name the bank resolved has to confirm the member we meant to pay.
     *
     * The bank's record is the authoritative one, so the test runs from it
     * back to what we expected - not the other way round. Nigerian names
     * routinely carry extra surnames and titles the member does not type,
     * so a longer bank name is normal and accepted. A shorter one is not:
     * the bank has failed to confirm the beneficiary, and a payout to an
     * unconfirmed account is the one mistake that cannot be undone.
     */
    protected function namesAgree(string $returned, string $expected): bool
    {
        $returnedTokens = $this->nameTokens($returned);
        $expectedTokens = $this->nameTokens($expected);

        if ($expectedTokens === []) {
            return false;
        }

        // Purely typographic differences - spacing, punctuation, an
        // apostrophe - are not differences in the person. "O'BRIEN" and
        // "OBRIEN" are the same name split differently by word, so this is
        // checked before the per-word test below, which would read them as
        // two separate words and refuse.
        if (implode('', $returnedTokens) === implode('', $expectedTokens)) {
            return true;
        }

        // Every word we were given has to appear in the bank's record.
        //
        // The bank may return more than we supplied - a second surname, a
        // middle name, a title - which is ordinary Nigerian naming and is
        // accepted. It may not return less. A bank that cannot produce a
        // word we supplied has failed to confirm the beneficiary, and a
        // payout to an unconfirmed account is the one mistake that cannot
        // be undone.
        foreach ($expectedTokens as $token) {
            if (! in_array($token, $returnedTokens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fold a name down to its comparable words.
     *
     * Punctuation becomes a separator rather than being deleted, so
     * "MARIÉ-JEANNE" reads as two words and its accent is folded instead of
     * being stripped along with the hyphen - otherwise the character count
     * would shift and a correctly spelled name would fail to match.
     *
     * @return array<int, string>
     */
    private function nameTokens(string $value): array
    {
        $value = $this->foldAccents(mb_strtolower($value, 'UTF-8'));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        $value = trim($value);

        return $value === '' ? [] : explode(' ', $value);
    }

    private function foldAccents(string $value): string
    {
        // Accents are folded to their base letter before punctuation is
        // stripped, so "É" and "E" agree and neither silently loses a
        // character the way a byte-wise strip would.
        return strtr($value, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        ]);
    }

    private function guard(string $gateway): void
    {
        if (! GatewayConfig::exists($gateway)) {
            throw new GatewayException('Unknown payment gateway.');
        }

        if (! GatewayConfig::enabled($gateway) || ! GatewayConfig::ready($gateway)) {
            throw new GatewayException(sprintf(
                '%s is not switched on with a saved %s key. Payouts are refused until the Payments screen shows it ready.',
                ucfirst($gateway),
                GatewayConfig::mode($gateway),
            ));
        }
    }

    protected function driver(string $gateway): object
    {
        return $gateway === 'flutterwave'
            ? app(FlutterwaveGateway::class)
            : app(PaystackGateway::class);
    }
}
