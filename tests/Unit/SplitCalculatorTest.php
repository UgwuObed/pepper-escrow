<?php

namespace Tests\Unit;

use App\Services\SplitCalculator;
use PHPUnit\Framework\TestCase;

class SplitCalculatorTest extends TestCase
{
    private function split(float $amount, array $destinations, string $currency = 'GBP'): array
    {
        return (new SplitCalculator())->split($amount, $currency, $destinations);
    }

    public function test_percentages_and_a_remainder(): void
    {
        $shares = $this->split(100000, [
            ['to' => 'logistics', 'percentage' => 10],
            ['to' => 'customs', 'percentage' => 15],
            ['to' => 'supplier', 'remainder' => true],
        ]);

        $this->assertSame([
            ['to' => 'logistics', 'amount' => 10000],
            ['to' => 'customs', 'amount' => 15000],
            ['to' => 'supplier', 'amount' => 75000],
        ], $shares);
    }

    public function test_fixed_amounts_mix_with_percentages(): void
    {
        $shares = $this->split(100000, [
            ['to' => 'manufacturer', 'percentage' => 65],
            ['to' => 'freight', 'amount' => 5000],
            ['to' => 'customs', 'amount' => 2000],
            ['to' => 'tax', 'percentage' => 10],
            ['to' => 'supplier', 'remainder' => true],
        ]);

        $this->assertEquals([65000, 5000, 2000, 10000, 18000], array_column($shares, 'amount'));
    }

    public function test_rounding_never_creates_or_loses_a_penny(): void
    {
        $shares = $this->split(100.00, [
            ['to' => 'a', 'percentage' => 33.33],
            ['to' => 'b', 'percentage' => 33.33],
            ['to' => 'c', 'remainder' => true],
        ]);

        $this->assertEqualsWithDelta(100.00, array_sum(array_column($shares, 'amount')), 0.0001);
        $this->assertEquals(33.33, $shares[0]['amount']);
        $this->assertEquals(33.34, $shares[2]['amount']);
    }

    public function test_without_a_remainder_rounding_goes_to_the_first_percentage(): void
    {
        $shares = $this->split(0.10, [
            ['to' => 'a', 'percentage' => 33.3333],
            ['to' => 'b', 'percentage' => 33.3333],
            ['to' => 'c', 'percentage' => 33.3334],
        ]);

        $this->assertEqualsWithDelta(0.10, array_sum(array_column($shares, 'amount')), 0.0001);
        $this->assertEquals(0.04, $shares[0]['amount']);
    }

    public function test_zero_decimal_currencies_split_in_whole_units(): void
    {
        $shares = $this->split(1001, [
            ['to' => 'a', 'percentage' => 50],
            ['to' => 'b', 'remainder' => true],
        ], 'JPY');

        $this->assertEquals([500, 501], array_column($shares, 'amount'));
    }

    public function test_an_empty_remainder_is_left_out(): void
    {
        $shares = $this->split(100, [
            ['to' => 'a', 'amount' => 100],
            ['to' => 'b', 'remainder' => true],
        ]);

        $this->assertSame([['to' => 'a', 'amount' => 100]], $shares);
    }

    public function test_shares_larger_than_the_release_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->split(100, [
            ['to' => 'a', 'amount' => 80],
            ['to' => 'b', 'percentage' => 30],
            ['to' => 'c', 'remainder' => true],
        ]);
    }

    /**
     * @dataProvider invalidLists
     */
    public function test_unsound_destination_lists_are_refused(mixed $destinations, string $expected): void
    {
        $this->assertStringContainsString($expected, (string) SplitCalculator::validate($destinations));
    }

    public static function invalidLists(): array
    {
        return [
            'empty' => [[], 'non-empty list'],
            'not a list' => [['to' => 'a'], 'non-empty list'],
            'missing to' => [[['percentage' => 100]], 'needs a "to"'],
            'two kinds' => [[['to' => 'a', 'percentage' => 50, 'amount' => 5]], 'exactly one of'],
            'no kind' => [[['to' => 'a']], 'exactly one of'],
            'over 100' => [[['to' => 'a', 'percentage' => 60], ['to' => 'b', 'percentage' => 50]], 'more than 100'],
            'two remainders' => [[['to' => 'a', 'remainder' => true], ['to' => 'b', 'remainder' => true]], 'Only one'],
            'duplicate' => [[['to' => 'a', 'percentage' => 50], ['to' => 'a', 'remainder' => true]], 'more than once'],
            'fixed without remainder' => [[['to' => 'a', 'amount' => 10], ['to' => 'b', 'percentage' => 90]], 'remainder'],
            'under 100 without remainder' => [[['to' => 'a', 'percentage' => 90]], 'exactly 100'],
            'bad characters' => [[['to' => 'a b', 'remainder' => true]], 'may only contain'],
        ];
    }

    public function test_sound_lists_pass(): void
    {
        $this->assertNull(SplitCalculator::validate([
            ['to' => 'a', 'percentage' => 60],
            ['to' => 'platform', 'percentage' => 40],
        ]));

        $this->assertNull(SplitCalculator::validate([
            ['to' => 'acct_123', 'amount' => 25],
            ['to' => 'supplier', 'remainder' => true],
        ]));
    }
}
