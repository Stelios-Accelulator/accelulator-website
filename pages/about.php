<?php
require_once('../includes/header.php');
?>

<style>
	:root {
		--accel-red: #b03229; /* closer to your homepage button tone */
		--accel-red-soft: rgba(176, 50, 41, 0.08);
		--page-bg: #f5f4f3;
		--card-bg: #fff;
		--text: #333;
	}

	body {
		background: #fff;
	}

	.about-page {
		background: #fff;
	}

	/* hero */
	.about-hero {
		max-width: 1100px;
		margin: 50px auto 20px auto;
		padding: 0 20px;
	}

	.about-hero-inner {
		background: #fff;
		border-radius: 14px;
		border: 1px solid #eee;
		padding: 40px 40px 35px 40px;
		display: flex;
		gap: 40px;
		align-items: center;
		box-shadow: 0 10px 35px rgba(0,0,0,0.02);
	}

	.hero-content {
		flex: 1 1 60%;
	}

	.hero-tag {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		background: var(--accel-red-soft);
		color: var(--accel-red);
		padding: 4px 12px 5px 12px;
		border-radius: 999px;
		font-size: 0.72rem;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		margin-bottom: 16px;
	}

	.about-hero h1 {
		font-size: 2.25rem;
		line-height: 1.08;
		margin-bottom: 12px;
		color: #141414;
	}

	.about-hero p.lead {
		font-size: 1.02rem;
		max-width: 620px;
		margin-bottom: 20px;
	}

	.hero-actions {
		display: flex;
		gap: 12px;
		flex-wrap: wrap;
	}

	.btn-primary {
		background: var(--brand);
		color: #fff;
		border: none;
		padding: 9px 22px;
		border-radius: 6px;
		font-weight: 600;
		text-decoration: none;
		font-size: 0.92rem;
		display: inline-flex;
		align-items: center;
		gap: 6px;
	}

	.btn-secondary {
		background: #fff;
		color: #222;
		border: 1px solid rgba(0,0,0,0.12);
		padding: 9px 18px;
		border-radius: 6px;
		font-weight: 500;
		text-decoration: none;
		font-size: 0.9rem;
	}

	/* sections */
	#content {
		max-width: 1050px;
		margin: 0 auto 60px auto;
		padding: 0 20px;
	}

	.section {
		background: var(--card-bg);
		border-radius: 10px;
		padding: 28px 30px 28px 26px;
		margin-bottom: 26px;
		border: 1px solid #eee;
		position: relative;
	}

	.section::before {
		content: "";
		position: absolute;
		left: 0;
		top: 0;
		height: 100%;
		width: 4px;
		border-radius: 10px 0 0 10px;
		background: linear-gradient(180deg, var(--accel-red) 0%, rgba(176,50,41,0) 85%);
		opacity: 0.4;
	}

	h1, h2 {
		color: #222;
		font-weight: 600;
	}

	.section h1 {
		font-size: 1.4rem;
		margin-bottom: 10px;
	}
	
	#our-approach h2 {
		color: #d43c00;
	}
	
	p {
		font-size: 0.97rem;
		line-height: 1.6;
		margin-bottom: 1em;
		color: var(--text);
	}

	ul {
		margin: 0.6em 0 0.5em 1.4em;
	}

	ul li {
		margin-bottom: 0.4em;
	}

	.highlight {
		color: var(--accel-red);
		font-weight: 600;
	}

	.vision-quote {
		font-style: italic;
		color: #555;
		border-left: 3px solid var(--accel-red);
		padding-left: 14px;
		margin: 18px 0 12px 0;
	}

	@media (max-width: 880px) {
		.about-hero-inner {
			flex-direction: column;
			align-items: flex-start;
			padding: 30px 20px 28px 20px;
		}
		.about-hero h1 {
			font-size: 1.9rem;
		}
		.hero-actions {
			width: 100%;
		}
		.section {
			padding: 22px 18px 22px 18px;
		}
		.section::before {
			width: 3px;
		}
	}
</style>

<div class="about-page">
	<div class="about-hero">
		<div class="about-hero-inner">
			<div class="hero-content">
				<div class="hero-tag">People-cost forecasting for finance teams</div>
				<h1>Built so finance doesn’t have to chase the numbers.</h1>
				<p class="lead">
					Accelulator is a people-cost-first forecasting platform, created by a finance director who was tired of rebuilding the same work every month. It’s for CFOs, HR and budget owners who want one version of the truth — without replacing their whole stack.
				</p>
				<div class="hero-actions">
					<a id='loginRegister' href="#" class="btn-primary">Start for free</a>
					<a href="/#see-how-it-works" class="btn-secondary">See how it works</a>
				</div>
			</div>
			<!-- optional right column later for illustration / stats -->
		</div>
	</div>

	<div id="content">
		<section class="section" id="what-is-accelulator">
			<h1>What is Accelulator?</h1>
			<p><strong>Accelulator</strong> is a suite of forecasting tools designed to make payroll and people cost planning reliable, explainable, and sharable. Instead of fragile spreadsheets, you get structured logic, auditability, and a place the whole business can plug into.</p>
			<p>It was born from day-to-day finance work — monthly closes, board packs, “can you just run me a scenario” requests — and from the realisation that finance shouldn’t have to be the spreadsheet police anymore.</p>
			<ul>
				<li><strong>Consistency:</strong> shared logic across all models.</li>
				<li><strong>Clarity:</strong> see actuals, outturn, and forecast in one view.</li>
				<li><strong>Collaboration:</strong> finance, HR, and managers working from the same data.</li>
			</ul>
		</section>

		<section class="section" id="vision">
			<h1>The Vision</h1>
			<p>We want to build the place finance teams go when they need the truth. Not a BI tool. Not a spreadsheet. A purpose-built forecasting layer that understands people, timing, payroll logic, and how real businesses actually report.</p>
			<div class="vision-quote">
				“When finance teams stop chasing the numbers, they start shaping the future.”
			</div>
			<p>We’re starting with <strong>StaffCast</strong> — monthly people cost forecasting with actuals and outturn — and we’ll move into P&amp;L, cash, and variance workflows next. Same approach, same transparency.</p>
		</section>

		<section class="section" id="our-approach">
			<h1>How We Build</h1>
			<h2>Security, simplicity, ownership</h2>
			<p>All of Accelulator is hand-built in HTML, CSS, JavaScript, and PHP. No bloated frameworks, no mystery third-party modules. That keeps performance sharp and lets us encrypt and handle employee-level data the way we want — with you in control.</p>
			<p>Because we own the code, we can roll out features finance actually asks for: pay elements, employer NI, outturn calculations, manager views, seat-based access for teams.</p>
		</section>

		<section class="section" id="founder">
			<h1>Founded by Finance, Built for Finance</h1>
			<p>Accelulator was created by <strong>Stelios Miltiadou</strong>, a CIMA accountant and interim finance leader who has spent decades building forecasts for PE-backed and high-growth businesses. The patterns were always the same: too many sheets, too many inputs, too much risk.</p>
			<p>Accelulator is his answer: a way for finance to keep control, look professional, and still let the rest of the business in.</p>
		</section>

		<section class="section" id="future">
			<h1>What’s Next</h1>
			<p>Next modules will introduce P&amp;L forecasting, cashflow, and reporting views so finance can give department heads live numbers without exporting to Excel. All tied to the same permissions and seat model you’re already using.</p>
			<p><span class="highlight">Early users shape the roadmap.</span> If you’re using the free plan right now, tell us what’s missing and we’ll prioritise it.</p>
		</section>
	</div>
</div>

<?php 
require_once('../includes/footer.php');
?>