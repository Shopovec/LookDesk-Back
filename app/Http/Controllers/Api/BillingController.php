<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlanPrice;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;
use Stripe\Product;
use Stripe\Price as StripePrice;
use Stripe\Customer;
use Stripe\Checkout\Session as CheckoutSession;

class BillingController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /* =========================================================
     | GET CURRENT SUBSCRIPTION
     ========================================================= */
    #[OA\Get(
        path: "/api/billing/subscription",
        summary: "Get current user subscription",
        tags: ["Billing"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(response: 200, description: "Subscription data"),
            new OA\Response(response: 401, description: "Unauthorized")
        ]
    )]
    public function current()
    {
        return $this->success(
            auth()->user()->load([
                'subscription.plan',
                'subscription.plan.features',
                'subscription.plan.prices',
                'subscription.planPrice',
                'payments'
            ])
        );
    }

    /* =========================================================
     | SUBSCRIBE (FIRST TIME)
     ========================================================= */
    #[OA\Post(
        path: "/api/billing/subscribe",
        summary: "Create Stripe subscription",
        tags: ["Billing"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                example: [
                    "plan_price_id" => 1,
                    "quantity" => 1
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Checkout URL returned"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function subscribe(Request $request)
{
    $request->validate([
        'plan_price_id' => 'required|exists:plan_prices,id',
        'quantity' => 'integer|min:1'
    ]);

    $user  = auth()->user();
    $price = PlanPrice::with('plan')->findOrFail($request->plan_price_id);

    Stripe::setApiKey(config('services.stripe.secret'));

    /* =====================================================
     | 1. ENSURE STRIPE CUSTOMER
     ===================================================== */
    if (!$user->stripe_customer_id) {
        $customer = Customer::create([
            'email' => $user->email,
            'name'  => $user->name,
        ]);
        $user->update(['stripe_customer_id' => $customer->id]);
    }

    /* =====================================================
     | 2. ENSURE STRIPE PRODUCT
     ===================================================== */
    if (!$price->stripe_product_id) {
        $product = Product::create([
            'name' => $price->plan->name . ' (' . $price->period . ')',
            'metadata' => [
                'plan_id' => $price->plan_id,
                'plan_price_id' => $price->id,
            ],
        ]);

        $price->stripe_product_id = $product->id;
        $price->save();
    }

    /* =====================================================
     | 3. ENSURE STRIPE PRICE
     ===================================================== */
    if (!$price->stripe_price_id) {
        $stripePrice = StripePrice::create([
            'product' => $price->stripe_product_id,
            'currency' => $price->currency ?? 'usd',
            'unit_amount' => (int) ($price->price * 100),
            'recurring' => [
                'interval' => $price->period === 'monthly' ? 'month' : 'year',
            ],
            'metadata' => [
                'plan_price_id' => $price->id,
            ],
        ]);

        $price->stripe_price_id = $stripePrice->id;
        $price->save();
    }

    /* =====================================================
     | 4. CREATE CHECKOUT SESSION
     ===================================================== */
    $session = CheckoutSession::create([
        'mode' => 'subscription',
        'customer' => $user->stripe_customer_id,
        'line_items' => [[
            'price' => $price->stripe_price_id,
            'quantity' => $request->quantity ?? 1,
        ]],
        'subscription_data' => [
            'trial_period_days' => $price->trial_days ?: null,
        ],
        'success_url' => env('FRONTEND_URL'),
        'cancel_url'  => env('FRONTEND_URL'),
    ]);

    return $this->success([
        'checkout_url' => $session->url
    ]);
}

    /* =========================================================
     | CHANGE PLAN (UPGRADE / DOWNGRADE)
     ========================================================= */
    #[OA\Put(
        path: "/api/billing/change-plan",
        summary: "Change subscription plan",
        tags: ["Billing"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                example: [
                    "plan_price_id" => 2,
                    "quantity" => 5
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Plan change requested"),
            new OA\Response(response: 400, description: "No active subscription")
        ]
    )]
    public function changePlan(Request $request)
    {
        $request->validate([
            'plan_price_id' => 'required|exists:plan_prices,id',
            'quantity' => 'integer|min:1'
        ]);

        $user = auth()->user();
        $subscription = $user->subscription;

        if (!$subscription || !$subscription->stripe_subscription_id) {
            return $this->error('No active subscription', 400);
        }

        $newPrice = PlanPrice::findOrFail($request->plan_price_id);

        Stripe::setApiKey(config('services.stripe.secret'));

        $stripeSub = StripeSubscription::retrieve(
            $subscription->stripe_subscription_id
        );

        StripeSubscription::update(
            $subscription->stripe_subscription_id,
            [
                'items' => [[
                    'id' => $stripeSub->items->data[0]->id,
                    'price' => $newPrice->stripe_price_id,
                    'quantity' => $request->quantity ?? $subscription->quantity,
                ]],
                'proration_behavior' => 'create_prorations',
            ]
        );

        // БД обновится через webhook
        return $this->success(null, 'Plan change requested');
    }

    /* =========================================================
     | CANCEL SUBSCRIPTION
     ========================================================= */
    #[OA\Post(
        path: "/api/billing/cancel",
        summary: "Cancel subscription",
        tags: ["Billing"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(response: 200, description: "Canceled"),
            new OA\Response(response: 400, description: "No subscription")
        ]
    )]
    public function cancel()
    {
        $user = auth()->user();
        $subscription = $user->subscription;

        if (!$subscription || !$subscription->stripe_subscription_id) {
            return $this->error('No active subscription', 400);
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        StripeSubscription::update(
            $subscription->stripe_subscription_id,
            ['cancel_at_period_end' => true]
        );

        return $this->success(null, 'Subscription will be canceled');
    }
}