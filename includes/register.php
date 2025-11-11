<?php // Register
session_start();
require_once("functions.php");
$error = $user = $pass = "";

if (isset($_SESSION['user'])) {
  destroySession();
}

require_once("../includes/register.js"); // Pull in the javascript functions specific to the registration form

// find out if the login.php content has been injected into the page
$isInject = isset($_GET['inject']) && $_GET['inject'] == '1';

// Optional: also respect the common AJAX header if you add it in JS
if (!$isInject && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
	$isInject = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
}

if(!$isInject) {
	require_once __DIR__ . '/header.php';
}

?>

<style>
  /* ===== register.php (scoped) ===== */
  #registerPage{
	--col-pad-y: clamp(1.25rem, 2.5vw, 2.25rem);
	--col-pad-x: clamp(1.25rem, 3vw, 2.75rem);
	max-width: 1200px;
	margin: 0 auto;
	padding: clamp(1rem, 2vw, 2rem) 1rem;
	display: grid;
	grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
	gap: clamp(1.25rem, 3vw, 2.5rem);
	align-items: start;
  }

  /* Avoid double padding from global .padded */
  #registerPage .padded{ padding: 0 !important; }

  /* Left column is its own vertical stack */
  #leftCol{
	grid-column: 1;
	display: grid;
	row-gap: 1.25rem; /* space between pitch and cards */
  }

  /* Right column (form) aligned with pitch */
  #registrationForm{
	grid-column: 2;
	align-self: start;
	position: sticky; top: 1rem; /* optional */
  }

  /* Panel look + generous inner whitespace */
  #registerPitch,
  #audienceCol,
  #registrationForm{
	background: #fff;
	padding: var(--col-pad-y) var(--col-pad-x);
	border: 1px solid #eee;
	border-radius: 12px;
	box-shadow: 0 1px 0 rgba(0,0,0,.03);
  }

  /* Pitch typography */
  #registerPitch h1{
	margin: 0 0 .6rem 0;
	font-size: clamp(1.75rem, 1rem + 1.2vw, 2rem);
  }
  #registerPitch p.lede{
	margin: .25rem 0 1rem 0;
	font-size: 1.1rem;
	line-height: 1.55;
  }

  /* Benefits list */
  .benefits{
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: .6rem 1rem;
	margin: 1rem 0 1.25rem 0;
  }
  .benefits li{
	list-style: none;
	padding-left: 1.6rem;
	position: relative;
	line-height: 1.35;
  }
  .benefits li::before{
	content: "✔";
	position: absolute; left: 0; top: 0;
	color: #16a34a; font-weight: 700;
  }

  /* Audience cards — wider, expand to fill the row */
  #audienceCol{
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 1.25rem;
  }
  #audienceCol .card{
	border: 1px solid #e9e9e9;
	border-radius: .9rem;
	padding: 1rem 1.1rem;
	background: #fff;
	box-shadow: 0 1px 0 rgba(0,0,0,.03);
	transition: transform .15s ease, box-shadow .15s ease;
  }
  #audienceCol .card:hover{ transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,.06); }
  #audienceCol .card h4{ margin: .1rem 0 .4rem 0; font-size: 1rem; }
  #audienceCol .card ul{ margin: 0; padding-left: 1.2rem; }
  #audienceCol .card li{ margin: .18rem 0; }

  /* Form fields */
  #registrationForm h2{ margin: .25rem 0 1rem 0; font-size: 1.35rem; }
  #registrationForm .field{ margin-bottom: .75rem; display: flex; flex-direction: column; }
  #registrationForm label{ font-size: .9rem; margin-bottom: .25rem; }
  #registrationForm input{
	padding: .55rem .65rem; border: 1px solid #cfcfcf; border-radius: .55rem; outline: none; width: 100%;
  }
  #registrationForm input:focus{ border-color: #111; }
  #smallPrint{ margin-top: .6rem; font-size: .85rem; color: #555; }
  #passwordHelp{ font-size: .85rem; color: #444; margin-top: .35rem; }
  #trust span{ display: inline-block; margin-right: .75rem; }

  /* Password strength */
  #strengthWrap{ margin-top: .25rem; }
  #strengthBar{ height: 6px; border-radius: 999px; background: #eee; overflow: hidden; }
  #strengthFill{ display: block; height: 100%; width: 0%; background: #ef4444; transition: width .2s ease, background .2s ease; }
  #strengthLabel{ font-size: .8rem; margin-top: .25rem; color: #555; }

  /* Registration messages */
  #registrationMessage{ margin-top: .75rem; font-size: .95rem; }
  .msg-success{ background:#ecfdf5; border:1px solid #34d399; color:#065f46; padding:.6rem .7rem; border-radius:.6rem; }
  .msg-error{ background:#fef2f2; border:1px solid #f87171; color:#7f1d1d; padding:.6rem .7rem; border-radius:.6rem; }

  /* ===== Responsive ===== */
  @media (max-width: 1000px){
	#audienceCol{ grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
  }
  @media (max-width: 900px){
	/* Single column grid with explicit order */
	#registerPage{
	  display: grid;
	  grid-template-columns: 1fr;
	  grid-template-areas:
		"pitch"
		"form"
		"audience";
	  gap: 1rem;
	}
  
	/* Flatten the wrapper so its children participate in #registerPage’s grid */
	#leftCol{ display: contents; }
  
	/* Place each section */
	#registerPitch{ grid-area: pitch; }
	#registrationForm{
	  grid-area: form;
	  position: static;   /* disable sticky on mobile */
	}
	#audienceCol{ grid-area: audience; }
  
	/* Tidy mobile layout */
	.benefits{ grid-template-columns: 1fr; }
  }
  
  /* Modal shell */
  .modal.is-hidden { display: none; }
  .modal { position: fixed; inset: 0; z-index: 1000; }
  .modal-backdrop {
	position: absolute; inset: 0; background: rgba(0,0,0,.45);
  }
  .modal-panel {
	position: relative; width: min(640px, 92vw); max-height: 86vh;
	margin: 6vh auto 0; background: #fff; border-radius: 12px;
	box-shadow: 0 10px 40px rgba(0,0,0,.25); display: flex; flex-direction: column;
  }
  .modal-header, .modal-footer { padding: 16px 20px; }
  .modal-header { border-bottom: 1px solid #eee; display:flex; align-items:center; justify-content:space-between; }
  .modal-close { background: none; border: 0; font-size: 20px; line-height: 1; cursor: pointer; }
  .modal-body { padding: 16px 20px; }
  .modal-scroll { max-height: 40vh; overflow: auto; padding-right: 4px; }
  .modal-check { display: block; margin-top: 12px; }
  .btn-primary {
	background: var(--brand); color: #fff; border: 0; border-radius: 8px; padding: 10px 16px; cursor: pointer;
  }
  .btn-primary:disabled { opacity: .5; cursor: not-allowed; }
  .btn-secondary {
	background: #f4f4f4; color: #333; border: 1px solid #ddd; border-radius: 8px; padding: 10px 16px; cursor: pointer;
  }
</style>

<div id="registerPage">
  <!-- Left Column: Pitch + Audience -->
  <div id="leftCol">
	<!-- Pitch -->
	<section id="registerPitch">
	  <h1>Join Accelulator Free</h1>
	  <p class="lede">Get clarity on your people costs in minutes. Create your account to access <strong>StaffCast</strong> — plan, track, and forecast staffing costs without spreadsheets.</p>
	  <ul class="benefits">
		<li><strong>Full access on the free plan</strong> — no credit card required</li>
		<li><strong>One clean view</strong> — actuals, outturn, and forecast together</li>
		<li><strong>Instant what-ifs</strong> — model hires, leavers, and pay changes</li>
		<li><strong>Shareable</strong> — align Finance, HR, and budget owners fast</li>
		<li><strong>Fast onboarding</strong> — upload payroll and get insights quickly</li>
		<li><strong>Secure</strong> — data encrypted in transit and at rest</li>
	  </ul>
	</section>

	<!-- Audience -->
	<section id="audienceCol">
	  <div class="card">
		<h4>CFOs & FDs</h4>
		<ul>
		  <li>Break down people costs by department, role, or person</li>
		  <li>See year-end outturn instantly from live data</li>
		  <li>Spot overspends early and share board-ready views</li>
		</ul>
	  </div>
	  <div class="card">
		<h4>HR Leads</h4>
		<ul>
		  <li>Align hiring plans to budget and forecast</li>
		  <li>Track start/end dates and role changes in one place</li>
		  <li>Test scenarios like delayed hires or pay rises</li>
		</ul>
	  </div>
	  <div class="card">
		<h4>Budget Owners</h4>
		<ul>
		  <li>See if you’re under/over budget at a glance</li>
		  <li>Understand monthly cost drivers and trends</li>
		  <li>Skip spreadsheet wrangling and manual updates</li>
		</ul>
	  </div>
	  <div class="card">
		<h4>What happens next?</h4>
		<ul>
		  <li>Create your account (takes ~30s)</li>
		  <li>Upload a simple payroll extract</li>
		  <li>Get an instant view of actuals, outturn & forecast</li>
		</ul>
	  </div>
	</section>
  </div>

  <!-- Right Column: Registration Form -->
  <div id="registrationForm">
	<h2>Create your free account</h2>

	<div class="field">
	  <label for="firstname">First Name</label>
	  <input type="text" maxlength="50" name="firstname" placeholder="Your firstname" id="firstname" onblur="checkFirstname()">
	</div>
	<div class="field">
	  <label for="surname">Surname</label>
	  <input type="text" maxlength="50" name="surname" placeholder="Your surname" id="surname" onblur="checkSurname()">
	</div>
	<div class="field">
	  <label for="emailAddress">Email</label>
	  <input type="text" maxlength="50" name="user" placeholder="user@business.com" id="emailAddress" onblur="checkEmailAddress()">
	</div>

	<div class="field">
	  <label for="pass">Password</label>
	  <input type="password" maxlength="50" name="pass" id="pass"
			 onblur="checkRegistrationPassword()"
			 onchange="checkRegistrationPassword()"
			 oninput="updatePasswordStrength(this.value)">
	  <div id="strengthWrap" aria-hidden="true">
		<div id="strengthBar"><span id="strengthFill"></span></div>
		<div id="strengthLabel">Strength: —</div>
	  </div>
	  <small id="passwordHelp">Use at least 8 characters with a mix of letters and numbers.</small>
	</div>

	<div class="field">
	  <label for="businessName">Business Name</label>
	  <input type="text" maxlength="60" name="business" placeholder="ACME Ltd" id="businessName" onblur="checkBusinessName()">
	</div>

	<div class="field">
	  <button type="button" class="btn-primary" id="registerBtn" onclick="processRegistrationDetails();">Register</button>
	  <div id="smallPrint">No spam. No hidden fees. You can cancel anytime.</div>
	  <div id="trust" aria-hidden="true">
		<span>SSL secured</span>
		<span>UK-based</span>
		<span>GDPR-aware</span>
	  </div>
	</div>

	<div id="registrationMessage"></div>
  </div>
</div>

<!-- Terms & Conditions modal -->
<div id="tcModal" class="modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="tcTitle" aria-describedby="tcBody">
  <div class="modal-backdrop" onclick="closeTosModal()"></div>
  <div class="modal-panel" role="document">
	<header class="modal-header">
	  <h3 id="tcTitle">Terms & Conditions</h3>
	  <button type="button" class="modal-close" aria-label="Close" onclick="closeTosModal()">×</button>
	</header>

	<section id="tcBody" class="modal-body">
	  <div class="modal-scroll">
		<!-- Keep this brief or inject your full T&Cs here -->
		<p>Welcome to Accelulator! Please review our <a href="/pages/terms.php" target="_blank" rel="noopener">Terms of Service</a> and <a href="/pages/privacy.php" target="_blank" rel="noopener">Privacy Policy</a>. By continuing, you agree to these terms.</p>
	  </div>

	  <label class="modal-check">
		<input type="checkbox" id="agreeTerms"> I have read and agree to the Terms of Service
	  </label>
	  <label class="modal-check">
		<input type="checkbox" id="agreePrivacy"> I agree to the Privacy Policy
	  </label>
	</section>

	<footer class="modal-footer">
	  <button type="button" class="btn-secondary" onclick="closeTosModal()">Cancel</button>
	  <button type="button" id="agreeAndContinue" class="btn-primary" disabled>Agree & continue</button>
	</footer>
  </div>
</div>

<?php
if(!$isInject) {
	require_once __DIR__ . '/footer.php';
}
?>
