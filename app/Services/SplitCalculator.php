<?php

namespace App\Services;

use App\PaymentGateways\StripeConnectGateway;

/**
 * Works out who gets what when a release is split.
 *
 * A rule's destinations are an ordered list, each taking a fixed amount, a
 * percentage of the release, or whatever is left:
 *
 *   [{"to": "logistics", "percentage": 10},
 *    {"to": "customs",   "amount": 250.00},
 *    {"to": "supplier",  "remainder": true}]
 *
 * Arithmetic is done in minor units so shares always add up to the release
 * exactly. Rounding never creates or loses a penny: whatever rounding leaves
 * over goes to the remainder, or to the first percentage share when there is
 * no remainder.
 */
class SplitCalculator
{
    public const MAX_DESTINATIONS = 20;

    /**
     * Check a destination list before it is saved, so a rule that could never
     * pay out correctly is refused when it is written rather than when money
     * is waiting on it.
     *
     * @return string|null the problem, or null when the list is sound
     */
    public static function validate(mixed $destinations): ?string
    {
        if (!is_array($destinations) || $destinations === [] || !array_is_list($destinations)) {
            return 'destinations must be a non-empty list.';
        }

        if (count($destinations) > self::MAX_DESTINATIONS) {
            return 'A rule can have at most ' . self::MAX_DESTINATIONS . ' destinations.';
        }

        $seen = [];
        $remainders = 0;
        $percentage = 0.0;
        $hasFixed = false;

        foreach ($destinations as $i => $d) {
            $n = $i + 1;

            if (!is_array($d) || !isset($d['to']) || !is_string($d['to']) || trim($d['to']) === '') {
                return "Destination {$n} needs a \"to\": a role name, an account id, or \"platform\".";
            }

            if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $d['to'])) {
                return "Destination {$n}: \"to\" may only contain letters, numbers, and _ . : -";
            }

            if (isset($seen[$d['to']])) {
                return "Destination \"{$d['to']}\" appears more than once. Combine its shares into one.";
            }
            $seen[$d['to']] = true;

            $kinds = array_values(array_filter([
                array_key_exists('percentage', $d) ? 'percentage' : null,
                array_key_exists('amount', $d) ? 'amount' : null,
                !empty($d['remainder']) ? 'remainder' : null,
            ]));

            if (count($kinds) !== 1) {
                return "Destination {$n} must have exactly one of percentage, amount or remainder.";
            }

            switch ($kinds[0]) {
                case 'percentage':
                    if (!is_numeric($d['percentage']) || $d['percentage'] <= 0 || $d['percentage'] > 100) {
                        return "Destination {$n}: percentage must be greater than 0 and at most 100.";
                    }
                    $percentage += (float) $d['percentage'];
                    break;
                case 'amount':
                    if (!is_numeric($d['amount']) || $d['amount'] <= 0) {
                        return "Destination {$n}: amount must be greater than 0.";
                    }
                    $hasFixed = true;
                    break;
                case 'remainder':
                    $remainders++;
                    break;
            }
        }

        if ($remainders > 1) {
            return 'Only one destination can take the remainder.';
        }

        if ($percentage > 100.0001) {
            return 'Percentages add up to ' . round($percentage, 2) . ', which is more than 100.';
        }

        // Without a remainder, something has to account for every penny, and
        // a fixed amount cannot because the release size varies.
        if ($remainders === 0 && ($hasFixed || abs($percentage - 100) > 0.0001)) {
            return 'Add a destination with "remainder": true, or make the percentages add up to exactly 100.';
        }

        return null;
    }

    /**
     * Split an amount across destinations.
     *
     * @param array<int, array<string, mixed>> $destinations
     * @return array<int, array{to: string, amount: float}> shares in rule order, zero shares omitted
     *
     * @throws \InvalidArgumentException when fixed and percentage shares need more than the release
     */
    public function split(float $amount, string $currency, array $destinations): array
    {
        $zeroDecimal = in_array(strtoupper($currency), StripeConnectGateway::ZERO_DECIMAL_CURRENCIES, true);
        $scale = $zeroDecimal ? 1 : 100;

        $total = (int) round($amount * $scale);
        $shares = [];
        $allocated = 0;
        $remainderIndex = null;
        $firstPercentageIndex = null;

        foreach ($destinations as $i => $d) {
            if (!empty($d['remainder'])) {
                $remainderIndex = $i;
                $shares[$i] = 0;
                continue;
            }

            if (array_key_exists('amount', $d)) {
                $shares[$i] = (int) round((float) $d['amount'] * $scale);
            } else {
                $shares[$i] = (int) floor($total * (float) $d['percentage'] / 100);
                $firstPercentageIndex ??= $i;
            }

            $allocated += $shares[$i];
        }

        if ($allocated > $total) {
            throw new \InvalidArgumentException(sprintf(
                'The fixed and percentage shares come to %s, more than the %s being released.',
                number_format($allocated / $scale, $zeroDecimal ? 0 : 2, '.', ''),
                number_format($amount, $zeroDecimal ? 0 : 2, '.', ''),
            ));
        }

        $left = $total - $allocated;

        if ($remainderIndex !== null) {
            $shares[$remainderIndex] = $left;
        } elseif ($left > 0 && $firstPercentageIndex !== null) {
            $shares[$firstPercentageIndex] += $left;
        }

        $result = [];
        foreach ($destinations as $i => $d) {
            if ($shares[$i] > 0) {
                $result[] = ['to' => (string) $d['to'], 'amount' => $shares[$i] / $scale];
            }
        }

        return $result;
    }
}
