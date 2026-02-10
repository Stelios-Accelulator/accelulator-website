<?php
// home.php
// Assumes your header/nav is handled elsewhere (e.g. includes/header.php) and this is the page body.
?>

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
		max-width: none;
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
		max-width: 36rem;
		color: var(--muted-ink);
	}

	.home-buttons {
		display: flex;
		flex-wrap: wrap;
		gap: 0.8rem;
		margin-top: 1.5rem;
	}

	/* right-hand login card column */
	.home-hero-right {
		flex: 0 0 360px;
		display: flex;
		align-items: center;
		justify-content: center;
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
		margin-bottom: 2.5rem;        /* gives space above the grey band */
		padding-bottom: 1.25rem;      /* a little less vertical space */
	}

	/* make the "Don't have an account?" smaller on the homepage */
	.home-hero-right .auth-links,
	.home-hero-right .auth-links a {
		font-size: 0.85rem;
		margin-top: 0.6rem;
		line-height: 1.3;
	}

	/* kill default hero padding on the homepage */
	section.hero {
		padding: 0;
		background: none;
		text-align: left;
	}

	/* remove underlines for hero buttons (buttons + anchors styled as buttons) */
	.home-buttons a.btn-secondary,
	.home-buttons a.btn-secondary:hover,
	.home-buttons a.btn-secondary:focus,
	.home-buttons a.btn-primary,
	.home-buttons a.btn-primary:hover,
	.home-buttons a.btn-primary:focus {
		text-decoration: none;
	}

	/* tertiary link under hero */
	.hero-tertiary-links {
		margin-top: 12px;
	}

	.roadmap-link {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		font-size: 0.95rem;
		color: var(--muted-ink);
		text-decoration: none;
		border-bottom: 1px solid rgba(0,0,0,0.12);
		padding-bottom: 2px;
	}

	.roadmap-link:hover {
		color: var(--ink);
		border-bottom-color: rgba(0,0,0,0.25);
	}

	/* === New sections === */
	.home-sections {
		max-width: 1180px;
		margin: 0 auto;
		padding: 0 1.25rem 2.5rem 1.25rem;
	}

	.home-section {
		margin-top: 2.25rem;
		padding-top: 2.25rem;
		border-top: 1px solid rgba(0,0,0,0.06);
	}

	.home-section:first-child {
		margin-top: 0;
		padding-top: 0;
		border-top: none;
	}

	.home-section h2 {
		font-size: 1.5rem;
		margin-bottom: 0.75rem;
	}

	.home-section p {
		max-width: 44rem;
		color: var(--muted-ink);
		line-height: 1.6;
		margin: 0 0 1.25rem 0;
	}

	/* Audience blocks */
	.home-audience-grid {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 1.25rem;
	}

	@media (max-width: 960px) {
		.home-audience-grid {
		grid-template-columns: 1fr;
		}
	}

	.audience-card {
		background: #f3f3f3;
		border-radius: 12px;
		padding: 1.25rem 1.25rem 1.15rem 1.25rem;
		box-shadow: var(--shadow-2);
		border: 1px solid rgba(0,0,0,0.06);
		transition: transform 0.15s ease, box-shadow 0.15s ease;
	}

	.audience-card:hover {
		transform: translateY(-3px);
		box-shadow: 0 8px 18px rgba(0,0,0,0.08);
	}

	.audience-card h3 {
		font-size: 1.08rem;
		margin: 0 0 0.5rem 0;
	}

	.audience-card p {
		margin: 0 0 0.85rem 0;
		color: #444;
		line-height: 1.55;
	}

	.audience-card ul {
		margin: 0;
		padding-left: 1.1rem;
		color: #444;
	}

	.audience-card li {
		margin: 0.35rem 0;
	}

	/* Differentiators */
	.diff-list {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 1.25rem;
		margin-top: 1rem;
	}

	@media (max-width: 960px) {
		.diff-list {
		grid-template-columns: 1fr;
		}
	}

	.diff-item {
		background: #fafafa;
		border: 1px solid rgba(0,0,0,0.06);
		border-radius: 12px;
		padding: 1.1rem 1.1rem 1rem 1.1rem;
	}

	.diff-item strong{
		display: block;
		line-height: 1.25;
		min-height: 2.5em;   /* reserve 2 lines */
		margin-bottom: 0.5rem;
	}

	.diff-item p {
		margin: 0;
		color: #444;
		line-height: 1.55;
	}

	/* ChangeLens callout */
	.changelens-callout {
		display: flex;
		gap: 1.25rem;
		align-items: flex-start;
		justify-content: space-between;
		background: #f7f7f7;
		border: 1px solid rgba(0,0,0,0.06);
		border-radius: 12px;
		padding: 1.25rem;
	}

	@media (max-width: 960px) {
		.changelens-callout {
		flex-direction: column;
		}
	}

	.changelens-callout h3 {
		margin: 0 0 0.5rem 0;
		font-size: 1.15rem;
	}

	.changelens-callout p {
		margin: 0;
		color: #444;
		line-height: 1.6;
		max-width: 48rem;
	}

	.changelens-actions {
		flex: 0 0 auto;
		display: flex;
		gap: 0.75rem;
		align-items: center;
		margin-top: 0.85rem;
	}

	.changelens-actions a {
		text-decoration: none;
	}

	/* Feature cards (reframed as "How it supports this") */
	.home-features {
		display: flex;
		justify-content: center;
		align-items: flex-start;
		gap: 1.25rem;
		flex-wrap: wrap;
		margin-top: 1.25rem;
	}

	.home-features .card {
		flex: 0 1 240px;
		text-align: left;
		background: #f3f3f3;
		border-radius: 10px;
		padding: 1.25rem;
		box-shadow: var(--shadow-2);
		transition: transform 0.15s ease, box-shadow 0.15s ease;
		cursor: default;
	
		display: flex;              /* NEW */
		flex-direction: column;     /* NEW */
	}

	.home-features .card:hover {
		transform: translateY(-3px);
		box-shadow: 0 8px 18px rgba(0,0,0,0.08);
	}

	.home-features .card h4{
		font-size: 1.02rem;
		line-height: 1.25;      /* define it so min-height is predictable */
		margin: 0 0 0.6rem 0;
		min-height: 2.5em;      /* ≈ 2 lines (2 * 1.25em) */
	}

	.home-features {
		display: flex;
		justify-content: center;
		align-items: stretch; /* makes items equal height per row */
		gap: 1.25rem;
		flex-wrap: wrap;
		margin-top: 1.25rem;
	}

	@media (min-width: 1100px) {
		.home-features .card {
		flex: 0 1 260px;
		}
	}

	/* Origin */
	.origin {
		background: #fafafa;
		border: 1px solid rgba(0,0,0,0.06);
		border-radius: 12px;
		padding: 1.25rem;
	}

	.origin p {
		margin: 0;
		color: #444;
		line-height: 1.65;
		max-width: none;  /* fill the container */
	}

	/* Bottom CTA (keeps your existing halo section, but tightened copy) */
	#homeBottomHalo.section {
		padding: 2.5rem 1.25rem;
		text-align: center;
	}

	#homeBottomHalo h2 {
		margin-bottom: 1.1rem;
	}

	/* Roadmap area (kept, but you can remove if you prefer) */
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
	/* ChangeLens placement under the Roadmap link (inside hero-left) */
	.home-changelens-inline{
		margin-top: 2rem;
		padding-top: 0;     /* ensure no inherited section spacing */
		border-top: 0;      /* ensure no inherited section border */
	}
	
	/* Tools callout (free in-browser helpers) */
	.tools-grid{
		display:grid;
		grid-template-columns: 1fr 1fr;
		gap: 1.25rem;
		margin-top: 1rem;
	}
	@media (max-width: 960px){
		.tools-grid{ grid-template-columns: 1fr; }
	}
	
	.tool-card{
		background: #f7f7f7;
		border: 1px solid rgba(0,0,0,0.06);
		border-radius: 12px;
		padding: 1.25rem;
		box-shadow: var(--shadow-2);
	}
	
	.tool-card h3{
		margin: 0 0 0.5rem 0;
		font-size: 1.15rem;
	}
	
	.tool-card p{
		margin: 0 0 0.9rem 0;
		color: #444;
		line-height: 1.6;
		max-width: none;
	}
	
	.tool-actions{
		display:flex;
		gap: 0.75rem;
		flex-wrap: wrap;
		align-items:center;
	}
	
	.tool-actions a{ text-decoration:none; }
	
	.tool-meta{
		margin-top: 0.75rem;
		color: var(--muted-ink);
		font-size: 0.9rem;
		line-height: 1.45;
	}
</style>

<div class="padded">

	<section class="hero">
	<div class="home-hero-container padded">
		<section class="home-hero">
		<!-- LEFT COLUMN -->
		<div class="home-hero-left">
			<p class="home-eyebrow">B2B workforce and financial planning software for UK finance teams and directors</p>
			<h1 class="home-headline">What if you always understood what changed in your people costs and why?</h1>
			<p class="home-subtext">
			StaffCast turns payroll planning into a shared system of understanding, so finance, HR and budget owners stop chasing spreadsheets and start making decisions with confidence.
			</p>

			<div class="home-buttons">
			<button class="btn-primary" onclick="loadRegistrationForm(); plausible('click_start_free');">Start for free</button>

			<button class="btn-secondary"
				onclick="plausible('click_features'); $('#contentView').load('/pages/seeHowItWorks.php?inject=1');">
				Features
			</button>

			</div>

			<div class="hero-tertiary-links">
			<a class="roadmap-link" href="/pages/roadmap.php" onclick="plausible('click_roadmap');">View the roadmap →</a>
			</div>
			
			
			<!-- CHANGELENS (inline under Roadmap link) -->
			<div class="home-changelens-inline" id="changelens">
				<div class="changelens-callout">
					<div>
						<h3>ChangeLens: explain cost change in minutes, not meetings</h3>
						<p>
							Compare two periods and see what actually changed, how much each driver contributed, and where attention is really needed.
						</p>
			
						<div class="changelens-actions">
							<a href="/pages/changeLens.php" class="btn-primary"
								 onclick="event.preventDefault(); plausible('click_changelens_primary', {callback: () => window.location = this.href});">
								Try ChangeLens
							</a>
						</div>
					</div>
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

	<!-- NEW: Narrative sections -->
	<section class="home-sections">

	<!-- WHO IT'S FOR -->
	<section class="home-section" id="who-its-for">
		<h2>Built for the people who carry people-cost decisions</h2>
		<p class="lead">
		Three roles. One shared problem: payroll change arrives as a number, but leadership needs the story behind it.
		</p>

		<div class="home-audience-grid">

		<div class="audience-card" onclick="createCFOMenu(); plausible('click_audience_cfo');">
			<h3>💼 CFOs &amp; FDs</h3>
			<p>
			Be ready for the board question before it lands. Understand the drivers of movement, not just the variance.
			</p>
			<ul>
			<li>Explain payroll movement with clarity, fast</li>
			<li>Spot emerging cost risk earlier</li>
			<li>Turn analysis into a decision, not a debate</li>
			</ul>
		</div>

		<div class="audience-card" onclick="createHRMenu(); plausible('click_audience_hr');">
			<h3>🎯 HR Leads</h3>
			<p>
			Plan workforce change without reverse-engineering finance models. Keep headcount intent and cost impact aligned.
			</p>
			<ul>
			<li>Map staffing plans without spreadsheet fragility</li>
			<li>See the cost impact of change, immediately</li>
			<li>Collaborate with finance on one version of truth</li>
			</ul>
		</div>

		<div class="audience-card" onclick="createBudgetHolderMenu(); plausible('click_audience_budget');">
			<h3>🧠 Budget Owners</h3>
			<p>
			See how today’s decisions become next month’s cost. Less surprise, fewer awkward conversations with finance.
			</p>
			<ul>
			<li>Track actuals, outturn and forecast in one view</li>
			<li>Understand cause and effect, not just totals</li>
			<li>Make changes with visibility of consequences</li>
			</ul>
		</div>

		</div>
	</section>

	<!-- WHY DIFFERENT -->
	<section class="home-section" id="why-different">
		<h2>Why Accelulator feels different</h2>
		<p class="lead">
		Most tools optimise reporting. Accelulator is built for explanation and decision, where confidence matters more than formatting.
		</p>

		<div class="diff-list">
		<div class="diff-item">
			<strong>Tools store numbers. We store structure.</strong>
			<p>
			The logic behind people costs is the real asset: roles, rules, timing, and change. That’s what creates reliable signal.
			</p>
		</div>
		<div class="diff-item">
			<strong>Spreadsheets are flexible. They are not shared systems.</strong>
			<p>
			When the model is personal, understanding is fragile. StaffCast is designed so multiple stakeholders can hold the same truth.
			</p>
		</div>
		<div class="diff-item">
			<strong>Variance is a number. Leadership needs a story.</strong>
			<p>
			If you cannot explain movement simply, you do not really control it. Accelulator helps you turn movement into narrative.
			</p>
		</div>
		</div>
	</section>
	
	<!-- FREE TOOLS -->
	<section class="home-section" id="tools">
		<h2>Free tools for finance teams</h2>
		<p class="lead">
			Small, practical helpers that remove friction in analysis.
			They run entirely in your browser (your spreadsheet is not uploaded).
		</p>
	
		<div class="tools-grid">
	
			<div class="tool-card">
				<h3>⚡ Inefficiency Tax Calculator</h3>
				<p>
					Finance leaders feel it in their gut but struggle to quantify it:
					most teams pay an inefficiency tax on people decisions.
					Not because payroll is wrong. Because decision-ready information arrives too late to act.
				</p>
				<p style="margin-top:-0.4rem;">
					In my own work, that tax typically sits between <strong>£300 and £1,500 per employee per year</strong>.
				</p>
	
				<div class="tool-actions">
					<a class="btn-primary"
						 href="/pages/inefficiencyTax.php"
						 onclick="plausible('click_tool_inefficiencyTax', {callback: () => window.location = this.href}); return false;">
						Calculate your cost
					</a>
	
					<a class="btn-secondary"
						 href="#"
						 onclick="loadRegistrationForm(); plausible('click_tool_start_free'); return false;">
						Start for free
					</a>
				</div>
	
				<div class="tool-meta">
					If you want to remove the delay entirely, StaffCast turns payroll and plans into a live workforce cost signal that teams can trust and act on.
				</div>
			</div>
	
			<div class="tool-card">
				<h3>🧾 Contra Matcher</h3>
				<p>
					Upload a general ledger extract and automatically flag contra postings.
					Find exact 1:1 offsets and (optionally) one-to-many matches where several lines net off a transaction.
				</p>
	
				<div class="tool-actions">
					<a class="btn-primary"
						 href="/pages/contraMatch.php"
						 onclick="plausible('click_tool_contraMatch', {callback: () => window.location = this.href}); return false;">
						Try Contra Matcher
					</a>
	
					<a class="btn-secondary"
						 href="https://buymeacoffee.com/accelulator"
						 target="_blank"
						 rel="noopener"
						 onclick="plausible('click_tool_support');">
						Support development
					</a>
				</div>
	
				<div class="tool-meta">
					This is one of the “little frictions” StaffCast is designed to eliminate at scale, by turning finance work into shared, repeatable systems.
				</div>
			</div>
	
		</div>
	</section>

	<!-- HOW IT SUPPORTS THIS -->
	<section class="home-section" id="how-it-supports">
		<h2>How StaffCast supports this way of working</h2>
		<p class="lead">
		Everything is built around one outcome: clearer people-cost signal that multiple roles can trust and act on.
		</p>

		<div class="home-features">
		<div class="card">
			<h4>Multi-role access</h4>
			<p>Finance, HR and budget owners can collaborate without passing spreadsheets around or losing context.</p>
		</div>

		<div class="card">
			<h4>Actuals, outturn and forecast together</h4>
			<p>See where you are, where you’re headed, and what changed, in one clean view.</p>
		</div>

		<div class="card">
			<h4>Rules and cost splits</h4>
			<p>Encode how costs flow (opex/capex/exceptionals, allocations, timing) so understanding is repeatable.</p>
		</div>

		<div class="card">
			<h4>Upload and reconcile with confidence</h4>
			<p>Bring payroll data in quickly, then focus on meaning, not manual cleansing and re-keying.</p>
		</div>
		</div>
	</section>

	<!-- ORIGIN STORY -->
	<section class="home-section" id="origin">
		<h2>Why we built Accelulator</h2>
		<div class="origin">
		<p>
			Accelulator was built from lived finance work: explaining people-cost movements to CEOs, boards and budget holders using fragile spreadsheets and stitched-together tools.
			StaffCast exists to turn that hard-won working practice into a shared system, so understanding flows through an organisation instead of getting trapped in one person’s model.
		</p>
		</div>
	</section>

	</section>

	<!-- Bottom halo CTA (kept) -->
	<section id="homeBottomHalo" class="section">
	<h2>People cost planning shouldn’t feel like guesswork.<br>Now it doesn’t.</h2>
	<button class="btn-primary" onclick="loadRegistrationForm(); plausible('click_start_free_bottom');">Start for free</button>
	</section>

	<footer id="siteFooter">
	<div class="footer-container">
		<p class="footer-brand">
		<strong>Accelulator Ltd</strong> &nbsp;·&nbsp;
		Registered in England &amp; Wales &nbsp;·&nbsp;
		Company No. 15828367
		</p>
		<p class="footer-links">
		<a href="/pages/privacy.php">Privacy Policy</a> &nbsp;·&nbsp;
		<a href="/pages/terms.php">Terms &amp; Conditions</a> &nbsp;·&nbsp;
		<a href="/pages/roadmap.php">Roadmap</a> &nbsp;·&nbsp;
		<a href="mailto:contact@accelulator.com">Contact Us</a> &nbsp;·&nbsp;
		<a href="/pages/inefficiencyTax.php">Inefficiency Tax Calculator</a> &nbsp;·&nbsp;
		<a href="https://buymeacoffee.com/accelulator" target="_blank" rel="noopener noreferrer" title="Opens in a new tab">Support Accelulator</a>
		</p>
		<p class="footer-copyright">
		© <?php echo date('Y'); ?> Accelulator Ltd. All rights reserved.
		</p>
	</div>
	</footer>

</div>