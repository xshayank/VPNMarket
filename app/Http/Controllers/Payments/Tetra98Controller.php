<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Reseller;
use App\Models\Transaction;
use App\Services\Payments\Tetra98Client;
use App\Services\WalletResellerReenableService;
use App\Support\Tetra98Config;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class Tetra98Controller extends Controller
{
    public function __construct(
        private readonly Tetra98Client $client,
        private readonly WalletResellerReenableService $walletResellerReenableService
    ) {
    }

    public function initiate(Request $request)
    {
        if (! Tetra98Config::isAvailable()) {
            abort(SymfonyResponse::HTTP_FORBIDDEN, 'درگاه Tetra98 فعال نیست.');
        }

        $minAmount = Tetra98Config::getMinAmountToman();

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:'.$minAmount],
            'phone' => ['required', 'regex:/^09\d{9}$/'],
        ], [
            'amount.required' => 'وارد کردن مبلغ الزامی است.',
            'amount.integer' => 'مبلغ باید به صورت عددی وارد شود.',
            'amount.min' => 'حداقل مبلغ مجاز برای پرداخت '.number_format($minAmount).' تومان است.',
            'phone.required' => 'وارد کردن شماره موبایل برای پرداخت Tetra98 الزامی است.',
            'phone.regex' => 'شماره موبایل باید با 09 شروع شده و 11 رقم باشد.',
        ]);

        $user = Auth::user();

        try {
            $hashId = 'tetra98-'.$user->id.'-'.Str::uuid()->toString();
            $metadata = [
                'payment_method' => 'tetra98',
                'phone' => $validated['phone'],
                'email' => $user->email,
                'tetra98' => [
                    'hash_id' => $hashId,
                    'amount_toman' => (int) $validated['amount'],
                    'state' => 'created',
                ],
            ];

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'order_id' => null,
                'amount' => (int) $validated['amount'],
                'type' => Transaction::TYPE_DEPOSIT,
                'status' => Transaction::STATUS_PENDING,
                'description' => 'شارژ کیف پول (درگاه Tetra98) - در انتظار پرداخت',
                'metadata' => $metadata,
            ]);
        } catch (Throwable $exception) {
            Log::error('tetra98_initiate_transaction_failed', [
                'action' => 'tetra98_initiate_transaction_failed',
                'user_id' => $user->id,
                'amount' => $validated['amount'],
                'message' => $exception->getMessage(),
            ]);

            return back()->withErrors(['tetra98' => 'خطایی در ثبت تراکنش رخ داد. لطفاً دوباره تلاش کنید.'])->withInput();
        }

        $callbackUrl = URL::to(Tetra98Config::getCallbackPath());
        $description = Tetra98Config::getDefaultDescription();

        Log::info('tetra98_initiate_request', [
            'action' => 'tetra98_initiate_request',
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'amount' => $transaction->amount,
            'hash_id' => Arr::get($transaction->metadata, 'tetra98.hash_id'),
            'callback_url' => $callbackUrl,
        ]);

        try {
            $response = $this->client->createOrder(
                Arr::get($transaction->metadata, 'tetra98.hash_id'),
                (int) $transaction->amount,
                $description,
                $user->email,
                $validated['phone'],
                $callbackUrl
            );
        } catch (Throwable $exception) {
            $this->markTransactionFailed($transaction, [
                'error' => $exception->getMessage(),
            ]);

            Log::error('tetra98_initiate_exception', [
                'action' => 'tetra98_initiate_exception',
                'transaction_id' => $transaction->id,
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);

            return back()->with('tetra98_error', 'امکان برقراری ارتباط با درگاه Tetra98 وجود ندارد. لطفاً بعداً تلاش کنید.')->withInput();
        }

        $responseData = $response->json();

        Log::info('tetra98_initiate_response', [
            'action' => 'tetra98_initiate_response',
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'http_status' => $response->status(),
            'response' => $this->sanitizePayload($responseData),
        ]);

        if (! $response->successful() || (string) Arr::get($responseData, 'status') !== '100') {
            $this->markTransactionFailed($transaction, [
                'initiate_response' => $this->sanitizePayload($responseData),
                'http_status' => $response->status(),
            ]);

            return back()->with('tetra98_error', 'درگاه Tetra98 در حال حاضر در دسترس نیست. لطفاً دوباره تلاش کنید.')->withInput();
        }

        $paymentUrl = Arr::get($responseData, 'payment_url_web');
        $authority = Arr::get($responseData, 'Authority');

        if (! $paymentUrl || ! $authority) {
            $this->markTransactionFailed($transaction, [
                'initiate_response' => $this->sanitizePayload($responseData),
                'missing_fields' => true,
            ]);

            return back()->with('tetra98_error', 'پاسخ نامعتبر از درگاه Tetra98 دریافت شد.')->withInput();
        }

        $metadata = $transaction->metadata ?? [];
        $metadata['tetra98'] = array_merge($metadata['tetra98'] ?? [], [
            'authority' => $authority,
            'tracking_id' => Arr::get($responseData, 'tracking_id'),
            'payment_url_web' => $paymentUrl,
            'payment_url_bot' => Arr::get($responseData, 'payment_url_bot'),
            'initiate_response' => $this->sanitizePayload($responseData),
            'state' => 'redirected',
        ]);

        $transaction->update(['metadata' => $metadata]);

        return redirect()->away($paymentUrl);
    }

    public function callback(Request $request)
    {
        $payload = $request->all();
        $hashId = (string) Arr::get($payload, 'hashid');
        $authority = (string) Arr::get($payload, 'authority');
        $statusValue = Arr::get($payload, 'status');
        $statusInt = is_numeric($statusValue) ? (int) $statusValue : (int) ((string) $statusValue === '100');

        Log::info('tetra98_callback_received', [
            'action' => 'tetra98_callback_received',
            'hash_id' => $hashId,
            'authority' => $authority,
            'status' => $statusValue,
            'http_status' => SymfonyResponse::HTTP_OK,
        ]);

        if ($hashId === '') {
            Log::warning('tetra98_callback_missing_hashid', [
                'action' => 'tetra98_callback_missing_hashid',
                'payload' => $this->sanitizePayload($payload),
            ]);

            return response()->json(['message' => 'hashid missing'], SymfonyResponse::HTTP_BAD_REQUEST);
        }

        $transaction = Transaction::whereJsonContains('metadata->tetra98->hash_id', $hashId)->first();

        if (! $transaction) {
            Log::warning('tetra98_callback_transaction_not_found', [
                'action' => 'tetra98_callback_transaction_not_found',
                'hash_id' => $hashId,
            ]);

            return response()->json(['message' => 'transaction not found'], SymfonyResponse::HTTP_NOT_FOUND);
        }

        $verifyResponse = null;
        $verifyData = null;
        $verifySuccessful = false;
        $verifyHttpStatus = null;

        if ($statusInt === 100 && $authority !== '') {
            try {
                $verifyResponse = $this->client->verify($authority);
                $verifyHttpStatus = $verifyResponse->status();
                $verifyData = $verifyResponse->json();
                $verifySuccessful = $verifyResponse->successful() && (string) Arr::get($verifyData, 'status') === '100';
            } catch (Throwable $exception) {
                Log::error('tetra98_verify_exception', [
                    'action' => 'tetra98_verify_exception',
                    'transaction_id' => $transaction->id,
                    'authority' => $authority,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $resellerIdForReenable = null;

        DB::transaction(function () use (
            $transaction,
            $payload,
            $authority,
            $statusValue,
            $statusInt,
            $verifySuccessful,
            $verifyData,
            $verifyHttpStatus,
            &$resellerIdForReenable
        ) {
            $fresh = Transaction::whereKey($transaction->id)->lockForUpdate()->first();

            $metadata = $fresh->metadata ?? [];
            $tetraMeta = $metadata['tetra98'] ?? [];

            if ($authority !== '') {
                $tetraMeta['authority'] = $authority;
            }

            $tetraMeta['last_status'] = $statusValue;
            $tetraMeta['callback_payload'] = $this->sanitizePayload($payload);
            if ($verifyData !== null) {
                $tetraMeta['verify_response'] = $this->sanitizePayload($verifyData);
                $tetraMeta['verify_http_status'] = $verifyHttpStatus;
                $tetraMeta['verified_at'] = now()->toIso8601String();
            }

            if ($fresh->status === Transaction::STATUS_COMPLETED) {
                $tetraMeta['state'] = 'completed';
                $tetraMeta['verification_status'] = $tetraMeta['verification_status'] ?? 'success';
                $metadata['tetra98'] = $tetraMeta;
                $fresh->update(['metadata' => $metadata]);

                Log::info('tetra98_verify_success', [
                    'action' => 'tetra98_verify_success',
                    'transaction_id' => $fresh->id,
                    'user_id' => $fresh->user_id,
                    'authority' => $authority,
                    'amount' => $fresh->amount,
                    'idempotent' => true,
                ]);

                return;
            }

            if ($statusInt !== 100 || ! $verifySuccessful) {
                $tetraMeta['state'] = 'failed';
                $tetraMeta['verification_status'] = 'failed';
                $metadata['tetra98'] = $tetraMeta;
                $fresh->update([
                    'status' => Transaction::STATUS_FAILED,
                    'metadata' => $metadata,
                ]);

                Log::warning('tetra98_verify_failed', [
                    'action' => 'tetra98_verify_failed',
                    'transaction_id' => $fresh->id,
                    'user_id' => $fresh->user_id,
                    'authority' => $authority,
                    'amount' => $fresh->amount,
                    'status_value' => $statusValue,
                    'verify_http_status' => $verifyHttpStatus,
                ]);

                return;
            }

            $user = $fresh->user()->lockForUpdate()->first();
            $reseller = $user?->reseller()->lockForUpdate()->first();

            $tetraMeta['state'] = 'completed';
            $tetraMeta['verification_status'] = 'success';
            $tetraMeta['wallet_credited_at'] = now()->toIso8601String();
            $metadata['tetra98'] = $tetraMeta;

            Log::info('tetra98_verify_success', [
                'action' => 'tetra98_verify_success',
                'transaction_id' => $fresh->id,
                'user_id' => $fresh->user_id,
                'authority' => $authority,
                'amount' => $fresh->amount,
                'idempotent' => false,
            ]);

            if ($reseller instanceof Reseller && method_exists($reseller, 'isWalletBased') && $reseller->isWalletBased()) {
                $reseller->increment('wallet_balance', $fresh->amount);
                $resellerIdForReenable = $reseller->id;
            } else {
                $user?->increment('balance', $fresh->amount);
            }

            $fresh->update([
                'status' => Transaction::STATUS_COMPLETED,
                'description' => 'شارژ کیف پول (درگاه Tetra98)',
                'metadata' => $metadata,
            ]);

            Log::info('tetra98_wallet_credited', [
                'action' => 'tetra98_wallet_credited',
                'transaction_id' => $fresh->id,
                'user_id' => $fresh->user_id,
                'amount' => $fresh->amount,
                'authority' => $authority,
            ]);
        });

        if ($resellerIdForReenable) {
            $reseller = Reseller::find($resellerIdForReenable);
            $user = $transaction->user()->first();

            if ($reseller && method_exists($reseller, 'isWalletBased') && $reseller->isWalletBased()) {
                $reseller->refresh();

                if (method_exists($reseller, 'isSuspendedWallet') &&
                    $reseller->isSuspendedWallet() &&
                    $reseller->wallet_balance > config('billing.wallet.suspension_threshold', -1000)) {

                    Log::info('tetra98_reseller_reactivation_start', [
                        'action' => 'tetra98_reseller_reactivation_start',
                        'reseller_id' => $reseller->id,
                        'user_id' => $user?->id,
                        'wallet_balance' => $reseller->wallet_balance,
                    ]);

                    $stats = $this->walletResellerReenableService->reenableWalletSuspendedConfigs($reseller);

                    Log::info('tetra98_reseller_reactivation_complete', [
                        'action' => 'tetra98_reseller_reactivation_complete',
                        'reseller_id' => $reseller->id,
                        'user_id' => $user?->id,
                        'configs_enabled' => $stats['enabled'] ?? 0,
                        'configs_failed' => $stats['failed'] ?? 0,
                    ]);
                }
            }
        }

        return response()->json(['message' => 'ok']);
    }

    protected function markTransactionFailed(Transaction $transaction, array $extraMeta = []): void
    {
        $metadata = $transaction->metadata ?? [];
        $tetraMeta = $metadata['tetra98'] ?? [];
        $tetraMeta['state'] = 'failed';
        $tetraMeta = array_merge($tetraMeta, $extraMeta);
        $metadata['tetra98'] = $tetraMeta;

        $transaction->update([
            'status' => Transaction::STATUS_FAILED,
            'metadata' => $metadata,
        ]);
    }

    protected function sanitizePayload($payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $sanitized[$key] = is_string($value)
                    ? mb_strimwidth($value, 0, 200, '…')
                    : $value;
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);
            }
        }

        return $sanitized;
    }
}
