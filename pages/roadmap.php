<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';
?>

<style>
  /* =========================
	 Roadmap page (scoped)
	 Uses your global variables + button classes
	 ========================= */

  .rm-wrap{
	max-width: 1100px;
	margin: 0 auto;
	padding: 22px 18px 42px 18px;
  }

  .rm-hero{
	background: var(--panel);
	border: 1px solid rgba(0,0,0,.08);
	border-radius: var(--radius);
	box-shadow: var(--shadow-2);
	padding: 22px 20px;
  }

  .rm-hero h1{
	margin: 0 0 8px 0;
	line-height: 1.15;
	letter-spacing: -0.02em;
  }

  .rm-hero p{
	margin: 0;
	max-width: 78ch;
	color: var(--muted-ink);
	font-size: 1.05rem;
  }

  .rm-actions{
	margin-top: 14px;
	display: flex;
	gap: .7rem;
	flex-wrap: wrap;
	align-items: center;
  }

  /* Tidy link style: no underline line */
  .rm-actions a.rm-link{
	text-decoration: none;
	color: var(--brand);
	font-weight: 700;
	padding: 0;
	border: none;
	background: none;
  }
  
  .rm-actions a.rm-link:hover{
	color: var(--brand-ink);
  }
  
  a {
	  text-decoration: none;
  }

  .rm-anchorbar{
	margin-top: 14px;
	display: flex;
	gap: .75rem;
	flex-wrap: wrap;
  }

  .rm-anchorbar a{
	text-decoration: none;
	font-weight: 700;
	color: var(--ink);
	opacity: .85;
	padding: 6px 10px;
	border-radius: 999px;
	border: 1px solid rgba(0,0,0,.10);
	background: rgba(255,255,255,.55);
  }

  .rm-anchorbar a:hover{
	opacity: 1;
	border-color: rgba(0,0,0,.18);
  }

  .rm-note{
	margin-top: 14px;
	padding: 12px 12px;
	border-radius: 12px;
	background: rgba(255,255,255,.65);
	border: 1px solid rgba(0,0,0,.06);
  }

  .rm-note p{
	margin: 0;
	color: var(--muted-ink);
	font-size: .95rem;
  }

  .rm-section{
	margin-top: 22px;
  }

  .rm-section-title{
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 10px;
  }

  .rm-section-title h2{
	margin: 0;
  }

  .rm-section-title .rm-sub{
	margin: 0;
	color: var(--muted-ink);
	font-weight: 600;
  }

  .rm-grid{
	display: grid;
	grid-template-columns: 1fr;
	gap: 14px;
  }

  @media (min-width: 980px){
	.rm-grid{ grid-template-columns: 1fr 1fr 1fr; }
  }

  .rm-card{
	background: #fff;
	border: 1px solid rgba(0,0,0,.10);
	border-radius: var(--radius);
	box-shadow: var(--shadow-2);
	padding: 14px 14px;
  }

  .rm-badge{
	display: inline-flex;
	align-items: center;
	gap: 8px;
	font-weight: 800;
	font-size: .82rem;
	padding: 5px 10px;
	border-radius: 999px;
	border: 1px solid rgba(166, 42, 23, .25);
	color: var(--brand);
	background: rgba(166, 42, 23, .06);
	margin-bottom: 10px;
  }

  .rm-card h3{
	margin: 0 0 10px 0;
	font-size: 1.05rem;
  }

  .rm-list{
	margin: 0;
	padding-left: 18px;
  }

  .rm-list li{
	margin: 7px 0;
	color: var(--ink);
  }
  
  li span.done{
	  text-decoration: line-through;
  }

  .rm-small{
	margin: 10px 0 0 0;
	color: var(--muted-ink);
	font-size: .93rem;
  }

  .rm-products{
	display: grid;
	grid-template-columns: 1fr;
	gap: 10px;
  }

  @media (min-width: 760px){
	.rm-products{ grid-template-columns: 1fr 1fr; }
  }

  .rm-pill{
	background: #fff;
	border: 1px solid rgba(0,0,0,.10);
	border-radius: var(--radius);
	box-shadow: var(--shadow-2);
	padding: 12px 12px;
  }

  .rm-pill strong{
	display: block;
	margin-bottom: 2px;
  }

  .rm-pill span{
	color: var(--muted-ink);
	font-size: .93rem;
  }

  .rm-cta{
	margin-top: 24px;
	background: var(--panel);
	border: 1px solid rgba(0,0,0,.08);
	border-radius: var(--radius);
	box-shadow: var(--shadow-2);
	padding: 18px 16px;
  }

  .rm-cta h2{
	margin: 0 0 8px 0;
  }

  .rm-cta p{
	margin: 0;
	color: var(--muted-ink);
	max-width: 78ch;
  }

  /* Ensure anchor jumps don't hide under any fixed header */
  .rm-section{ scroll-margin-top: 90px; }
</style>

<main class="rm-wrap">

  <header class="rm-hero">
	<h1>Roadmap</h1>
	<p>
	  A transparent look at what we’re building next for Accelulator.
	  This plan is directional and may change as we learn from users.
	</p>

	<div class="rm-actions">
	  <a class="btn-primary" href="https://accelulator.com/includes/register.php">Start for free</a>
	  <a class="rm-link" href="mailto:features@accelulator.com?subject=Feature%20suggestion%20for%20Accelulator&body=Hi%20Accelulator%20team%2C%0D%0A%0D%0AI%E2%80%99d%20like%20to%20suggest%20a%20feature%3A%0D%0A%0D%0A%5BDescribe%20the%20problem%20you%E2%80%99re%20solving%5D%0D%0A%0D%0A%5BWhat%20you%E2%80%99d%20like%20to%20happen%5D%0D%0A%0D%0A%5BWhy%20it%20matters%20%2F%20who%20it%20helps%5D%0D%0A%0D%0AThanks%2C%0D%0A">Suggest a feature</a>
	</div>

	<nav class="rm-anchorbar" aria-label="Roadmap sections">
	  <a href="#next-up">Next up</a>
	  <a href="#medium-term">Medium term</a>
	  <a href="#longer-term">Longer term</a>
	  <a href="#planned-modules">Planned modules</a>
	</nav>

	<div class="rm-note" role="note">
	  <p><strong>Status:</strong> Items are grouped by priority rather than fixed dates. Shipping is continuous.</p>
	</div>
  </header>

  <!-- =======================
	   NEXT UP
	   ======================= -->
  <section id="next-up" class="rm-section">
	<div class="rm-section-title">
	  <h2>Next up</h2>
	  <p class="rm-sub">High-impact improvements we’re focused on right now.</p>
	</div>

	<div class="rm-grid">
	  <article class="rm-card">
		<div class="rm-badge">Data accuracy</div>
		<h3>Better history and tagging</h3>
		<ul class="rm-list">
		  <li>Historic department alignment (store department at upload time, with sensible fallbacks)</li>
		  <li>Capex / Opex / Exceptional tagging</li>
		  <li>Employee rate history (reusable for pension %, Employers NI, etc.)</li>
		</ul>
	  </article>

	  <article class="rm-card">
		<div class="rm-badge">Structure</div>
		<h3>Cleaner organisation</h3>
		<ul class="rm-list">
		  <li>Functions that contain Departments (fully editable and deletable)</li>
		  <li>Cost centres (optional structure, can be independent from Functions and Departments)</li>
		</ul>
	  </article>

	  <article class="rm-card">
		<div class="rm-badge">Usability</div>
		<h3>More control, less friction</h3>
		<ul class="rm-list">
		  <li>Reset buttons for user-entered adjustments (restore original values)</li>
		  <li>Excel export from tables</li>
		  <li>✅ <span class="done">Excel export of forecast</span></li>
		  <li>Refresh/redraw on every change (consistent fetch actions)</li>
		</ul>
	  </article>
	</div>

	<div class="rm-grid" style="margin-top:14px;">
	  <article class="rm-card">
		<div class="rm-badge">Permissions</div>
		<h3>Role-based access that actually holds up</h3>
		<ul class="rm-list">
		  <li>Department-only view (strictly limited to that department)</li>
		  <li>Offboarding flow (switch users back to non-paying cleanly)</li>
		</ul>
	  </article>

	  <article class="rm-card">
		<div class="rm-badge">Payroll</div>
		<h3>Contractors</h3>
		<ul class="rm-list">
		  <li>Finish contractor section (wire up new contractor and new payment logic)</li>
		</ul>
		<p class="rm-small">Goal: contractors feel first-class, not bolted on.</p>
	  </article>

	  <article class="rm-card">
		<div class="rm-badge">Polish</div>
		<h3>Quality-of-life improvements</h3>
		<ul class="rm-list">
		  <li>✅ <span class="done">Company name editable in Settings (main account)</span></li>
		  <li>YTD view toggle (monthly vs YTD)</li>
		</ul>
	  </article>
	</div>
  </section>

  <!-- =======================
	   MEDIUM TERM
	   ======================= -->
  <section id="medium-term" class="rm-section">
	<div class="rm-section-title">
	  <h2>Medium term</h2>
	  <p class="rm-sub">Governance, workflows, imports, and performance.</p>
	</div>

	<div class="rm-grid">
	  <article class="rm-card">
		<div class="rm-badge">Governance</div>
		<h3>Audit and safety nets</h3>
		<ul class="rm-list">
		  <li>Change audit log (who changed what, and when)</li>
		  <li>Database reset (main user can wipe and restart)</li>
		</ul>
	  </article>

	  <article class="rm-card">
		<div class="rm-badge">Workflow</div>
		<h3>Role authorisation</h3>
		<ul class="rm-list">
		  <li>Joined-up flow: request a role, approve it, then allocate a person</li>
		</ul>
	  </article>

	  <article class="rm-card">
		<div class="rm-badge">Imports</div>
		<h3>Bring your structure in fast</h3>
		<ul class="rm-list">
		  <li>Role import</li>
		  <li>Department import</li>
		  <li>Function import</li>
		  <li>Forecast import</li>
		</ul>
	  </article>
	</div>

	<div class="rm-grid" style="margin-top:14px;">
	  <article class="rm-card">
		<div class="rm-badge">Views</div>
		<h3>More role-based views</h3>
		<ul class="rm-list">
		  <li>Functional-only view (strictly limited to that function)</li>
		  <li>Manager view</li>
		  <li>Auditor view</li>
		  <li>Management alignment (manager mapping so access is automatic)</li>
		</ul>
	  </article>

	  <article class="rm-card">
		<div class="rm-badge">Weekly</div>
		<h3>Weekly mode</h3>
		<ul class="rm-list">
		  <li>Weekly pay periods and reporting</li>
		</ul>
	  </article>

	  <article class="rm-card">
		<div class="rm-badge">Architecture</div>
		<h3>Rework table and data handling</h3>
		<ul class="rm-list">
		  <li>Reduce reliance on long-lived JS state</li>
		  <li>Fetch required data when needed</li>
		</ul>
	  </article>
	</div>

	<div class="rm-note" style="margin-top:14px;">
	  <p><strong>Labs:</strong> a future area where users can suggest and vote on new products and features.</p>
	</div>
  </section>

  <!-- =======================
	   LONGER TERM
	   ======================= -->
  <section id="longer-term" class="rm-section">
	<div class="rm-section-title">
	  <h2>Longer term</h2>
	  <p class="rm-sub">Bigger shifts once the foundations are rock solid.</p>
	</div>

	<div class="rm-grid">
	  <article class="rm-card">
		<div class="rm-badge">Experience</div>
		<h3>User experience overhaul</h3>
		<ul class="rm-list">
		  <li>Cleaner flows</li>
		  <li>More consistent UI</li>
		  <li>Fewer clicks for core tasks</li>
		</ul>
	  </article>

	  <article class="rm-card">
		<div class="rm-badge">Analysis</div>
		<h3>Variance analysis</h3>
		<ul class="rm-list">
		  <li>Identify drivers</li>
		  <li>Add commentary</li>
		  <li>Export-ready reporting</li>
		</ul>
	  </article>

	  <article class="rm-card">
		<div class="rm-badge">Scale</div>
		<h3>Multi-company and apps</h3>
		<ul class="rm-list">
		  <li>Multiple companies under one account</li>
		  <li>Native apps (iOS, macOS, Windows)</li>
		</ul>
	  </article>
	</div>
  </section>

  <!-- =======================
	   PLANNED MODULES
	   ======================= -->
  <section id="planned-modules" class="rm-section">
	<div class="rm-section-title">
	  <h2>Planned modules</h2>
	  <p class="rm-sub">Separate tools we intend to build as Accelulator grows.</p>
	</div>

	<div class="rm-products">
	  <div class="rm-pill"><strong>Revenue forecasting</strong><span>Model revenue with drivers and timing.</span></div>
	  <div class="rm-pill"><strong>Margin forecasting</strong><span>Understand gross margin and contribution.</span></div>
	  <div class="rm-pill"><strong>Operating cost forecasting</strong><span>Beyond payroll: recurring costs made simple.</span></div>
	  <div class="rm-pill"><strong>Overhead forecasting</strong><span>Keep overheads visible and controllable.</span></div>
	  <div class="rm-pill"><strong>Allocations &amp; apportionments</strong><span>Allocate shared costs across teams.</span></div>
	  <div class="rm-pill"><strong>Balance sheet forecasting</strong><span>Cash, working capital, and balance sheet drivers.</span></div>
	  <div class="rm-pill"><strong>Scenario planning</strong><span>Compare options quickly without spreadsheet chaos.</span></div>
	  <div class="rm-pill"><strong>Performance analysis</strong><span>Trends, variance, and insight.</span></div>
	  <div class="rm-pill"><strong>Business planning</strong><span>A joined-up plan from assumptions to outputs.</span></div>
	  <div class="rm-pill"><strong>Commission calculator</strong><span>Simple, explainable commission logic.</span></div>
	</div>
  </section>

  <!-- =======================
	   CTA
	   ======================= -->
  <section class="rm-cta">
	<h2>Have a suggestion?</h2>
	<p>If you’re using Accelulator and something feels missing, tell us. The best roadmap is the one shaped by real users.</p>

	<div class="rm-actions" style="margin-top:12px;">
	  <a class="btn-secondary" href="mailto:features@accelulator.com?subject=Feature%20suggestion%20for%20Accelulator&body=Hi%20Accelulator%20team%2C%0D%0A%0D%0AI%E2%80%99d%20like%20to%20suggest%20a%20feature%3A%0D%0A%0D%0A%5BDescribe%20the%20problem%20you%E2%80%99re%20solving%5D%0D%0A%0D%0A%5BWhat%20you%E2%80%99d%20like%20to%20happen%5D%0D%0A%0D%0A%5BWhy%20it%20matters%20%2F%20who%20it%20helps%5D%0D%0A%0D%0AThanks%2C%0D%0A">Suggest a feature</a>
	  <a class="rm-link" href="https://accelulator.com/includes/register.php">Start for free</a>
	</div>
  </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>