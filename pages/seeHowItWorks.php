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
$sections = [
	[
		'id'    => 'first-use',
		'title' => 'Getting Started: 15-Minute Setup',
		'intro' => 'Go from system generated payroll file to fully populated StaffCast resources in minutes. No expensive transformation project required!',
		'videos' => [
			[
				'title'       => 'Step 1 – Download the payroll template',
				'youtube_id'  => 'rDi5CMtZiJU',
				'duration'    => '0:43',
				'bullets'     => [
					'Loggin in to StaffCast',
					'Where to find the payroll upload template in StaffCast',
					'Downloading the file',
				],
				'description' => 'In this video you’ll see exactly where to click to download the StaffCast payroll template, so everyone is working from the same structure.'
			],
			[
				'title'       => 'Step 2 – Prepare your payroll file',
				'youtube_id'  => 'Rf6m44xd0Xc',
				'duration'    => '11:23',
				'bullets'     => [
					'Using a Pivot Table to re-structure your payroll data',
					'Populating the required columns',
					'A few tips and tricks to get exactly what you need',
				],
				'description' => 'We walk through preparing your payroll file using example data, so you can see how employee numbers, names, and pay types line up with the template.'
			],
			[
				'title'       => 'Step 3 – Transpose to the upload format',
				'youtube_id'  => 'uAammdUpJEU',
				'duration'    => '1:36',
				'bullets'     => [
					'Mapping your columns to the StaffCast template',
					'Pasting your data into the template',
				],
				'description' => 'Here we show how to take your newly structured payroll data and insert it neatly into the StaffCast upload format.'
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
		<h1>Videos coming soon</h1>
		<!-- 
		<h1>See how StaffCast works</h1>
		<p>
			Watch short, focused walkthroughs that guide you through from beginner to 
			expert at your own pace. Each video can be played directly on this page or expanded
			to full screen whenever you want a closer look.
		</p>
		
	-->
	</section>
	<!--
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
								<?php echo htmlspecialchars($video['duration']); ?> · StaffCast walkthrough
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
-->
</main>

<?php
if($inject!=1){
	require_once("../includes/footer.php");
}
?>