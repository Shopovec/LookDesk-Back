<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Subscription;
use App\Models\PlanPrice;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Customer;
use Stripe\PaymentMethod;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;
use Stripe\PaymentIntent;
use Stripe\Charge;

class StripeWebhookController extends Controller
{
    private function resolvePaymentMethodIdFromInvoice($invoice): ?string
    {
    // 1) Через PaymentIntent (лучший способ)
        $piId = $invoice->payment_intent ?? null;
        if ($piId) {
            try {
                $pi = PaymentIntent::retrieve($piId);
                $pm = $pi->payment_method ?? null;

                if (is_string($pm) && $pm !== '') return $pm;
                if (is_object($pm) && !empty($pm->id)) return $pm->id;
            } catch (\Throwable $e) {
            // ignore
            }
        }

    // 2) Иногда есть charge
        $chId = $invoice->charge ?? null;
        if ($chId) {
            try {
                $ch = Charge::retrieve($chId);

            // у charge может не быть payment_method id, но есть last4 в payment_method_details
            // поэтому этот вариант лучше использовать как fallback через customer default PM
            } catch (\Throwable $e) {
            // ignore
            }
        }

    // 3) Fallback: customer.invoice_settings.default_payment_method
        $stripeCustomerId = $invoice->customer ?? null;
        if ($stripeCustomerId) {
            return $this->resolveDefaultPaymentMethodId($stripeCustomerId);
        }

        return null;
    }

    public function webhook(Request $request)
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $payload        = $request->getContent();
        $sigHeader      = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (\UnexpectedValueException $e) {
            return response('Invalid payload', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return response('Invalid signature', 400);
        }

        // Логи лучше оставить (можно выключить через env)
        if (config('app.debug')) {
            Log::info('stripe_webhook', [
                'type' => $event->type,
                'id'   => $event->id,
            ]);
        }

        switch ($event->type) {

            /* ===================== SUBSCRIPTIONS ===================== */

            case 'customer.subscription.created':
            $this->handleSubscriptionCreated($event->data->object);
            break;

            case 'customer.subscription.updated':
            $this->handleSubscriptionUpdated($event->data->object);
            break;

            case 'customer.subscription.deleted':
            $this->handleSubscriptionDeleted($event->data->object);
            break;

            /* ===================== CHECKOUT (optional) ===================== */
            // Если ты реально создаёшь юзера тут — включай
            case 'checkout.session.completed':
            $this->handleCheckoutCompleted($event->data->object);
            break;

            /* ===================== PAYMENTS HISTORY ===================== */

            // ✅ История ежемесячных оплат (успешно)
            case 'invoice.payment_succeeded':
            $this->handleInvoicePaid($event->data->object);
            break;

            // ❌ (опционально) история неуспешных оплат
            case 'invoice.payment_failed':
            $this->handleInvoiceFailed($event->data->object);
            break;

            // ✅ (опционально) если хочешь обновлять last4 когда карту “прикрепили”
            case 'payment_method.attached':
            $this->handlePaymentMethodAttached($event->data->object);
            break;

            default:
            break;
        }

        return response()->json(['received' => true]);
    }

    /* =====================================================
     | SUBSCRIPTION CREATED
     ===================================================== */

     private function handleSubscriptionCreated($stripeSub): void
     {
        // защита от дублей
        if (Subscription::where('stripe_subscription_id', $stripeSub->id)->exists()) {
            // даже если подписка уже есть — можно обновить payment method у юзера
            $this->trySyncUserPaymentMethodFromSubscription($stripeSub);
            return;
        }

        $stripeCustomerId = $stripeSub->customer ?? null;
        if (!$stripeCustomerId) return;

        $user = User::where('stripe_customer_id', $stripeCustomerId)->first();
        if (!$user) {
            // user может быть создан позже через checkout.session.completed
            return;
        }

        $item = $stripeSub->items->data[0] ?? null;
        if (!$item || empty($item->price->id)) return;

        $planPrice = PlanPrice::where('stripe_price_id', $item->price->id)->first();
        if (!$planPrice) return;

        Subscription::create([
            'user_id'                 => $user->id,
            'plan_id'                 => $planPrice->plan_id,
            'plan_price_id'           => $planPrice->id,
            'stripe_subscription_id'  => $stripeSub->id,
            'status'                  => $stripeSub->status ?? null,
            'quantity'                => $item->quantity ?? 1,
            'current_period_end'      => !empty($stripeSub->current_period_end)
            ? date('Y-m-d H:i:s', $stripeSub->current_period_end)
            : null,
        ]);

        // ✅ сохраним last4/brand/exp
        $this->trySyncUserPaymentMethodFromSubscription($stripeSub, $user);
    }

    /* =====================================================
     | CHECKOUT COMPLETED (создание юзера + подписки)
     ===================================================== */

     private function handleCheckoutCompleted($session): void
     {
        if (($session->mode ?? null) !== 'subscription') return;

        $stripeCustomerId     = $session->customer ?? null;
        $stripeSubscriptionId = $session->subscription ?? null;

        if (!$stripeCustomerId || !$stripeSubscriptionId) return;

        $customer = Customer::retrieve($stripeCustomerId);
        $email = $customer->email ?? null;
        if (!$email) return;

        $user = User::where('stripe_customer_id', $stripeCustomerId)
        ->orWhere('email', $email)
        ->first();

        $generatedPassword = null;

        if (!$user) {
            $generatedPassword = Str::random(10);

            $user = User::create([
                'name'               => $customer->name ?? $email,
                'email'              => $email,
                'password'           => Hash::make($generatedPassword),
                'stripe_customer_id' => $stripeCustomerId,
            ]);

            $user->markEmailAsVerified();
            $user->role_id = 4; // user
            $user->save();
        } else {
            if (!$user->stripe_customer_id) {
                $user->stripe_customer_id = $stripeCustomerId;
                $user->save();
            }
        }

        $stripeSub = StripeSubscription::retrieve($stripeSubscriptionId);

        $item = $stripeSub->items->data[0] ?? null;
        if (!$item || empty($item->price->id)) return;

        $planPrice = PlanPrice::where('stripe_price_id', $item->price->id)->first();
        if (!$planPrice) return;

        Subscription::updateOrCreate(
            ['stripe_subscription_id' => $stripeSub->id],
            [
                'user_id'            => $user->id,
                'plan_id'            => $planPrice->plan_id,
                'plan_price_id'      => $planPrice->id,
                'status'             => $stripeSub->status ?? null,
                'quantity'           => $item->quantity ?? 1,
                'current_period_end' => !empty($stripeSub->current_period_end)
                ? date('Y-m-d H:i:s', $stripeSub->current_period_end)
                : null,
            ]
        );

        // ✅ сохраним last4/brand/exp сразу после чекаута
        $this->trySyncUserPaymentMethodFromSubscription($stripeSub, $user);

        // пароль только новому
        if ($generatedPassword) {
            \Mail::raw(
                "Your account has been created.\n\n".
                "Login: {$email}\n".
                "Password: {$generatedPassword}\n\n".
                "Login here: " . env('FRONTEND_URL'),
                fn ($m) => $m->to($email)->subject('Your LookDesk account')
            );
        }
    }

    /* =====================================================
     | SUBSCRIPTION UPDATED
     ===================================================== */

     private function handleSubscriptionUpdated($stripeSub): void
     {
        $subscription = Subscription::where('stripe_subscription_id', $stripeSub->id)->first();
        if (!$subscription) {
            $this->trySyncUserPaymentMethodFromSubscription($stripeSub);
            return;
        }

        $item = $stripeSub->items->data[0] ?? null;
        if (!$item || empty($item->price->id)) return;

        $planPrice = PlanPrice::where('stripe_price_id', $item->price->id)->first();
        if ($planPrice) {
            $subscription->plan_id       = $planPrice->plan_id;
            $subscription->plan_price_id = $planPrice->id;
        }

        $subscription->status            = $stripeSub->status ?? $subscription->status;
        $subscription->quantity          = $item->quantity ?? $subscription->quantity;
        $subscription->current_period_end = !empty($stripeSub->current_period_end)
        ? date('Y-m-d H:i:s', $stripeSub->current_period_end)
        : $subscription->current_period_end;

        $subscription->save();

        // ✅ обновим last4 если поменяли карту
        $this->trySyncUserPaymentMethodFromSubscription($stripeSub, $subscription->user);
    }

    /* =====================================================
     | SUBSCRIPTION DELETED
     ===================================================== */

     private function handleSubscriptionDeleted($stripeSub): void
     {
        Subscription::where('stripe_subscription_id', $stripeSub->id)
        ->update(['status' => 'canceled']);
    }

    /* =====================================================
     | INVOICE PAID -> история ежемесячных оплат
     ===================================================== */

     private function handleInvoicePaid($invoice): void
     {
        $stripeCustomerId = $invoice->customer ?? null;
        if (!$stripeCustomerId) return;

        $user = User::where('stripe_customer_id', $stripeCustomerId)->first();
        if (!$user) return;

        $stripeSubId = $invoice->subscription ?? null;

        $localSub = null;
        if ($stripeSubId) {
            $localSub = Subscription::where('stripe_subscription_id', $stripeSubId)->first();
        }

        // период по первой линии invoice
        $line = $invoice->lines->data[0] ?? null;
        $pStart = $line->period->start ?? null;
        $pEnd   = $line->period->end ?? null;

        SubscriptionPayment::updateOrCreate(
            ['stripe_invoice_id' => $invoice->id],
            [
                'user_id'                => $user->id,
                'subscription_id'         => $localSub?->id,
                'stripe_subscription_id'  => $stripeSubId,

                'amount_paid'            => (int)($invoice->amount_paid ?? 0),
                'currency'               => $invoice->currency ?? null,
                'status'                 => $invoice->status ?? 'paid',

                'paid_at' => !empty($invoice->status_transitions->paid_at)
                ? date('Y-m-d H:i:s', $invoice->status_transitions->paid_at)
                : now(),

                'period_start' => $pStart ? date('Y-m-d H:i:s', $pStart) : null,
                'period_end'   => $pEnd ? date('Y-m-d H:i:s', $pEnd) : null,

                'hosted_invoice_url' => $invoice->hosted_invoice_url ?? null,
                'invoice_pdf'        => $invoice->invoice_pdf ?? null,

                // raw сохраняем целиком (на будущее)
                'raw' => $invoice,
            ]
        );

        // ✅ после успешной оплаты синкаем last4 по customer default PM
        $pmId = $this->resolvePaymentMethodIdFromInvoice($invoice);
        $this->syncUserPaymentMethod($user, $pmId);
    }

    private function handleInvoiceFailed($invoice): void
    {
        $stripeCustomerId = $invoice->customer ?? null;
        if (!$stripeCustomerId) return;

        $user = User::where('stripe_customer_id', $stripeCustomerId)->first();
        if (!$user) return;

        SubscriptionPayment::updateOrCreate(
            ['stripe_invoice_id' => $invoice->id],
            [
                'user_id'               => $user->id,
                'stripe_subscription_id' => $invoice->subscription ?? null,
                'amount_paid'           => (int)($invoice->amount_paid ?? 0),
                'currency'              => $invoice->currency ?? null,
                'status'                => $invoice->status ?? 'failed',
                'hosted_invoice_url'    => $invoice->hosted_invoice_url ?? null,
                'invoice_pdf'           => $invoice->invoice_pdf ?? null,
                'raw'                   => $invoice,
            ]
        );
    }

    /* =====================================================
     | PAYMENT METHOD ATTACHED (опционально)
     ===================================================== */

     private function handlePaymentMethodAttached($paymentMethod): void
     {
        $stripeCustomerId = $paymentMethod->customer ?? null;
        if (!$stripeCustomerId) return;

        $user = User::where('stripe_customer_id', $stripeCustomerId)->first();
        if (!$user) return;

        // обновим по этому payment method
        $this->syncUserPaymentMethod($user, $paymentMethod->id ?? null);
    }

    /* =====================================================
     | PAYMENT METHOD SYNC HELPERS (last4/brand/exp)
     ===================================================== */

     private function trySyncUserPaymentMethodFromSubscription($stripeSub, ?User $knownUser = null): void
     {
        $stripeCustomerId = $stripeSub->customer ?? null;
        if (!$stripeCustomerId) return;

        $user = $knownUser ?: User::where('stripe_customer_id', $stripeCustomerId)->first();
        if (!$user) return;

        // сначала попробуем subscription.default_payment_method
        $pmId = null;

        $subPm = $stripeSub->default_payment_method ?? null;
        if (is_string($subPm) && $subPm !== '') $pmId = $subPm;
        if (is_object($subPm) && !empty($subPm->id)) $pmId = $subPm->id;

        // если нет — берём customer invoice_settings default
        if (!$pmId) {
            $pmId = $this->resolveDefaultPaymentMethodId($stripeCustomerId);
        }

        $this->syncUserPaymentMethod($user, $pmId);
    }

    private function resolveDefaultPaymentMethodId(string $stripeCustomerId): ?string
    {
        try {
            $customer = Customer::retrieve($stripeCustomerId);
        } catch (\Throwable $e) {
            return null;
        }

        $pm = $customer->invoice_settings->default_payment_method ?? null;

        if (is_string($pm) && $pm !== '') return $pm;
        if (is_object($pm) && !empty($pm->id)) return $pm->id;

        return null;
    }

    private function syncUserPaymentMethod(User $user, ?string $stripePaymentMethodId): void
    {
        if (!$stripePaymentMethodId) return;

        try {
            $pm = PaymentMethod::retrieve($stripePaymentMethodId);
        } catch (\Throwable $e) {
            return;
        }

        $card = $pm->card ?? null;
        if (!$card) return;

        $user->stripe_payment_method_id = $pm->id;
        $user->payment_method_brand     = $card->brand ?? null;
        $user->payment_method_last4     = $card->last4 ?? null;

        $expMonth = $card->exp_month ?? null;
        $expYear  = $card->exp_year ?? null;
        if ($expMonth && $expYear) {
            $user->payment_method_exp =
            str_pad((string)$expMonth, 2, '0', STR_PAD_LEFT) . '/' . $expYear;
        }

        $user->save();
    }
}