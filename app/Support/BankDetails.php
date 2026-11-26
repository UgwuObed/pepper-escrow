<?php

namespace App\Support;

/**
 * Normalises and checks the bank details a participant is paid to.
 *
 * Caught here, a mistyped IBAN or sort code is a 422 when the participant is
 * added. Missed, it is a payout that bounces at release, after the money has
 * already been promised to them.
 */
class BankDetails
{
    /**
     * @param array<string, mixed> $input name, and either sort_code + account_number or iban (+ bic)
     * @return array{payee_name: string, sort_code: ?string, bank_account_number: ?string, iban: ?string, bic: ?string}
     *
     * @throws \InvalidArgumentException naming the field that is wrong
     */
    public static function normalise(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 140) {
            throw new \InvalidArgumentException('bank_account.name is required: the name on the bank account.');
        }

        $iban = strtoupper(preg_replace('/\s+/', '', (string) ($input['iban'] ?? '')));

        if ($iban !== '') {
            if (!self::validIban($iban)) {
                throw new \InvalidArgumentException('bank_account.iban is not a valid IBAN.');
            }

            $bic = strtoupper(preg_replace('/\s+/', '', (string) ($input['bic'] ?? '')));

            if ($bic !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
                throw new \InvalidArgumentException('bank_account.bic is not a valid BIC.');
            }

            return ['payee_name' => $name, 'sort_code' => null, 'bank_account_number' => null, 'iban' => $iban, 'bic' => $bic ?: null];
        }

        $sortCode = preg_replace('/[\s-]/', '', (string) ($input['sort_code'] ?? ''));
        $accountNumber = preg_replace('/\s/', '', (string) ($input['account_number'] ?? ''));

        if (!preg_match('/^\d{6}$/', $sortCode)) {
            throw new \InvalidArgumentException('bank_account.sort_code must be 6 digits, or give an iban instead.');
        }

        if (!preg_match('/^\d{8}$/', $accountNumber)) {
            throw new \InvalidArgumentException('bank_account.account_number must be 8 digits.');
        }

        return ['payee_name' => $name, 'sort_code' => $sortCode, 'bank_account_number' => $accountNumber, 'iban' => null, 'bic' => null];
    }

    /** ISO 13616 check: country code, check digits, then the mod-97 test. */
    public static function validIban(string $iban): bool
    {
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $digits = '';

        foreach (str_split($rearranged) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        // Piecewise mod 97, since the number is far too long for an int.
        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }

        return $remainder === 1;
    }

    /** Last four characters only, for showing details back without exposing them. */
    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_repeat('•', max(0, strlen($value) - 4)) . substr($value, -4);
    }
}
