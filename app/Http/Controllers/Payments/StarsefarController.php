<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayTransaction;
use App\Models\Reseller;
use App\Services\Payments\StarsEfarClient;
use App\Services\WalletResellerReenableService;
use App\Services\WalletService;
use App\Support\StarsefarConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class StarsefarController extends Controller
{
    public function __construct(private WalletService $walletService)
    {
    }

    public function initiate(Request $request)
    {
        if (! StarsefarConfig::isEnabled()) {
            abort(SymfonyResponse::HTTP_FORBIDDEN, 'درگاه استارز فعال نیست.');
        }

        $minAmount = StarsefarConfig::getMinAmountToman();

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:'.$minAmount],
            'phone' => ['nullable', 'string', 'max:64'],
        ]);

        $user = Auth::user();

        try {
            $client = $this->makeClient();
        } catch (Throwable $exception) {
            Log::error('StarsEfar client initialization failed', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'تنظیمات درگاه استارز ناقص است.',
            ], SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        $callbackUrl = URL::to(StarsefarConfig::getCallbackPath());
        $customerPhone = trim((string) ($validated['phone'] ?? '')) ?: null;
        $targetAccount = StarsefarConfig::getDefaultTargetAccount() ?: '@xShayank';

        try {
            $response = $client->createGiftLink(
                (int) $validated['amount'],
                $targetAccount,
                $callbackUrl
            );
        } catch (Throwable $exception) {
            Log::error('StarsEfar createGiftLink threw exception', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'امکان ایجاد لینک پرداخت وجود ندارد. لطفاً بعداً تلاش کنید.',
            ], SymfonyResponse::HTTP_BAD_GATEWAY);
        }

        if (! ($response['success'] ?? false)) {
            Log::warning('StarsEfar gift link returned unsuccessful response', [
                'response' => $response,
            ]);

            return response()->json([
                'message' => 'خطا در ایجاد لینک پرداخت استارز.',
            ], SymfonyResponse::HTTP_BAD_GATEWAY);
        }

        $orderId = $response['orderId'] ?? null;
        $link = $response['link'] ?? null;

        if (! $orderId || ! $link) {
            Log::warning('StarsEfar gift link missing fields', [
                'response' => $response,
            ]);

            return response()->json([
                'message' => 'پاسخ نامعتبر از درگاه استارز.',
            ], SymfonyResponse::HTTP_BAD_GATEWAY);
        }

        $transaction = PaymentGatewayTransaction::create([
            'provider' => 'starsefar',
            'order_id' => $orderId,
            'user_id' => $user->id,
            'amount_toman' => (int) $validated['amount'],
            'stars' => Arr::get($response, 'data.stars'),
            'target_account' => $targetAccount,
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
            'meta' => [
                'response' => $response,
                'link' => $link,
                'callback_url' => $callbackUrl,
                'customer_phone' => $customerPhone,
            ],
        ]);

        Log::info('StarsEfar gift link created', [
            'transaction_id' => $transaction->id,
            'order_id' => $orderId,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'orderId' => $orderId,
            'link' => $link,
            'statusEndpoint' => route('wallet.charge.starsefar.status', ['orderId' => $orderId]),
        ]);
    }

    public function status(string $orderId)
    {
        $user = Auth::user();

        $transaction = PaymentGatewayTransaction::where('order_id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'تراکنش یافت نشد.'], SymfonyResponse::HTTP_NOT_FOUND);
        }

        if ($transaction->status === PaymentGatewayTransaction::STATUS_PENDING) {
            try {
                $client = $this->makeClient();
                $remote = $client->checkOrder($orderId);

                if (($remote['success'] ?? false) && ($remote['data']['paid'] ?? false)) {
                    $this->markTransactionPaid($transaction, $remote);
                }
            } catch (\Throwable $exception) {
                Log::warning('StarsEfar status check failed', [
                    'order_id' => $orderId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $transaction->refresh();

        return response()->json([
            'status' => $transaction->status,
        ]);
    }

    public function webhook(Request $request)
    {
        $payload = $request->all();
        $orderId = $payload['orderId'] ?? null;

        if (! $orderId) {
            Log::warning('StarsEfar webhook missing orderId', ['payload' => $payload]);

            return response()->json(['message' => 'orderId missing'], SymfonyResponse::HTTP_BAD_REQUEST);
        }

        $transaction = PaymentGatewayTransaction::where('order_id', $orderId)->first();

        if (! $transaction) {
            Log::warning('StarsEfar webhook transaction not found', ['order_id' => $orderId]);

            return response()->json(['message' => 'ok']);
        }

        if (($payload['status'] ?? null) === 'completed' || ($payload['status'] ?? null) === 'paid') {
            $this->markTransactionPaid($transaction, $payload);
        }

        return response()->json(['message' => 'ok']);
    }

    protected function makeClient(): StarsEfarClient
    {
        return new StarsEfarClient(StarsefarConfig::getBaseUrl(), StarsefarConfig::getApiKey());
    }

    protected function markTransactionPaid(PaymentGatewayTransaction $transaction, array $payload = []): void
    {
        DB::transaction(function () use ($transaction, $payload) {
            $fresh = PaymentGatewayTransaction::whereKey($transaction->id)->lockForUpdate()->first();

            if ($fresh->status === PaymentGatewayTransaction::STATUS_PAID) {
                // Already paid - idempotency guard
                Log::info('StarsEfar payment already processed (idempotent)', [
                    'action' => 'starsefar_payment_already_paid',
                    'transaction_id' => $fresh->id,
                    'order_id' => $fresh->order_id,
                    'user_id' => $fresh->user_id,
                ]);
                return;
            }

            Log::info('StarsEfar payment verified', [
                'action' => 'starsefar_payment_verified',
                'transaction_id' => $fresh->id,
                'order_id' => $fresh->order_id,
                'user_id' => $fresh->user_id,
                'amount' => $fresh->amount_toman,
            ]);

            $walletTransaction = $this->walletService->credit(
                $fresh->user,
                $fresh->amount_toman,
                'شارژ کیف پول (درگاه استارز تلگرام)',
                [
                    'gateway' => [
                        'provider' => $fresh->provider,
                        'order_id' => $fresh->order_id,
                    ],
                ]
            );

            Log::info('StarsEfar wallet credited', [
                'action' => 'starsefar_wallet_credited',
                'transaction_id' => $fresh->id,
                'wallet_transaction_id' => $walletTransaction->id,
                'user_id' => $fresh->user_id,
                'amount' => $fresh->amount_toman,
            ]);

            $meta = $fresh->meta ?? [];
            $meta['payload'] = $payload;
            $meta['wallet_transaction_id'] = $walletTransaction->id;

            $fresh->update([
                'status' => PaymentGatewayTransaction::STATUS_PAID,
                'callback_received_at' => now(),
                'meta' => $meta,
            ]);

            // Check if user has a wallet-based reseller and handle reactivation
            $user = $fresh->user;
            $reseller = $user->reseller;

            if ($reseller instanceof Reseller && 
                method_exists($reseller, 'isWalletBased') && 
                $reseller->isWalletBased()) {
                
                // Refresh reseller to get updated wallet balance
                $reseller->refresh();

                // Check if reseller was suspended and should be reactivated
                if (method_exists($reseller, 'isSuspendedWallet') &&
                    $reseller->isSuspendedWallet() &&
                    $reseller->wallet_balance > config('billing.wallet.suspension_threshold', -1000)) {
                    
                    Log::info('StarsEfar payment triggers reseller auto-reactivation', [
                        'action' => 'starsefar_reseller_reactivation_start',
                        'reseller_id' => $reseller->id,
                        'user_id' => $user->id,
                        'wallet_balance' => $reseller->wallet_balance,
                        'suspension_threshold' => config('billing.wallet.suspension_threshold', -1000),
                    ]);

                    $reseller->update(['status' => 'active']);

                    // Re-enable configs that were auto-disabled due to wallet suspension
                    $reenableService = new WalletResellerReenableService();
                    $reenableStats = $reenableService->reenableWalletSuspendedConfigs($reseller);

                    Log::info('StarsEfar payment reseller reactivation completed', [
                        'action' => 'starsefar_reseller_reactivation_complete',
                        'reseller_id' => $reseller->id,
                        'user_id' => $user->id,
                        'configs_enabled' => $reenableStats['enabled'],
                        'configs_failed' => $reenableStats['failed'],
                    ]);
                }
            }
        });
    }
}
