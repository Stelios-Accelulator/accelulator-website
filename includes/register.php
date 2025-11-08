<?php
// are we being loaded into another page (like the homepage)?
$inject = isset($_GET['inject']);

if (!$inject) {
	require_once __DIR__ . '/header.php';
}
require_once __DIR__ . '/functions.php';
?>
<style>
/* make register use same visual language as login */
.register-shell{
	max-width: 1180px;
	margin: 0 auto;
	padding: 2.5rem 1rem 3rem;
	display: flex;
	gap: 2rem;
	align-items: flex-start;
}
.register-left{
	flex: 1 1 55%;
	display: grid;
	gap: 1.25rem;
}
.register-intro{
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 14px;
	padding: 1.5rem 1.4rem 1.25rem;
	box-shadow: 0 4px 12px rgba(0,0,0,.025);
}
.register-intro h1{
	margin-top: 0;
	margin-bottom: .4rem;
}
.register-intro p{
	margin-top: 0;
	color: #444;
}
.register-benefits{
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(185px,1fr));
	gap: 1rem;
}
.register-benefits .reg-card{
	background: #fff;
	border: 1px solid #edf0f3;
	border-radius: 12px;
	padding: .85rem .85rem .75rem;
}
.register-benefits .reg-card h3{
	font-size: .95rem;
	margin-bottom: .35rem;
}
.register-benefits .reg-card ul{
	padding-left: 1.1rem;
	margin: 0;
}
.register-benefits .reg-card li{
	font-size: .8rem;
	margin-bottom: .3rem;
}

/* right column: reuse auth-card look from login */
.register-form-wrap{
	flex: 0 0 360px;
}
.register-form-wrap .auth-card{
	width: 100%;
	max-width: 360px;
}
.register-form-wrap .auth-title{
	margin-bottom: 0.25rem;
}
.register-form-wrap .auth-subtitle{
	margin-bottom: 1rem;
}

/* stack on mobile */
@media (max-width: 960px){
	.register-shell{
		flex-direction: column;
	}
	.register-form-wrap{
		max-width: 460px;
	}
}
</style>

<div class="register-shell">
	<!-- LEFT: marketing / reassurance -->
	<div class="register-left">
		<div class="register-intro">
			<h1>Join Accelulator free</h1>
			<p>Get clarity on your people costs in minutes. Create your account to access <strong>StaffCast</strong> and upcoming tools.</p>
			<p><strong>No card required.</strong> You can invite more seats later.</p>
		</div>

		<div class="register-benefits">
			<div class="reg-card">
				<h3>CFOs &amp; FDs</h3>
				<ul>
					<li>See actuals, outturn, forecast</li>
					<li>Spot overspends early</li>
					<li>Share board-ready views</li>
				</ul>
			</div>
			<div class="reg-card">
				<h3>HR Leads</h3>
				<ul>
					<li>Align hiring to budget</li>
					<li>Track role changes</li>
					<li>Test delayed hires / pay rises</li>
				</ul>
			</div>
			<div class="reg-card">
				<h3>Budget Owners</h3>
				<ul>
					<li>One shared view</li>
					<li>No spreadsheet wrangling</li>
					<li>Fast to update</li>
				</ul>
			</div>
			<div class="reg-card">
				<h3>Security</h3>
				<ul>
					<li>UK-based</li>
					<li>Encrypted employee data</li>
					<li>GDPR-aware</li>
				</ul>
			</div>
		</div>
	</div>

	<!-- RIGHT: actual register form -->
	<div class="register-form-wrap">
		<div class="auth-card">
			<div class="auth-header">
				<h2 class="auth-title">Create your free account</h2>
				<p class="auth-subtitle">Access StaffCast and your finance tools.</p>
			</div>

			<form id="registrationForm" method="post" action="/includes/registerUser.php">
				<input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">

				<div class="form-field">
					<label for="first_name">First name</label>
					<input id="first_name" name="first_name" type="text" class="form-input" placeholder="Your firstname" required>
				</div>

				<div class="form-field">
					<label for="last_name">Surname</label>
					<input id="last_name" name="last_name" type="text" class="form-input" placeholder="Your surname" required>
				</div>

				<div class="form-field">
					<label for="email">Email</label>
					<input id="email" name="email" type="email" class="form-input" placeholder="user@business.com" required>
				</div>

				<div class="form-field password-wrap">
					<label for="reg_password">Password</label>
					<input id="reg_password" name="password" type="password" class="form-input" placeholder="Use at least 8 characters" required>
					<button type="button" class="password-toggle" onclick="toggleRegisterPassword()">Show</button>
				</div>

				<div class="form-field">
					<label for="company_name">Business name</label>
					<input id="company_name" name="company_name" type="text" class="form-input" placeholder="ACME Ltd" required>
				</div>

				<button type="submit" class="btn-auth" style="width:100%; margin-top: .5rem;">Register</button>

				<p class="auth-links" style="margin-top:1rem;">
					Already have an account?
					<a href="/includes/login.php">Sign in</a>
				</p>
			</form>
		</div>
	</div>
</div>

<?php if (!$inject): ?>
	</div> <!-- #contentView -->
<?php endif; ?>

<script>
function toggleRegisterPassword(){
	const input = document.getElementById('reg_password');
	if (!input) return;
	const btn = document.querySelector('.register-form-wrap .password-toggle');
	const show = (input.type === 'password');
	input.type = show ? 'text' : 'password';
	if (btn) btn.textContent = show ? 'Hide' : 'Show';
}
</script>