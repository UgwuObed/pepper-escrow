<?php

namespace App\Console\Commands;

use App\Services\SettlementRulesEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The sweep that makes time-based rules real.
 *
 * Without this running, elapsed-day rules never fire and nothing force
 * releases before Stripe's per-country holding limit. The limit is a
 * compliance obligation rather than a soft deadline, so a silently broken
 * scheduler is a compliance problem, not a delayed payout.
 */
class ProcessSettlementHolds extends Command
{
    protected $signature = 'settlements:process-holds
                            {--dry-run : Report what would happen without releasing anything}';

    protected $description = 'Fire due settlement rules and release holds approaching the Stripe limit';

    public function handle(SettlementRulesEngine $engine): int
    {
        if ($this->option('dry-run')) {
            return $this->dryRun();
        }

        $started = microtime(true);

        try {
            $summary = $engine->processDueHolds();
        } catch (\Throwable $e) {
            // A sweep that dies partway has still done real work, so this is
            // loud rather than silent.
            Log::error('Settlement hold sweep failed', ['error' => $e->getMessage()]);
            $this->error('Sweep failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $started, 2);

        $this->info(sprintf(
            'Released %d by rule, %d forced at the Stripe limit, %d failed (%ss)',
            $summary['released'], $summary['forced'], $summary['failed'], $elapsed
        ));

        // Forced releases mean a rule never fired in time, and failures mean
        // money did not move when it should have. Both deserve a log line
        // someone can alert on.
        if ($summary['forced'] > 0 || $summary['failed'] > 0) {
            Log::warning('Settlement sweep needed intervention', $summary);
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Show what the sweep would do. Useful before trusting it on a new
     * environment, and for checking a merchant's rules behave as expected.
     */
    protected function dryRun(): int
    {
        $holds = \App\Models\SettlementHold::whereIn('status', [
            \App\Models\SettlementHold::STATUS_HELD,
            \App\Models\SettlementHold::STATUS_PART_RELEASED,
            \App\Models\SettlementHold::STATUS_DISPUTED,
        ])->orderBy('hold_expires_at')->get();

        if ($holds->isEmpty()) {
            $this->info('No open holds.');

            return self::SUCCESS;
        }

        $rows = $holds->map(function ($hold) {
            $action = match (true) {
                $hold->isFrozen() && $hold->expiresWithin(SettlementRulesEngine::EXPIRY_SAFETY_DAYS)
                    => 'WARN: disputed and at the limit',
                $hold->isFrozen() => 'skip (disputed)',
                $hold->isSplit() && $hold->expiresWithin(SettlementRulesEngine::EXPIRY_SAFETY_DAYS)
                    => ($hold->on_expiry ?? 'refund') === 'refund'
                        ? 'REFUND (holding limit)'
                        : 'RELEASE ALL SHARES (holding limit)',
                $hold->expiresWithin(SettlementRulesEngine::EXPIRY_SAFETY_DAYS)
                    => 'FORCE RELEASE (Stripe limit)',
                default => 'evaluate elapsed-day rules',
            };

            return [
                $hold->reference,
                $hold->status,
                number_format($hold->remaining(), 2) . ' ' . $hold->currency,
                $hold->hold_expires_at?->toDateString() ?? '—',
                $action,
            ];
        });

        $this->table(['Reference', 'Status', 'Remaining', 'Release by', 'Would do'], $rows);
        $this->comment('Dry run: nothing was released.');

        return self::SUCCESS;
    }
}
