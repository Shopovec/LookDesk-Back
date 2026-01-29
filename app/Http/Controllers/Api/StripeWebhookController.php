<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Subscription;
use App\Models\PlanPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Stripe\Webhook;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Subscription as StripeSubscription;
use Symfony\Component\HttpFoundation\Response;

class StripeWebhookController extends Controller
{
    public function webhook(Request $request)
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $endpointSecret
            );
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            return response('Invalid payload', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            return response('Invalid signature', 400);
        }

        /* =====================================================
         | HANDLE EVENTS
         ===================================================== */
        switch ($event->type) {

           case 'customer.subscription.created':
    $this->handleSubscriptionCreated($event->data->object);
    break;

            /**
             * 🔹 Checkout завершён (subscription создана)
             */
            /*case 'checkout.session.completed':
                $this->handleCheckoutCompleted($event->data->object);
                break;
*/
            /**
             * 🔹 Подписка обновлена (смена тарифа, qty)
             */
            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($event->data->object);
                break;

            /**
             * 🔹 Подписка отменена
             */
            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($event->data->object);
                break;

            default:
                // ignore
                break;
        }

        return response()->json(['received' => true]);
    }

    private function handleSubscriptionCreated(StripeSubscription $stripeSub)
{
    // защита от дублей
    $exists = \App\Models\Subscription::where(
        'stripe_subscription_id',
        $stripeSub->id
    )->exists();

    if ($exists) {
        return;
    }

    // customer
    $stripeCustomerId = $stripeSub->customer;

    if (!$stripeCustomerId) {
        return;
    }

    $user = \App\Models\User::where(
        'stripe_customer_id',
        $stripeCustomerId
    )->first();

    if (!$user) {
        // user может быть создан позже через checkout.session.completed
        return;
    }

    // subscription item
    $item = $stripeSub->items->data[0] ?? null;
    if (!$item) {
        return;
    }

    $stripePriceId = $item->price->id;

    $planPrice = \App\Models\PlanPrice::where(
        'stripe_price_id',
        $stripePriceId
    )->first();

    if (!$planPrice) {
        return;
    }

    \App\Models\Subscription::create([
        'user_id' => $user->id,
        'plan_id' => $planPrice->plan_id,
        'plan_price_id' => $planPrice->id,
        'stripe_subscription_id' => $stripeSub->id,
        'status' => $stripeSub->status,
        'quantity' => $item->quantity,
        'current_period_end' => date(
            'Y-m-d H:i:s',
            $stripeSub->current_period_end
        ),
    ]);
}

    /* =====================================================
     | CHECKOUT COMPLETED
     ===================================================== */
    private function handleCheckoutCompleted($session)
    {
        // Нам важны ТОЛЬКО подписки
        if ($session->mode !== 'subscription') {
            return;
        }

        $stripeCustomerId = $session->customer;
        $stripeSubscriptionId = $session->subscription;

        if (!$stripeCustomerId || !$stripeSubscriptionId) {
            return;
        }

        // 1️⃣ Получаем customer
        $customer = Customer::retrieve($stripeCustomerId);

        $email = $customer->email;
        if (!$email) {
            return;
        }

        // 2️⃣ User (создать или найти)
        $user = User::where('stripe_customer_id', $stripeCustomerId)
            ->orWhere('email', $email)
            ->first();

        $generatedPassword = null;

        if (!$user) {
            $generatedPassword = Str::random(10);

            $user = User::create([
                'name' => $customer->name ?? $email,
                'email' => $email,
                'password' => Hash::make($generatedPassword),
                'stripe_customer_id' => $stripeCustomerId,
            ]);

            $user->markEmailAsVerified();
            $user->role_id = 4; // user
            $user->save();
        } else {
            // гарантируем связь
            if (!$user->stripe_customer_id) {
                $user->stripe_customer_id = $stripeCustomerId;
                $user->save();
            }
        }

        // 3️⃣ Получаем subscription из Stripe
        $stripeSub = StripeSubscription::retrieve($stripeSubscriptionId);

        $item = $stripeSub->items->data[0] ?? null;
        if (!$item) {
            return;
        }

        $stripePriceId = $item->price->id;
        $quantity = $item->quantity;

        $planPrice = PlanPrice::where('stripe_price_id', $stripePriceId)->first();
        if (!$planPrice) {
            return;
        }

        // 4️⃣ Idempotent create/update
        Subscription::updateOrCreate(
            ['stripe_subscription_id' => $stripeSub->id],
            [
                'user_id' => $user->id,
                'plan_id' => $planPrice->plan_id,
                'plan_price_id' => $planPrice->id,
                'status' => $stripeSub->status,
                'quantity' => $quantity,
                'current_period_end' => date(
                    'Y-m-d H:i:s',
                    $stripeSub->current_period_end
                ),
            ]
        );

        // 5️⃣ Отправить пароль ТОЛЬКО новому юзеру
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
    private function handleSubscriptionUpdated($stripeSub)
    {
        $subscription = Subscription::where(
            'stripe_subscription_id',
            $stripeSub->id
        )->first();

        if (!$subscription) {
            return;
        }

        $item = $stripeSub->items->data[0] ?? null;
        if (!$item) {
            return;
        }

        $planPrice = PlanPrice::where(
            'stripe_price_id',
            $item->price->id
        )->first();

        if ($planPrice) {
            $subscription->plan_id = $planPrice->plan_id;
            $subscription->plan_price_id = $planPrice->id;
        }

        $subscription->status = $stripeSub->status;
        $subscription->quantity = $item->quantity;
        $subscription->current_period_end = date(
            'Y-m-d H:i:s',
            $stripeSub->current_period_end
        );

        $subscription->save();
    }

    /* =====================================================
     | SUBSCRIPTION DELETED
     ===================================================== */
    private function handleSubscriptionDeleted($stripeSub)
    {
        Subscription::where(
            'stripe_subscription_id',
            $stripeSub->id
        )->update([
            'status' => 'canceled'
        ]);
    }
}