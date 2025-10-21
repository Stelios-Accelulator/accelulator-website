<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';
?>

<style>
	/* ===============================
	   Accelulator Numbered Terms List
	   =============================== */
	ol.decimal-points {
	  counter-reset: section;
	  list-style: none;
	  padding-left: 2.5em;
	  margin: 1em 0;
	  color: #333;
	  font-family: "Inter", "Helvetica Neue", Arial, sans-serif;
	  font-size: 15px;
	  line-height: 1.6;
	}
	
	ol.decimal-points > li {
	  counter-increment: section;
	  margin-bottom: 0.75em;
	  position: relative;
	  padding-left: 0.5em;
	}
	
	/* Top-level numbers: 1.0, 2.0, 3.0 ... */
	ol.decimal-points > li::before {
	  content: counter(section) ".0";
	  position: absolute;
	  left: -2.2em;
	  top: 0;
	  font-weight: 600;
	  color: #167a8b;          /* Accelulator teal */
	  font-size: 16px;
	}
	
	/* Sub-lists: 1.1, 1.2, etc. */
	ol.decimal-points ol {
	  counter-reset: subsection;
	  list-style: none;
	  padding-left: 2.2em;
	  margin-top: 0.5em;
	}
	
	ol.decimal-points ol > li {
	  counter-increment: subsection;
	  margin-bottom: 0.5em;
	  position: relative;
	}
	
	/* Sub-level numbering inherits top-level prefix */
	ol.decimal-points ol > li::before {
	  content: counter(section) "." counter(subsection);
	  position: absolute;
	  left: -2.2em;
	  font-weight: 500;
	  color: #20a0b2;          /* lighter accent teal */
	}
	
	/* Optional hover or focus effect for readability */
	ol.decimal-points li:hover::before {
	  color: #125f6e;
	}
</style>
<div class="padded">
	<h1>Subscription Agreement and Terms of Business</h1>
	<ol class="decimal-points">
		<li>
			<h2>Introduction</h2>
			<ol>
				<li>Accelulator Ltd aim to offer our subscribers a quality and professional service at a fair cost. This Subscription Agreement and Terms of Business sets out the basis on which we will provide our professional services.</li>
				<li>Accelulator Ltd is committed to promoting equality and diversity in all of its dealings with subscribers, third parties, and employees. Please ask if you would like to see a copy of our equality and diversity policy.</li>
			</ol>
		</li>
		<li>
			<h2>Service Levels</h2>
			<ol>
				<li>In our service to subscribers, Accelulator will:
					<ul>
						<li>Communicate with the subscriber in plain language;</li>
						<li>Advise and keep the subscriber informed of any changes to the subscription;</li>
						<li>Do our best to reply quickly to correspondence;</li>
						<li>Tell the subscriber about any delays and explain the reasons behind these delays;</li>
						<li>Explain the effect of any important documents;</li>
						<li>Tell the subscriber about staff changes that might affect them;</li>
						<li>Advise the subscriber of any circumstances and risks of which we are aware or consider to be reasonably foreseeable that could affect you as a subscriber;</li>
						<li>Update the subscriber on the costs position at least every year.</li>
					</ul>
				</li>
				<li>You can help us by:
					<ul>
						<li>Giving us clear instructions;</li>
						<li>Safeguarding any documents relevant to your matter;</li>
						<li>Letting us know if you are unsure over any aspect of your subscription;</li>
						<li>Telling us about any important time limits that you are under, or if you are going to be away for any length of time;</li>
						<li>Responding promptly to any questions that arise and providing all documents required in a timely manner.</li>
					</ul>
				</li>
			</ol>
		</li>
		<li>
			<h2>Seat-Based Licensing</h2>
			<ol>
				<li>Subscriptions are licensed on a per-user ("seat") basis. Each active user who accesses or uses Accelulator requires an individual licence associated with your organisation's account. Seats can be reassigned to different team members but may not be shared concurrently.</li>
				<li>Seats may be reassigned to a different team member (for example, a leaver) but may not be shared concurrently by multiple users.</li>
			</ol>
		</li>
		<li>
			<h2>Subscription Instructions</h2>
			<ol>
				<li><strong>Submission of Instructions:</strong> All instructions, requests, or directions by the subscriber under this Subscription Agreement must be submitted through the designated platform found on <a href="https://accelulator.com">www.accelulator.com</a>.</li>
				<li><strong>Authority:</strong> No formal signature is required from the subscriber and an agreement is accepted when the subscriber signs up via the designated platform found on <a href="https://accelulator.com">www.accelulator.com</a> and accepts the terms and conditions of the Subscription Agreement. Accelulator Ltd shall act upon these instructions only from the subscriber (or via delegate through creation of appropriate delegate user entitlement) through the designated platform on <a href="https://accelulator.com">www.accelulator.com</a> and not from any third parties.</li>
				<li><strong>Timeframe for Processing:</strong> Accelulator Ltd will use commercially reasonable efforts to implement such instructions within 20 business days from receipt, unless otherwise notified and agreed.</li>
				<li><strong>Acknowledgement:</strong> Accelulator Ltd shall provide confirmation of receipt of all instructions and payment within 10 business days.</li>
				<li><strong>Limitations:</strong> Accelulator Ltd may refuse to act on any instruction that is unclear, unlawful, or outside the scope of this Subscription Agreement, notifying the subscriber of such refusal within 20 business days.</li>
			</ol>
		</li>
		<li>
			<h2>Subscription Login Details</h2>
			<ol>
				<li>The subscriber shall keep their login credentials, including usernames and passwords, strictly confidential and shall not disclose, share, or permit the use of such credentials by any third party, including colleagues, employers, and employees. Access to the services under this Subscription Agreement is limited solely to the subscriber. Unauthorised use of login details constitutes a material breach of this Subscription Agreement and may result in immediate suspension and/or termination of the subscriber's access to the Services, without prejudice to any other rights or remedies available to the company, and reimbursement may be sought in-line with any damages incurred to such activities.</li>
			</ol>
		</li>
		<li>
			<h2>Charges</h2>
			<ol>
				<li>Our charges are based on a monthly rolling subscription. The subscriber pays in advance for a month starting on the day the subscriber agrees to the Subscription Agreement by signing up via the designated platform via <a href="https://accelulator.com">www.accelulator.com</a>. The subscription renews every month automatically unless cancelled.</li>
				<li>VAT is payable in addition to the subscription price at the applicable rate (currently 20%). We are registered for VAT under GB[insert number here].</li>
				<li>Payments are processed securly by Stripe, our payment partner. Accelulator Ltd does not store card numbers or full payment credentials.</li>
				<li>Please note we do not offer any kind of funding or loan scheme and you will be responsible for all costs quoted and notified to you.</li>
				<li>Accelulator Ltd operates on a tiered subscription model. Please find our subscription costs as follows:
					<ul>
						<li>£20/user/month for Complete Access (Chief Executive Officer, Chief Finance Officer, Finance Director, Human Resources Director, Financial Controller)</li>
						<li>£20/user/month for Functional Manager Access (Directors)</li>
						<li>£15/user/month for Department Manager Access (Head of Departments)</li>
						<li>£10/user/month for Cost Centre Manager Access (Managers of Cost Centres)</li>
						<li>£10/user/month for Analyst Access (Business Partners, Analysts)</li>
						<li>£5/user/month for Line Manager Access (Line Managers, Team Leaders)</li>
						<li>£5/user/month for Audit Access (Auditor, Third Party Limited Access)</li>
						<li>£5/user/month for Administrator Access (System Administrators)</li>
						<li>£5/user/month for Payroll Access (Payroll Administrators)</li>
						<li>Free plan offers full product functionality but allows access to data by only one user and no export functionality - ideal for solo finance teams</li>
					</ul>
				</li>
				<li>Additional user access will require a paid licence/paid subscription.</li>
				<li>Only one free plan offer is available per firm/company/person and any breach of this clause may result in immediate suspension or termination of the subscriber's access to the Services, without any prejudice to any other rights or remedies available to the company.</li>
				<li>Our free plan offers full functionality for a single user but restricts dataset sharing and multi-user collaboration. We reserve the right to modify or discontinue the free plan with prior notice. All data entered under a free plan remains your property and can be exported or deleted upon request.</li>
				<li>Any changes to subscription costs will be notified to you in advance of their taking effect. Please note our costs are subject to inflation adjustment.</li>
			</ol>
		</li>
		<li>
			<h2>Inflation Adjustment</h2>
			<ol>
				<li>Accelulator Ltd reserves the right to increase the Subscription Fees annually to account for inflation. Any such adjustment shall not exceed the percentage change in the UK Consumer Prices Index (CPI) as published by the Office for National Statistics (or a comparable index if the CPI is discontinued) for the preceding 12-month period. Accelulator Ltd will provide the subscriber with at least 30 days' notice (via email) prior to implementing any such adjustment.</li>
				<li>Where Accelulator Ltd decides not to increase the Subscription Fees annually to account for inflation, we reserve the right to increase the Subscription Fees by a higher percentage change than in the UK Consumer Prices Index (CPI) as published by the Office for National Statics (or a comparable index if the CPI is discontinued). However, this shall not exceed the cumulative increase from the last inflation adjustment enacted by Accelulator Ltd.</li>
			</ol>
		</li>
		<li>
			<h2>Cancellation of Subscription</h2>
			<ol>
				<li>Subscribers can cancel at any time. Your subscription will remain active until the end of the current billing period, and no further payments will be taken. We do not offer partial-month refunds.</li>
				<li>Upon termination or cancellation of your account, Accelulator Ltd will delete all associated user data within twelve (12) months unless retention is required for legal, regulatory, or accounting purposes. You may request earlier deletion by contacting us in writing.</li>
			</ol>
		</li>
		<li>
			<h2>Service Availability and Maintenance</h2>
			<ol>
				<li>We aim to provide a reliable service and will schedule planned maintenance outside normal business hours where feasible. We may post service notices in-app when maintenance is planned or when we become aware of incidents affecting availability.</li>
			</ol>
		</li>
		<li>
			<h2>Methods of Communication</h2>
			<ol>
				<li>All communications, notices, and other information relating to this Subscription Agreement may be delivered by email or in-app notifications (if applicable). A communication shall be deemed received: (i) if by email, when sent to the designated email address and no bounce-back is received; (ii) if by in-app or online platform notification, on the date it is posted. Each part agrees to maintain up-to-date contact details for this purpose.</li>
			</ol>
		</li>
		<li>
			<h2>Data Protection and how we use your Information</h2>
			<ol>
				<li>We will collect information about you and keep this on our computers, in our email, in cloud storage, and on paper for a certain period of time. The main reasons for this are to:
					<ul>
						<li>deliver the professional services we have agreed in contract to provide to you; and</li>
						<li>comply with the law. For example, from time to time, we may have to perform checks against new clients and companies such as insolvency or bankruptcy checks, company 'conflicts of interest', and directors checks. This list is not exhaustive.</li>
					</ul>
				</li>
				<li>You can withdraw consent to your information being used in a particular way, but this may limit what we can do for you if anything.</li>
				<li>As a client/subscriber we may also, in the future, send you a newsletter/email or similar and we find that most clients/subscribers find this helpful. We rely upon the 'legitimate interest' we have in maintaining contact with former clients/subscribers to do this in data protection law and your agreement for the purposes of the Privacy & Electronic Communictions Regulation (which can be implied under these Regulations). However we will never share your information with third parties to market to you and will not contact you about non-relevant services/subscriptions. We will make it quick and easy to 'opt out' of future communications in every communication sent. If you already know that you don't want to receive these messages, then you can opt out now by emailing charlotte.miltiadou@accelulator.com.</li>
				<li>Your information will be kept on computer servers within the European Union. If at any point in the future information is to be stored on computer servers outside of the EU, we will inform you of this and of the safeguards in place to ensure its security.</li>
				<li>We do not use your personal information to make 'automated decisions' which affect you.</li>
				<li>We do not sell your personal information or share it with third parties for their marketing. We use a small number of service providers (for example, hosting with <a href="https://www.one.com/en/">one.com</a> and payments with <a href="https://stripe.com/gb">Stripe</a>) solely to deliver the service to you, each under GDPR-compliant data processing terms. Please contact our legal team, <a href="mailto:legal@accelulator.com">legal@accelulator.com</a>, if you would like to request a copy of your personal data held by Accelulator Ltd or if you wish for your personal data to be deleted from our records.</li>
				<li>All information and details inserted by the subscriber on our platform are confidential and visible only to the subscriber’s organisation and its authorised users. Accelulator personnel do not access customer content except where strictly necessary to diagnose or resolve a support request, and in accordance with our Privacy Policy. Personally identifiable information is encrypted and designed to remain inaccessible to Accelulator in plaintext.</li>
				<li>If you have a complaint about how your personal information is being used which we have not been able to address, please note that you may be able to make a complaint to the Information Commissioner's Office (ICO) directly. <strong>By agreeing to these Terms and Conditions you agree to your information being used in the way described above.</strong></li>
			</ol>
		</li>
		<li>
			<h2>Financial Services</h2>
			<ol>
			  <li>We are not authorised by, nor regulated by, the Financial Conduct Authority (FCA). If, while we are acting for you, you need advice on regulated investments or activities, we will refer you to an authorised firm.</li>
			</ol>
		</li>
		<li>
			<h2>Applicable Law</h2>
			<ol>
				<li>Any dispute or legal issue arising from our terms of business will be determined by the law of England and Wales and considered exclusively by the English and Welsh courts.</li>
			</ol>
		</li>
		<li>
			<h2>Professional Indemnity Insurance</h2>
			<ol>
				<li>We maintain professional indemnity insurance. Details of the insurers and the territorial coverage of the policy are available for inspection at our offices.</li>
			</ol>
		</li>
		<li>
			<h2>Complaints</h2>
			<ol>
				<li>If you are unhappy about any aspect of our service, please contact our complaints team <a href="mailto:complaints@accelulator.com">complaints@accelulator.com</a>. We will investigate the problem as quickly as possible in accordance with our complaints procedure.</li>
			</ol>
		</li>
		<li>
			<h2>Support</h2>
			<ol>
				<li>For technical or billing assistance, please contact us at <a href="mailto:contact@accelulator.com">contact@accelulator.com</a>. We aim to respond to all enquiries within five (5) working days.</li>
			</ol>
		</li>
	</ol>
	
	<p>We will post notice of any material updates to these Terms on our website and, where feasible, notify registered users by email at least seven days prior to the change taking effect.</p>
	
	<p>Use of the service is also subject to our <a href="https://accelulator.com/pages/privacy.php">Privacy Policy</a>.</p>
		
	<p><strong>Please note that you do not need to sign this Subscription Agreement, by signing up to a subscription via the Accelulator Ltd platform and ticking the box confirming you have read this Subscription Agreement, you are agreeing to our terms and conditions and entering into a contract with Accelulator Ltd.</strong></p>	
		
		
		
		
		
		
		
		
		
		
		
		
		
		
		
		
		
		
		
		
		
		
		
		
</div>