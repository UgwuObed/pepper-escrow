<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Set up payouts') — {{ $merchant->business_name }}</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
<style>
  :root {
    --ink: #16191d; --ink-soft: #4a5159; --ink-faint: #767d86;
    --paper: #f7f6f3; --surface: #ffffff; --rule: #e2ded7;
    --accent: #1f5d50; --accent-ink: #ffffff; --accent-soft: #e6efec;
    --bad: #9c3b24; --bad-soft: #fbeae5;
    --good: #1f5d50; --good-soft: #e6efec;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --ink: #e8e6e1; --ink-soft: #a9a59d; --ink-faint: #7d7973;
      --paper: #16181a; --surface: #1d2023; --rule: #32363a;
      --accent: #6fbfa8; --accent-ink: #10211d; --accent-soft: #1b2e2a;
      --bad: #e08a6e; --bad-soft: #32211c;
      --good: #6fbfa8; --good-soft: #1b2e2a;
      color-scheme: dark;
    }
  }
  * { box-sizing: border-box; }
  body {
    background: var(--paper); color: var(--ink);
    font-family: "IBM Plex Sans", system-ui, -apple-system, sans-serif;
    font-size: 16px; line-height: 1.6; margin: 0;
    display: flex; align-items: center; justify-content: center; min-height: 100vh;
    padding: 24px 16px;
  }
  .card {
    background: var(--surface); border: 1px solid var(--rule); border-radius: 10px;
    width: 100%; max-width: 440px; padding: 32px 30px;
  }
  .who { font-size: 13px; color: var(--ink-faint); margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--rule); }
  .who strong { color: var(--ink); font-weight: 600; }
  h1 { font-size: 23px; font-weight: 600; letter-spacing: -0.015em; margin: 0 0 8px; line-height: 1.25; }
  p { margin: 0 0 16px; color: var(--ink-soft); font-size: 15px; }
  p.tight { margin-bottom: 8px; }
  label { display: block; font-size: 13.5px; font-weight: 600; margin-bottom: 5px; color: var(--ink); }
  input[type=email], select {
    width: 100%; font: inherit; font-size: 15px; padding: 10px 12px;
    border: 1px solid var(--rule); border-radius: 6px;
    background: var(--paper); color: var(--ink); margin-bottom: 4px;
  }
  input[type=email]:focus, select:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
  .field { margin-bottom: 18px; }
  .hint { font-size: 12.5px; color: var(--ink-faint); }
  button {
    width: 100%; font: inherit; font-size: 15px; font-weight: 600;
    background: var(--accent); color: var(--accent-ink);
    border: 0; border-radius: 6px; padding: 12px; cursor: pointer; margin-top: 4px;
  }
  button:hover { opacity: 0.92; }
  button:focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }
  .err { background: var(--bad-soft); color: var(--bad); border-radius: 6px; padding: 10px 13px; font-size: 14px; margin-bottom: 18px; }
  .ok { background: var(--good-soft); color: var(--good); border-radius: 6px; padding: 10px 13px; font-size: 14px; margin-bottom: 18px; }
  .steps { list-style: none; padding: 0; margin: 0 0 20px; font-size: 14px; color: var(--ink-soft); }
  .steps li { padding-left: 24px; position: relative; margin-bottom: 7px; }
  .steps li::before { content: "→"; position: absolute; left: 0; color: var(--accent); }
  .foot { margin-top: 22px; padding-top: 16px; border-top: 1px solid var(--rule); font-size: 12.5px; color: var(--ink-faint); }
</style>
</head>
<body>
  <div class="card">
    <div class="who">Payouts for <strong>{{ $merchant->business_name }}</strong></div>
    @yield('content')
  </div>
</body>
</html>
