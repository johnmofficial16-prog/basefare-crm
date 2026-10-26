<?php
// Sign-in look shared by the sign-in page and "choose your password" (Peacock / Ivory).
?>
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; min-height: 100%; }
  body {
    min-height: 100vh; display: flex; align-items: center; padding: 32px 16px;
    font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; color: #E6ECF7; letter-spacing: -0.006em;
    background: #05070D url('<?= \App\Services\Asset::url('assets/img/login-peacock.jpg') ?>') right center / cover no-repeat;
  }
  .card {
    width: 100%; max-width: 460px; margin: 0 auto; padding: 40px 36px;
    border-radius: 22px; background: rgba(8, 12, 22, 0.9); border: 1px solid #1E2A42;
    box-shadow: 0 30px 80px -20px rgba(0, 0, 0, 0.7); display: flex; flex-direction: column; gap: 20px;
  }
  @media (min-width: 1100px) { .card { margin: 0 0 0 120px; padding: 44px; } }
  .brand { display: flex; align-items: center; gap: 14px; }
  .brand img { width: 52px; height: 52px; border-radius: 14px; display: block; }
  .brand b { display: block; font-family: 'Source Serif 4', Georgia, serif; font-size: 23px; font-weight: 600; color: #FFFFFF; }
  .brand span { display: block; font-size: 14px; color: #8FA0BF; }
  h1 { margin: 6px 0 0; font-family: 'Source Serif 4', Georgia, serif; font-size: 34px; font-weight: 600; letter-spacing: -0.015em; color: #FFFFFF; }
  .lead { margin: 6px 0 0; font-size: 16px; color: #A9B6CF; }
  .err { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 12px;
    background: #3A1620; border: 1px solid #5C2433; color: #FECDD3; font-size: 15px; font-weight: 600; }
  form { display: flex; flex-direction: column; gap: 18px; margin: 0; }
  label { display: flex; flex-direction: column; gap: 8px; font-size: 15px; font-weight: 600; color: #D7E0F0; }
  .field { position: relative; }
  input[type=email], input[type=password], input[type=text] {
    width: 100%; height: 52px; padding: 0 16px; border-radius: 12px; border: 1px solid #2A3756;
    background: #0F1626; color: #FFFFFF; font: inherit; font-size: 16px; font-weight: 500;
  }
  input::placeholder { color: #7383A3; }
  input:focus { outline: none; border-color: #4D8DF5; box-shadow: 0 0 0 3px rgba(31, 111, 235, 0.35); }
  .field input { padding-right: 52px; }
  .eye { position: absolute; right: 6px; top: 6px; width: 40px; height: 40px; border: 0; border-radius: 10px;
    background: transparent; color: #8FA0BF; display: flex; align-items: center; justify-content: center; cursor: pointer; }
  .eye:hover { color: #FFFFFF; background: #18223A; }
  .eye:focus-visible { outline: 2px solid #4D8DF5; }
  button.go { height: 54px; border: 0; border-radius: 12px; color: #FFFFFF; cursor: pointer; margin-top: 4px;
    background: linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,0) 52%), linear-gradient(180deg, #1C7398, #155E7D);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.22), 0 8px 20px -10px rgba(21,94,125,.8);
    font: inherit; font-size: 17px; font-weight: 600; }
  button.go:hover { filter: brightness(1.08); }
  button.go:focus-visible { outline: 3px solid #9CC3FF; outline-offset: 2px; }
  .help { margin: 0; font-size: 14px; color: #8FA0BF; text-align: center; line-height: 1.5; }
  .foot { margin: 0; font-size: 13px; color: #6F7E9C; text-align: center; }
</style>
