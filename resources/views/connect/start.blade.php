@extends('connect.layout')
@section('title', 'Set up payouts')
@section('content')

<h1>Set up your payouts</h1>
<p>So {{ $merchant->business_name }} can pay you, you need an account with Stripe, who handle the money.</p>

@if(!empty($problem))
  <div class="err">{{ $problem }}</div>
@endif

@if($errors->any())
  <div class="err">{{ $errors->first() }}</div>
@endif

<ul class="steps">
  <li>Give us your email and country</li>
  <li>Stripe asks for your details and bank account</li>
  <li>You are paid as agreed with {{ $merchant->business_name }}</li>
</ul>

<form method="POST" action="{{ route('connect.submit', $token) }}">
  @csrf

  <div class="field">
    <label for="email">Your email address</label>
    <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email" placeholder="you@yourbusiness.com">
    <div class="hint">Stripe uses this to contact you about your account.</div>
  </div>

  <div class="field">
    <label for="country">Where is your business based?</label>
    <select id="country" name="country" required>
      @foreach($countries as $code => $name)
        <option value="{{ $code }}" {{ old('country') === $code ? 'selected' : '' }}>{{ $name }}</option>
      @endforeach
    </select>
    <div class="hint">This cannot be changed later, so pick the country your bank account is in.</div>
  </div>

  <button type="submit">Continue to Stripe</button>
</form>

<div class="foot">
  Stripe collects your identity and bank details directly. {{ $merchant->business_name }} never sees them.
</div>

@endsection
