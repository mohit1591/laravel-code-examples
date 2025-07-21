<?php

namespace App\Http\Controllers\Front;

use App\Traits\InvoiceGeneratorTrait;
use App\Http\Controllers\Controller;
use App\Events\PaymentReferrerBonus;
use App\Services\PaymentPlatformResolverService;
use App\Events\PaymentProcessed;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\PaymentPlatform;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\SubscriptionPlan;
use App\Models\PrepaidPlan;
use App\Models\Customer;
use Carbon\Carbon;

class PaymentController extends Controller
{
    use InvoiceGeneratorTrait;

    protected $paymentPlatformResolver;

    public function __construct(PaymentPlatformResolverService $paymentPlatformResolver)
    {
        $this->paymentPlatformResolver = $paymentPlatformResolver;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function pay(Request $request, SubscriptionPlan $id)
    {
        if ($id->free) {
            $order_id = $this->registerFreeSubscription($id);
            $plan = SubscriptionPlan::where('id', $id->id)->first();
            return view('front.plans.success', compact('plan', 'order_id'));
        } else {
            $rules = [
                'payment_platform' => ['required', 'exists:payment_platforms,id'],
            ];
            $request->validate($rules);
            $paymentPlatform = $this->paymentPlatformResolver->resolveService($request->payment_platform);
            session()->put('subscriptionPlatformID', $request->payment_platform);
            session()->put('gatewayID', $request->payment_platform);

            return $paymentPlatform->handlePaymentSubscription($request, $id);
        }
    }

    /**
     * Process prepaid plan request
     */
    public function payPrePaid(Request $request, PrepaidPlan $id)
    {
        $rules = [
            'payment_platform' => ['required', 'exists:payment_platforms,id'],
        ];

        $request->validate($rules);

        $paymentPlatform = $this->paymentPlatformResolver->resolveService($request->payment_platform);

        session()->put('paymentPlatformID', $request->payment_platform);

        return $paymentPlatform->handlePaymentPrePaid($request, $id);
    }

    /**
     * Process approved prepaid plan requests
     */
    public function approved(Request $request)
    {
        if (session()->has('paymentPlatformID')) {
            $paymentPlatform = $this->paymentPlatformResolver->resolveService(session()->get('paymentPlatformID'));

            return $paymentPlatform->handleApproval($request);
        }

        toastr()->error(__('There was an error while retrieving payment gateway. Please try again'));
        return redirect()->back();
    }

    /**
     * Process cancelled prepaid plan requests
     */
    public function cancelled()
    {
        toastr()->warning(__('You cancelled the payment process. Would like to try again?'));
        return redirect()->route('front.plans');
    }

    /**
     * Process approved subscription plan requests
     */
    public function approvedSubscription(Request $request)
    {
        if (session()->has('subscriptionPlatformID')) {
            $paymentPlatform = $this->paymentPlatformResolver->resolveService(session()->get('subscriptionPlatformID'));

            if (session()->has('subscriptionID')) {
                $subscriptionID = session()->get('subscriptionID');
            }

            if ($paymentPlatform->validateSubscriptions($request)) {
                $plan = SubscriptionPlan::where('id', $request->plan_id)->firstOrFail();
                $user = $request->user();

                $gateway_id = session()->get('gatewayID');
                $gateway = PaymentPlatform::where('id', $gateway_id)->firstOrFail();
                $duration = $plan->payment_interval;
                $days = ($duration == 'monthly') ? 30 : 365;

                $subscription = Subscriber::updateOrCreate(['customer_id'=>$user->customer_id],[
                    'customer_id' => $user->customer_id,
                    'plan_id' => $plan->id,
                    'status' => 'Active',
                    'created_at' => now(),
                    'gateway' => $gateway->name,
                    'frequency' => $plan->payment_interval,
                    'plan_name' => $plan->name,
                    'words' => $plan->words,
                    'images' => $plan->images,
                    'speechtotext' => $plan->speechtotext,
                    'users' => $plan->users,
                    'subscription_id' => $subscriptionID,
                    'active_until' => Carbon::now()->addDays($days),
                ]);

                session()->forget('gatewayID');

                $this->registerSubscriptionPayment($plan, $user->customer, $subscriptionID, $gateway->name);
                $order_id = $subscriptionID;

                return view('front.plans.success', compact('plan', 'order_id'));
            }
        }

        toastr()->error(__('There was an error while checking your subscription. Please try again'));
        return redirect()->back();
    }

    /**
     * Process cancelled subscription plan requests
     */
    public function cancelledSubscription()
    {
        toastr()->warning(__('You cancelled the payment process. Would like to try again?'));
        return redirect()->route('front.plans');
    }

    /**
     * Register subscription payment in DB
     */
    private function registerSubscriptionPayment(SubscriptionPlan $plan, Customer $customer, $subscriptionID, $gateway)
    {
        $tax_value = (config('payment.payment_tax') > 0) ? $plan->price * config('payment.payment_tax') / 100 : 0;
        $total_price = $tax_value + $plan->price;

        if (config('payment.referral.enabled') == 'on') {
            if (config('payment.referral.payment.policy') == 'first') {
                if (Payment::where('customer_id', $customer->id)->where('status', 'completed')->exists()) {
                    /** User already has at least 1 payment */
                } else {
                    event(new PaymentReferrerBonus($customer, $subscriptionID, $total_price, $gateway));
                }
            } else {
                event(new PaymentReferrerBonus($customer, $subscriptionID, $total_price, $gateway));
            }
        }

        $record_payment = new Payment();
        $record_payment->customer_id = $customer->id;
        $record_payment->plan_id = $plan->id;
        $record_payment->order_id = $subscriptionID;
        $record_payment->plan_name = $plan->name;
        $record_payment->frequency = $plan->payment_interval;
        $record_payment->price = $total_price;
        $record_payment->gateway = $gateway;
        $record_payment->status = 'completed';
        $record_payment->words = $plan->words;
        $record_payment->speechtotext = $plan->speechtotext;
        $record_payment->images = $plan->images;
        $record_payment->users = $plan->users;
        $record_payment->save();

        $customer->plan_id = $plan->id;
        $customer->total_words = $plan->words;
        $customer->total_images = $plan->images;
        $customer->total_speechtotext = $plan->speechtotext;
        $customer->available_words = $plan->words;
        $customer->available_images = $plan->images;
        $customer->available_speechtotext = $plan->speechtotext;
        $customer->save();

        event(new PaymentProcessed($customer));
    }

    /**
     * Generate Invoice after payment
     */
    public function generatePaymentInvoice($order_id)
    {
        $this->generateInvoice($order_id);
    }

    /**
     * Show invoice for past payments
     */
    public function showPaymentInvoice(Payment $id)
    {
        $this->showInvoice($id);
    }

    /**
     * Cancel active subscription
     */
    public function stopSubscription(Request $request)
    {
        if ($request->ajax()) {
            $id = Subscriber::where('id', $request->id)->first();

            if ($id->status == 'Cancelled') {
                $data['status'] = 200;
                $data['message'] = __('This subscription was already cancelled before');
                return $data;
            } elseif ($id->status == 'Suspended') {
                $data['status'] = 400;
                $data['message'] = __('Subscription has been suspended due to failed renewal payment');
                return $data;
            } elseif ($id->status == 'Expired') {
                $data['status'] = 400;
                $data['message'] = __('Subscription has been expired, please create a new one');
                return $data;
            }

            switch ($id->gateway) {
                case 'Stripe':
                    $platformID = 1;
                    break;
                case 'PayPal':
                    $platformID = 2;
                    break;
                case 'Mollie':
                    $platformID = 3;
                    break;
                case 'FREE':
                    $platformID = 99;
                    break;
                default:
                    $platformID = 1;
                    break;
            }

            if ($id->gateway == 'PayPal' || $id->gateway == 'Stripe' || $id->gateway == 'Mollie' || $id->gateway == 'FREE') {
                if($platformID!=99){
                    $paymentPlatform = $this->paymentPlatformResolver->resolveService($platformID);
                    $status = $paymentPlatform->stopSubscription($id->subscription_id);
                }
                if ($platformID == 1) {
                    if ($status->cancel_at) {
                        $id->update(['status'=>'Cancelled', 'active_until' => Carbon::createFromFormat('Y-m-d H:i:s', now())]);
                        $customer = Customer::where('id', $id->customer_id)->firstOrFail();
                        $customer->plan_id = null;
                        $customer->total_words= 0;
                        $customer->total_images= 0;
                        $customer->total_speechtotext = 0;
                        $customer->save();
                    }
                } elseif ($platformID == 3) {
                    if ($status->status == 'Canceled') {
                        $id->update(['status'=>'Cancelled', 'active_until' => Carbon::createFromFormat('Y-m-d H:i:s', now())]);
                        $customer = Customer::where('id', $id->customer_id)->firstOrFail();
                        $customer->plan_id = null;
                        $customer->total_words= 0;
                        $customer->total_images= 0;
                        $customer->total_speechtotext = 0;
                        $customer->save();
                    }
                } elseif ($platformID == 99) {
                    $id->update(['status'=>'Cancelled', 'active_until' => Carbon::createFromFormat('Y-m-d H:i:s', now())]);
                    $customer = Customer::where('id', $id->customer_id)->firstOrFail();
                    $customer->plan_id = null;
                    $customer->total_words= 0;
                    $customer->total_images= 0;
                    $customer->total_speechtotext = 0;
                    $customer->save();
                } else {
                    if (is_null($status)) {
                        $id->update(['status'=>'Cancelled', 'active_until' => Carbon::createFromFormat('Y-m-d H:i:s', now())]);
                        $customer = Customer::where('id', $id->customer_id)->firstOrFail();
                        $customer->plan_id = null;
                        $customer->total_words= 0;
                        $customer->total_images= 0;
                        $customer->total_speechtotext = 0;
                        $customer->save();
                    }
                }
            } else {
                $id->update(['status'=>'Cancelled', 'active_until' => Carbon::createFromFormat('Y-m-d H:i:s', now())]);
                $customer = Customer::where('id', $id->customer_id)->firstOrFail();
                $customer->plan_id = null;
                $customer->total_words= 0;
                $customer->total_images= 0;
                $customer->total_speechtotext = 0;
                $customer->save();
            }

            $data['status'] = 200;
            $data['message'] = __('Subscription has been successfully cancelled');
            return $data;
        }
    }

    /**
     * Register free subscription
     */
    private function registerFreeSubscription(SubscriptionPlan $plan)
    {
        $order_id = Str::random(10);
        $subscription = Str::random(10);
        $duration = $plan->payment_interval;
        $days = ($duration == 'monthly') ? 30 : 365;

        $record_payment = new Payment();
        $record_payment->customer_id = auth()->user()->customer_id;
        $record_payment->plan_id = $plan->id;
        $record_payment->frequency = $plan->payment_interval;
        $record_payment->order_id = $order_id;
        $record_payment->plan_name = $plan->name;
        $record_payment->price = 0;
        $record_payment->gateway = 'FREE';
        $record_payment->status = 'completed';
        $record_payment->words = $plan->words;
        $record_payment->speechtotext = $plan->speechtotext;
        $record_payment->images = $plan->images;
        $record_payment->users = $plan->users;
        $record_payment->save();

        $subscription = Subscriber::create([
            'customer_id' => auth()->user()->customer_id,
            'plan_id' => $plan->id,
            'status' => 'Active',
            'created_at' => now(),
            'gateway' => 'FREE',
            'frequency' => $plan->payment_interval,
            'plan_name' => $plan->name,
            'words' => $plan->words,
            'images' => $plan->images,
            'speechtotext' => $plan->speechtotext,
            'users' => $plan->users,
            'subscription_id' => $subscription,
            'active_until' => Carbon::now()->addDays($days),
        ]);

        $customer = Customer::where('id', auth()->user()->customer_id)->first();
        $customer->plan_id = $plan->id;
        $customer->total_words = $plan->words;
        $customer->total_images = $plan->images;
        $customer->total_speechtotext = $plan->speechtotext;
        $customer->available_words = $plan->words;
        $customer->available_images = $plan->images;
        $customer->available_speechtotext = $plan->speechtotext;
        $customer->save();

        return $order_id;
    }
}
