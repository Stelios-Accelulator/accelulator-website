<style>
	/* === Home hero layout (two-column) === */
	.home-hero-container {
	  max-width: 1180px;
	  margin: 0 auto;
	  padding-top: 2rem;
	}
	
	.home-hero {
	  display: flex;
	  gap: 3rem;
	  align-items: flex-start;
	  justify-content: space-between;
	  flex-wrap: wrap;
	}
	
	.home-hero-left {
	  flex: 1 1 50%;
	  max-width: none; // 750px works but, for this, let's go with completely removing the max-width
	}
	
	.home-eyebrow {
	  text-transform: uppercase;
	  letter-spacing: 0.1em;
	  font-size: 0.8rem;
	  color: #666;
	  margin-bottom: 0.5rem;
	}
	
	.home-headline {
	  font-size: clamp(2rem, 3vw, 2.8rem);
	  margin-bottom: 0.5rem;
	}
	
	.home-subtext {
	  font-size: 1rem;
	  line-height: 1.5;
	  max-width: 32rem;
	  color: var(--muted-ink);
	}
	
	.home-buttons {
	  display: flex;
	  flex-wrap: wrap;
	  gap: 0.8rem;
	  margin-top: 1.5rem;
	}
	
	/* right-hand placeholder */
	.home-hero-right {
	  flex: 0 0 360px;
	  display: flex;
	  align-items: center;
	  justify-content: center;
	}
	
	.auth-placeholder {
	  border: 2px dashed #ccc;
	  border-radius: 10px;
	  padding: 2rem;
	  text-align: center;
	  background: #fafafa;
	  color: #555;
	  width: 100%;
	}
	
	/* responsive layout */
	@media (max-width: 960px) {
	  .home-hero {
		flex-direction: column;
		align-items: stretch;
	  }
	  .home-hero-right {
		flex: 1 1 auto;
		margin-top: 2rem;
	  }
	}
	.home-hero-right .auth-card {
	  box-shadow: var(--shadow-2);
	  border: 1px solid #e5e7eb;
	  border-radius: 12px;
	}
	/* login form when used inside homepage hero */
	.home-hero-right .auth-wrap {
	  min-height: auto;
	  display: block;
	  padding: 0;
	  background: transparent;
	  place-items: initial;
	}
	
	.home-hero-right .auth-card {
	  width: 100%;
	  max-width: 360px;
	  box-shadow: var(--shadow-2);
	  border: 1px solid #e5e7eb;
	  border-radius: 12px;
	}
	/* kill default hero padding on the homepage */
	section.hero {
	  padding: 0;
	  background: none;
	  text-align: left;
	}
	
	/* place the cards neatly under the hero text */
	.home-features {
	  display: flex;
	  gap: 1.5rem;
	  flex-wrap: wrap;
	  margin-top: 2.5rem;
	}
	
	.home-features .card {
	  flex: 1 1 180px;
	  background: #f2f2f2;
	  border-radius: 10px;
	  padding: 1.5rem;
	  box-shadow: var(--shadow-2);
	  text-align: left;
	  transition: transform 0.15s ease, box-shadow 0.15s ease;
	}
	
	.home-features .card:hover {
	  transform: translateY(-3px);
	  box-shadow: 0 8px 18px rgba(0,0,0,0.08);
	}
	
	.home-features h3 {
	  font-size: 1.1rem;
	  margin-bottom: 0.5rem;
	}
	
	.home-features p {
	  font-size: 0.95rem;
	  color: #444;
	}
	/* tighten login card when shown on the homepage */
	.home-hero-right .auth-card {
	  margin-bottom: 2.5rem;        /* gives space above the grey band */
	  padding-bottom: 1.25rem;      /* a little less vertical space */
	}
	
	/* make the "Don't have an account?" smaller on the homepage */
	.home-hero-right .auth-links {
	  font-size: 0.85rem;
	  margin-top: 0.6rem;
	  line-height: 1.3;
	}
	.home-hero-right .auth-links a {
	  font-size: 0.85rem;
	}
	
	/* center and size the feature cards properly */
	.home-features {
	  display: flex;
	  justify-content: center;   /* keeps them centered */
	  align-items: flex-start;
	  gap: 1.25rem;
	  flex-wrap: wrap;           /* wrap only when necessary (mobile) */
	  margin-top: 2.25rem;
	}
	
	.home-features .card {
	  flex: 0 1 200px;           /* narrower default to fit three side-by-side */
	  text-align: center;
	  background: #f3f3f3;
	  border-radius: 10px;
	  padding: 1.5rem;
	  box-shadow: var(--shadow-2);
	  transition: transform 0.15s ease, box-shadow 0.15s ease;
	}
	
	.home-features .card:hover {
	  transform: translateY(-3px);
	  box-shadow: 0 8px 18px rgba(0,0,0,0.08);
	}
	
	.home-features h3 {
	  font-size: 1.05rem;
	  margin-bottom: 0.4rem;
	}
	
	.home-features p {
	  font-size: 0.95rem;
	  color: #444;
	  max-width: 13rem;
	  margin: 0 auto;
	}
	
	/* slightly widen them on large screens */
	@media (min-width: 1100px) {
	  .home-features .card {
		flex: 0 1 220px;
	  }
	}
	
	.home-roadmap{
	  max-width:1180px;
	  margin:0 auto;
	  padding:2.25rem 1.25rem;
	  text-align:left;
	}
	
	.home-roadmap p{
	  max-width:42rem;
	  color:var(--muted-ink);
	  margin:0.5rem 0 1rem 0;
	}
	
	.hero-tertiary-links{
	  margin-top:12px;
	}
	
	.roadmap-link{
	  display:inline-flex;
	  align-items:center;
	  gap:8px;
	  font-size:0.95rem;
	  color:var(--muted-ink);
	  text-decoration:none;
	  border-bottom:1px solid rgba(0,0,0,0.12);
	  padding-bottom:2px;
	}
	
	.roadmap-link:hover{
	  color:var(--ink);
	  border-bottom-color:rgba(0,0,0,0.25);
	}
	
</style>

<div class="padded">
	
	  <section class="hero">
		<div class="home-hero-container padded">
		  <section class="home-hero">
			<!-- LEFT COLUMN -->
			<div class="home-hero-left">
			  <p class="home-eyebrow">People-cost forecasting for finance teams</p>
			  <h1 class="home-headline">What if your people costs were always right?</h1>
			  <p class="home-subtext">
				With StaffCast they can be — forecast payroll with confidence and stop chasing spreadsheets.
			  </p>
			  <div class="home-buttons">
				<button class="btn-primary" onclick="loadRegistrationForm();">Start for free</button>
				<button class="btn-secondary" onclick="$('#contentView').load('/pages/seeHowItWorks.php?inject=1');">See how it works</button>
			  </div>
			  
			  <div class="hero-tertiary-links">
				<a class="roadmap-link" href="/pages/roadmap.php">View the roadmap →</a>
			  </div>
			  
			  <div class="home-features">
				  <div class="card" onclick="createCFOMenu();">
					<h3>💼 CFOs &amp; FDs</h3>
					<p>Instantly see where your biggest people costs are going.</p>
				  </div>
				  <div class="card" onclick="createHRMenu();">
					<h3>🎯 HR Leads</h3>
					<p>Map out staffing plans without budget guesswork.</p>
				  </div>
				  <div class="card" onclick="createBudgetHolderMenu();">
					<h3>🧠 Budget Owners</h3>
					<p>See actuals, outturn, and forecast in one clean view.</p>
				  </div>
				</div>
			</div>
		
			<!-- RIGHT COLUMN (Sign-in form) -->
			<div class="home-hero-right">
			  <?php
				// Include the login form in a contained wrapper
				$_GET['inject'] = '1';
				include $_SERVER['DOCUMENT_ROOT'] . '/includes/login.php';
				unset($_GET['inject']);
			  ?>
			</div>
		  </section>
		</div>
	  </section>
	  
	  <section id="homeBottomHalo" class="section">
		<h2>People cost planning shouldn’t feel like guesswork.<br>Now it doesn’t.</h2>
		<button class="btn-primary" onClick="loadRegistrationForm();">Start for free</button>
	  </section><footer id="siteFooter">
		<div class="footer-container">
		  <p class="footer-brand">
			<strong>Accelulator Ltd</strong> &nbsp;·&nbsp;
			Registered in England & Wales &nbsp;·&nbsp;
			Company No. 15828367
		  </p>
		  <p class="footer-links">
			<a href="/pages/privacy.php">Privacy Policy</a> &nbsp;·&nbsp;
			<a href="/pages/terms.php">Terms & Conditions</a> &nbsp;·&nbsp;
			<a href="/pages/roadmap.php">Roadmap</a> &nbsp;·&nbsp;
			<a href="mailto:contact@accelulator.com">Contact Us</a> &nbsp;·&nbsp;
			<a href="https://buymeacoffee.com/accelulator" target="_blank" rel="noopener noreferrer" title="Opens in a new tab">Support Accelulator</a>
		  </p>
		  <p class="footer-copyright">
			© <?php echo date('Y'); ?> Accelulator Ltd. All rights reserved.
		  </p>
		</div>
	  </footer>
</div>