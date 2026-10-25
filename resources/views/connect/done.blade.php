@extends('connect.layout')
@section('title', 'Payout setup')
@section('content')

@if($alreadyComplete)
  <h1>You are already set up</h1>
  <div class="ok">Your account is active and ready to receive payouts.</div>
  <p>Nothing more to do. {{ $merchant->business_name }} can pay you as soon as you have a sale.</p>

@elseif($account->charges_enabled && $account->payouts_enabled)
  <h1>You are all set</h1>
  <div class="ok">Stripe has approved your account.</div>
  <p>You can be paid as soon as {{ $merchant->business_name }} releases a payment to you. Money normally reaches your bank one to two working days after that.</p>

@elseif($account->details_submitted)
  <h1>Thanks — Stripe is reviewing</h1>
  <p>Your details are in and Stripe is checking them. This usually takes a few minutes but can take longer.</p>
  <p class="tight"><strong>Nothing is needed from you right now.</strong> Stripe will email you if they need anything else.</p>

@else
  <h1>Not quite finished</h1>
  <div class="err">Stripe still needs a few things before you can be paid.</div>
  @if(!empty($account->requirements_due))
    <p class="tight">Still outstanding:</p>
    <ul class="steps">
      @foreach(array_slice($account->requirements_due, 0, 6) as $item)
        <li>{{ ucfirst(str_replace(['individual.', 'business_profile.', '_', '.'], ['', '', ' ', ' '], $item)) }}</li>
      @endforeach
    </ul>
  @endif
  <p><a href="{{ route('connect.refresh', [request()->route('token'), $account->account_id]) }}">Pick up where you left off</a></p>
@endif

<div class="foot">
  Questions about a payment? Contact {{ $merchant->business_name }}. Questions about your Stripe account or your bank details? Stripe will have emailed you.
</div>

@endsection
