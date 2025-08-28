<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index()
    {
        // Placeholder page to choose plan or usage billing (non-enforced)
        return view('billing.index');
    }

    public function checkout(Request $request)
    {
        // Placeholder: here you'd create Stripe Checkout or PaymentIntent
        // For now, just simulate success and redirect back.
        return back()->with('status', 'Checkout placeholder – not enforced.');
    }
}
