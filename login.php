<?php
require_once __DIR__ . '/config/auth.php';

if (isset($_GET['logout'])) {
    logoutAppUser();
    header('Location: login.php');
    exit;
}

if (isAppAuthenticated()) {
    header('Location: index.php');
    exit;
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $passcode = trim((string) ($_POST['passcode'] ?? ''));

    if ($passcode === APP_LOGIN_PASSCODE) {
        loginAppUser();
        header('Location: index.php');
        exit;
    }

    $errorMessage = 'Incorrect 4-digit password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Mail Hub Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@500;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<style>
:root{
  --bg:#07111f;--surface:#0e1a2f;--surface-2:#12223c;--border:rgba(255,255,255,.1);
  --text:#e8eef9;--muted:#8ea3c7;--accent:#32a0ff;--accent-2:#7c5cff;--danger:#ff6b6b;
  --gradient:linear-gradient(135deg,#32a0ff,#7c5cff);
}
*{box-sizing:border-box}
body{
  margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;overflow:hidden;
  font-family:'Plus Jakarta Sans',sans-serif;color:var(--text);background:
  radial-gradient(circle at top right,rgba(50,160,255,.2),transparent 28%),
  radial-gradient(circle at bottom left,rgba(124,92,255,.18),transparent 30%),
  var(--bg);
}
.shell{
  width:min(100%,940px);display:grid;grid-template-columns:1.05fr .95fr;border:1px solid var(--border);
  background:rgba(11,20,36,.88);backdrop-filter:blur(24px);border-radius:28px;overflow:hidden;
  box-shadow:0 30px 80px rgba(0,0,0,.35);
}
.hero{
  padding:52px 46px;background:
  linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,0)),
  linear-gradient(145deg,#0f1f36,#0a1423);
  position:relative;
}
.hero::after{
  content:'';position:absolute;inset:auto -80px -80px auto;width:220px;height:220px;border-radius:50%;
  background:rgba(50,160,255,.15);filter:blur(10px);
}
.badge{
  display:inline-flex;align-items:center;gap:10px;padding:10px 14px;border-radius:999px;
  background:rgba(50,160,255,.1);border:1px solid rgba(50,160,255,.18);color:#bcdcff;
  font-size:.8rem;font-weight:700;letter-spacing:.4px;text-transform:uppercase;
}
.logo{
  width:48px;height:48px;margin-top:24px;border-radius:14px;background:var(--gradient);
  display:grid;place-items:center;font-size:1.15rem;font-weight:800;box-shadow:0 18px 38px rgba(50,160,255,.22);
}
h1{
  margin:22px 0 12px;font-family:'Syne',sans-serif;font-size:clamp(2.2rem,5vw,3.6rem);
  line-height:.98;letter-spacing:-1.6px;
}
.hero p{
  max-width:420px;margin:0;color:var(--muted);font-size:1rem;line-height:1.75;
}
.meta{
  display:grid;gap:14px;margin-top:34px;
}
.meta-card{
  padding:16px 18px;border-radius:18px;background:rgba(255,255,255,.03);border:1px solid var(--border);
}
.meta-card strong{
  display:block;margin-bottom:6px;font-size:.8rem;letter-spacing:.8px;text-transform:uppercase;color:#caddff;
}
.meta-card span{
  color:var(--muted);font-size:.95rem;
}
.panel{
  padding:52px 40px;display:flex;align-items:center;justify-content:center;background:var(--surface);
}
.card{
  width:min(100%,360px);
}
.eyebrow{
  color:#93b3df;font-size:.78rem;font-weight:700;letter-spacing:.28em;text-transform:uppercase;
}
.title{
  margin:12px 0 8px;font-family:'Syne',sans-serif;font-size:2rem;letter-spacing:-.8px;
}
.sub{
  margin:0 0 28px;color:var(--muted);line-height:1.7;font-size:.95rem;
}
.field-label{
  display:block;margin-bottom:10px;font-size:.88rem;font-weight:700;color:#d5e4ff;
}
.field{
  width:100%;padding:18px 20px;border-radius:18px;border:1px solid var(--border);outline:none;
  background:var(--surface-2);color:var(--text);font-size:1.55rem;letter-spacing:.55em;text-align:center;
  font-weight:700;
}
.field:focus{
  border-color:rgba(50,160,255,.55);box-shadow:0 0 0 5px rgba(50,160,255,.12);
}
.hint{
  margin:12px 0 0;color:var(--muted);font-size:.84rem;
}
.error{
  margin:16px 0 0;padding:13px 14px;border-radius:14px;background:rgba(255,107,107,.1);
  border:1px solid rgba(255,107,107,.24);color:#ffc2c2;font-size:.88rem;font-weight:600;
}
.btn{
  width:100%;margin-top:22px;padding:16px 18px;border:none;border-radius:18px;cursor:pointer;
  background:var(--gradient);color:#fff;font-size:.98rem;font-weight:800;letter-spacing:.02em;
  box-shadow:0 18px 36px rgba(50,160,255,.18);
}
.foot{
  margin-top:16px;text-align:center;color:var(--muted);font-size:.84rem;
}
@media (max-width: 820px){
  .shell{grid-template-columns:1fr}
  .hero,.panel{padding:34px 24px}
}
</style>
</head>
<body>
  <div class="shell">
    <section class="hero">
      <div class="badge">Secure Access Portal</div>
      <div class="logo">HV</div>
      <h1>Bulk Mail Sender</h1>
      <p>Enter the 4-digit password to open the main dashboard and manage your customer mail campaigns.</p>
      <div class="meta">
        <div class="meta-card">
          <strong>Session Security</strong>
          <span>After login, a PHP session is created and the dashboard stays locked until you log out.</span>
        </div>
        <div class="meta-card">
          <strong>Access Code</strong>
          <span>This login accepts a 4-digit password as requested.</span>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="card">
        <div class="eyebrow">Login</div>
        <h2 class="title">Enter Password</h2>
        <p class="sub">Use the 4-digit access code to continue to the main page.</p>

        <form method="post" action="">
          <label class="field-label" for="passcode">4-Digit Password</label>
          <input
            id="passcode"
            name="passcode"
            class="field"
            type="password"
            inputmode="numeric"
            pattern="[0-9]{4}"
            maxlength="4"
            minlength="4"
            placeholder="••••"
            autocomplete="off"
            required
          />
          <?php if ($errorMessage !== ''): ?>
            <div class="error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php endif; ?>
          <button class="btn" type="submit">Open Main Page</button>
        </form>

        <div class="foot">Session-based access is active for this app.</div>
      </div>
    </section>
  </div>

<script>
document.getElementById('passcode').focus();
</script>
</body>
</html>
