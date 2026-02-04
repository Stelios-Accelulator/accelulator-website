<?php
$inject = $_GET['inject'];

if($inject != 1){
	require_once("../includes/header.php");
}
// Optionally pull in your usual bootstrap/header here:
// require_once 'functions.php';
// protectPage();
// $pageTitle = 'See how it works';
// include 'header.php';

// Define your sections + videos in one place
$sections = 
[
	[
		'id'			=> 'adverts',
		'title'		=> 'Why We Built StaffCast',
		'intro'		=> 'Short films about clarity, confidence, and people cost forecasting done properly.',
		'videos'	=> [
			[
				'title'						=> 'People Cost Forecasting, Without the Stress',
				'youtube_id'			=> 'WNwdVpvMT5M',
				'duration'				=> '1:55',
				'label'						=> 'Short film',
				'bullets'					=> [
					'Clarity over guesswork',
					'No surprises, month to month',
					'Your data stays yours, always',
				],
				'description'	=> 'A quiet introduction to the thinking behind StaffCast.'
			],
			
			[
				'title'       => 'Stop Defending People Forecasts You Didn’t Build',
				'youtube_id'  => 'vkbO9-CfOAM',
				'duration'    => '1:17',
				'label'       => 'HR Business Partner',
				'bullets'     => [
					'One shared people forecast for HR and Finance',
					'Built from the same data, assumptions and structure',
					'Clear separation of Opex, Capex and exceptionals',
				],
				'description' => 'A short, experience-led film for HR Business Partners who are tired of being asked to defend forecasts they never truly agreed with.'
			],
			[
				'title'       => 'Stop Reconciling Forecasts. Start Influencing Decisions.',
				'youtube_id'  => 'zElPzJ9orvk',
				'duration'    => '1:07',
				'label'       => 'Finance Business Partner',
				'bullets'     => [
					'One trusted people forecast for the whole organisation',
					'No parallel versions, quiet adjustments or hidden assumptions',
					'Opex, Capex and exceptionals separated properly',
				],
				'description' => 'A short film for Finance Business Partners who want to stop reconciling versions of the forecast and start focusing on insight, challenge and better decisions.'
			],
		]
	],
	[
		'id'    => 'features',
		'title' => 'StaffCast: Making your people costs better',
		'intro' => 'Building features to make your data work for you, safely and quickly.',
		'videos' => [
			[
				'title'       => 'Security by Design',
				'youtube_id'  => 'dAQds-dQTlI',
				'duration'    => '0:18',
				'label'						=> 'StaffCast Security',
				'bullets'     => [
					'256-bit encryption - even we can\'t see it',
					'Full GDPR implementation - completely compliant',
					'Your data is safe - completely unusable outside of StaffCast',
				],
				'description' => 'All sensitive data is encrypted before it reaches our servers. Even if there was a data leak, the universe would end before they could get to your data.'
			],
			[
				'title'       => 'Your Payroll, Your Way',
				'youtube_id'  => 'HbXnamvNARs',
				'duration'    => '2:47',
				'label'						=> 'StaffCast Walkthrough',
				'bullets'     => [
					'No need to change your files: map and upload',
					'Mappings saved so you only have to do it once',
					'Employees automatically created, meaning you can get started in under 3 minutes',
				],
				'description' => 'No need for lengthy, expensive, transformation projects. We take your payroll files as they are, and create your employees automatically.'
			],
		]
	],
	

	// You can add more sections like this later:
	// [
	//     'id' => 'forecasting',
	//     'title' => 'Exploring your forecasts',
	//     'intro' => 'Dive into outturns, scenarios, and monthly views.',
	//     'videos' => [ ... ]
	// ],
];
?>

	<style>
		.seehow-page {
			max-width: 1100px;
			margin: 0 auto;
			padding: 2rem 1.5rem 4rem;
		}

		.seehow-hero {
			text-align: left;
			margin-bottom: 2.5rem;
		}

		.seehow-hero h1 {
			font-size: 2rem;
			margin-bottom: 0.5rem;
		}

		.seehow-hero p {
			max-width: 650px;
			font-size: 1rem;
			line-height: 1.5;
			color: #444;
		}

		.seehow-section {
			margin-bottom: 3rem;
			border-top: 1px solid #eee;
			padding-top: 2rem;
		}

		.seehow-section h2 {
			font-size: 1.4rem;
			margin-bottom: 0.4rem;
		}

		.seehow-section-intro {
			margin-bottom: 1.5rem;
			color: #555;
		}

		.video-row {
			display: flex;
			gap: 1.5rem;
			align-items: flex-start;
			margin-bottom: 2rem;
		}

		.video-thumb {
			flex: 0 0 40%;
			max-width: 420px;
		}

		.video-frame {
			position: relative;
			padding-bottom: 56.25%;
			height: 0;
			overflow: hidden;
			border-radius: 12px;
			box-shadow: 0 2px 8px rgba(0,0,0,0.08);
			background: #000;
		}

		.video-frame iframe {
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			border: 0;
		}

		.video-copy {
			flex: 1;
			min-width: 0;
		}

		.video-copy h3 {
			font-size: 1.1rem;
			margin: 0 0 0.3rem;
		}

		.video-meta {
			font-size: 0.85rem;
			color: #777;
			margin-bottom: 0.6rem;
		}

		.video-points {
			margin: 0 0 0.8rem;
			padding-left: 1.1rem;
		}

		.video-points li {
			margin-bottom: 0.2rem;
		}

		.video-desc {
			margin: 0;
			color: #444;
			line-height: 1.5;
		}

		/* Small screens: stack video above text */
		@media (max-width: 768px) {
			.video-row {
				flex-direction: column;
			}
			.video-thumb {
				flex: 0 0 auto;
				width: 100%;
				max-width: none;
			}
		}
	</style>
</head>
<body>

<main class="seehow-page">
	<section class="seehow-hero">
				 
		<h1>Features</h1>
		<p>
			Watch short, focused clips of StaffCast features. Each video can be played directly on this page or expanded
			to full screen whenever you want a closer look.
		</p>
		
	
	</section>
	<?php foreach ($sections as $section): ?>
		<section id="<?php echo htmlspecialchars($section['id']); ?>" class="seehow-section">
			<h2><?php echo htmlspecialchars($section['title']); ?></h2>
			<?php if (!empty($section['intro'])): ?>
				<p class="seehow-section-intro">
					<?php echo htmlspecialchars($section['intro']); ?>
				</p>
			<?php endif; ?>

			<?php foreach ($section['videos'] as $video): ?>
				<article class="video-row">
					<div class="video-thumb">
						<div class="video-frame">
							<iframe
								loading="lazy"
								src="https://www.youtube.com/embed/<?php echo htmlspecialchars($video['youtube_id']); ?>"
								title="<?php echo htmlspecialchars($video['title']); ?>"
								allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
								allowfullscreen>
							</iframe>
						</div>
					</div>
					<div class="video-copy">
						<h3><?php echo htmlspecialchars($video['title']); ?></h3>
						<?php if (!empty($video['duration'])): ?>
							<p class="video-meta">
								<?php echo htmlspecialchars($video['duration']); ?> · <?php echo htmlspecialchars($video['label']); ?>
							</p>
						<?php endif; ?>

						<?php if (!empty($video['bullets'])): ?>
							<ul class="video-points">
								<?php foreach ($video['bullets'] as $point): ?>
									<li><?php echo htmlspecialchars($point); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php if (!empty($video['description'])): ?>
							<p class="video-desc">
								<?php echo htmlspecialchars($video['description']); ?>
							</p>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</section>
	<?php endforeach; ?>
</main>

<?php
if($inject!=1){
	require_once("../includes/footer.php");
}
?>