<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Exception;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Create a new wallet for a user under a merchant.
     */
    public function createWallet(
        ?int $merchantId,
        string $userIdentifier,
        string $currency = 'NGN',
        string $type = 'fiat',
        ?string $label = null,
    ): Wallet {
        return Wallet::firstOrCreate(
            [
                'merchant_id' => $merchantId,
                'user_identifier' => $userIdentifier,
                'currency' => $currency,
                'type' => $type,
            ],
            [
                'label' => $label ?? "{$currency} {$type} wallet",
                'balance' => 0,
                'ledger_balance' => 0,
                'hold_balance' => 0,
                'status' => true,
            ]
        );
    }

    /**
     * Credit a wallet (deposit funds).
     */
    public function credit(
        Wallet $wallet,
        float $amount,
        string $referenceType,
        string $referenceId,
        string $description = '',
        array $metadata = [],
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new Exception('Credit amount must be positive.');
        }

        return DB::transaction(function () use ($wallet, $amount, $referenceType, $referenceId, $description, $metadata) {
            $wallet->lockForUpdate();

            $balanceBefore = (float) $wallet->ledger_balance;
            $balanceAfter = $balanceBefore + $amount;

            $wallet->update([
                'ledger_balance' => $balanceAfter,
                'balance' => (float) $wallet->balance + $amount,
            ]);

            $wallet->refresh();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Debit a wallet (withdraw funds). Checks available balance.
     */
    public function debit(
        Wallet $wallet,
        float $amount,
        string $referenceType,
        string $referenceId,
        string $description = '',
        array $metadata = [],
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new Exception('Debit amount must be positive.');
        }

        return DB::transaction(function () use ($wallet, $amount, $referenceType, $referenceId, $description, $metadata) {
            $wallet->lockForUpdate();

            $available = (float) ($wallet->ledger_balance - $wallet->hold_balance);
            if ($available < $amount) {
                throw new Exception("Insufficient available balance. Available: {$available}, Requested: {$amount}");
            }

            $balanceBefore = (float) $wallet->ledger_balance;
            $balanceAfter = $balanceBefore - $amount;

            $wallet->update([
                'ledger_balance' => $balanceAfter,
                'balance' => (float) $wallet->balance - $amount,
            ]);

            $wallet->refresh();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Transfer funds between two wallets (double-entry).
     */
    public function transfer(
        Wallet $from,
        Wallet $to,
        float $amount,
        string $referenceType,
        string $referenceId,
        string $description = '',
        array $metadata = [],
    ): array {
        if ($amount <= 0) {
            throw new Exception('Transfer amount must be positive.');
        }

        return DB::transaction(function () use ($from, $to, $amount, $referenceType, $referenceId, $description, $metadata) {
            $from->lockForUpdate();
            $to->lockForUpdate();

            $available = (float) ($from->ledger_balance - $from->hold_balance);
            if ($available < $amount) {
                throw new Exception("Insufficient available balance. Available: {$available}, Requested: {$amount}");
            }

            $fromBalanceBefore = (float) $from->ledger_balance;
            $toBalanceBefore = (float) $to->ledger_balance;

            $from->update([
                'ledger_balance' => $fromBalanceBefore - $amount,
                'balance' => (float) $from->balance - $amount,
            ]);

            $to->update([
                'ledger_balance' => $toBalanceBefore + $amount,
                'balance' => (float) $to->balance + $amount,
            ]);

            $from->refresh();
            $to->refresh();

            $debitTxn = WalletTransaction::create([
                'wallet_id' => $from->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_before' => $fromBalanceBefore,
                'balance_after' => (float) $from->ledger_balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'counterparty_wallet_id' => $to->id,
                'description' => $description,
                'metadata' => $metadata,
            ]);

            $creditTxn = WalletTransaction::create([
                'wallet_id' => $to->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_before' => $toBalanceBefore,
                'balance_after' => (float) $to->ledger_balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'counterparty_wallet_id' => $from->id,
                'description' => $description,
                'metadata' => $metadata,
            ]);

            return [
                'debit' => $debitTxn,
                'credit' => $creditTxn,
                'from_balance' => (float) $from->ledger_balance,
                'to_balance' => (float) $to->ledger_balance,
            ];
        });
    }

    /**
     * Place a hold on funds (for escrow).
     */
    public function hold(
        Wallet $wallet,
        float $amount,
        string $referenceType,
        string $referenceId,
        string $description = '',
        array $metadata = [],
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new Exception('Hold amount must be positive.');
        }

        return DB::transaction(function () use ($wallet, $amount, $referenceType, $referenceId, $description, $metadata) {
            $wallet->lockForUpdate();

            $available = (float) ($wallet->ledger_balance - $wallet->hold_balance);
            if ($available < $amount) {
                throw new Exception("Insufficient balance to place hold. Available: {$available}, Required: {$amount}");
            }

            $balanceBefore = (float) $wallet->balance;
            $holdBefore = (float) $wallet->hold_balance;

            $wallet->update([
                'balance' => (float) $wallet->balance - $amount,
                'hold_balance' => (float) $wallet->hold_balance + $amount,
            ]);

            $wallet->refresh();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'hold',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => (float) $wallet->balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Release a hold (funds go to the merchant/beneficiary wallet).
     */
    public function releaseHold(
        Wallet $wallet,
        Wallet $beneficiaryWallet,
        float $amount,
        string $referenceType,
        string $referenceId,
        string $description = '',
        array $metadata = [],
    ): array {
        if ($amount <= 0) {
            throw new Exception('Release amount must be positive.');
        }

        return DB::transaction(function () use ($wallet, $beneficiaryWallet, $amount, $referenceType, $referenceId, $description, $metadata) {
            $wallet->lockForUpdate();
            $beneficiaryWallet->lockForUpdate();

            if ((float) $wallet->hold_balance < $amount) {
                throw new Exception("Insufficient hold balance. Held: {$wallet->hold_balance}, Requested: {$amount}");
            }

            $wallet->update([
                'hold_balance' => (float) $wallet->hold_balance - $amount,
                'ledger_balance' => (float) $wallet->ledger_balance - $amount,
            ]);

            $beneficiaryWallet->update([
                'ledger_balance' => (float) $beneficiaryWallet->ledger_balance + $amount,
                'balance' => (float) $beneficiaryWallet->balance + $amount,
            ]);

            $wallet->refresh();
            $beneficiaryWallet->refresh();

            $releaseTxn = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'release_hold',
                'amount' => $amount,
                'balance_before' => (float) $wallet->balance,
                'balance_after' => (float) $wallet->balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'counterparty_wallet_id' => $beneficiaryWallet->id,
                'description' => $description,
                'metadata' => $metadata,
            ]);

            $creditTxn = WalletTransaction::create([
                'wallet_id' => $beneficiaryWallet->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_before' => (float) $beneficiaryWallet->balance,
                'balance_after' => (float) $beneficiaryWallet->balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'counterparty_wallet_id' => $wallet->id,
                'description' => 'Funds released from escrow: ' . $description,
                'metadata' => $metadata,
            ]);

            return [
                'release' => $releaseTxn,
                'credit' => $creditTxn,
                'payer_balance' => (float) $wallet->ledger_balance,
                'beneficiary_balance' => (float) $beneficiaryWallet->ledger_balance,
            ];
        });
    }

    /**
     * Reverse a hold (escrow cancelled — funds go back to original wallet).
     */
    public function reverseHold(
        Wallet $wallet,
        float $amount,
        string $referenceType,
        string $referenceId,
        string $description = '',
        array $metadata = [],
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new Exception('Reverse amount must be positive.');
        }

        return DB::transaction(function () use ($wallet, $amount, $referenceType, $referenceId, $description, $metadata) {
            $wallet->lockForUpdate();

            if ((float) $wallet->hold_balance < $amount) {
                throw new Exception("Insufficient hold balance to reverse. Held: {$wallet->hold_balance}, Requested: {$amount}");
            }

            $wallet->update([
                'hold_balance' => (float) $wallet->hold_balance - $amount,
                'balance' => (float) $wallet->balance + $amount,
            ]);

            $wallet->refresh();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'reversal',
                'amount' => $amount,
                'balance_before' => (float) $wallet->balance,
                'balance_after' => (float) $wallet->balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Get wallet balance details.
     */
    public function getBalance(Wallet $wallet): array
    {
        return [
            'currency' => $wallet->currency,
            'balance' => (float) $wallet->balance,
            'ledger_balance' => (float) $wallet->ledger_balance,
            'hold_balance' => (float) $wallet->hold_balance,
            'available_balance' => (float) ($wallet->ledger_balance - $wallet->hold_balance),
        ];
    }
}
