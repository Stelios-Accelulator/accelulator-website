<?php
require_once('../includes/header.php');

 // CONFIG
 $ARTICLES_DIR      = realpath(__DIR__ . '/../articles');      // PHP files
 $MEDIA_DIR_FS      = realpath(__DIR__ . '/../assets/post-media'); // must exist!
 $HERO_PATTERN_URL  = '/assets/post-media/%s/hero.jpg';        // public URL
 $HERO_PATTERN_FS   = $MEDIA_DIR_FS . '/%s/hero.jpg';          // filesystem

/**
 * Helpers
 */
function human_date_from_filename($file) {
	// expects 2025-11-01_1454_slug.php
	if (preg_match('#(\d{4})-(\d{2})-(\d{2})_#', basename($file), $m)) {
		return date('j F Y', strtotime("$m[1]-$m[2]-$m[3]"));
	}
	return '';
}
function slug_from_filename($file) {
	return preg_replace('/\.php$/', '', basename($file));
}
function extract_between($html, $start, $end) {
	$p1 = stripos($html, $start);
	if ($p1 === false) return null;
	$p1 += strlen($start);
	$p2 = stripos($html, $end, $p1);
	if ($p2 === false) return null;
	return trim(substr($html, $p1, $p2 - $p1));
}
function extract_title($html) {
	if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m)) return trim(strip_tags($m[1]));
	return 'Untitled';
}
function extract_summary($html) {
	// Prefer your Summary card content, else first <p>
	$sum = extract_between($html, '<section class="summary-card">', '</section>');
	if ($sum) {
		// drop any headings like <h2>Summary</h2> that appear in the card
		$sum = preg_replace('#<h1[^>]*>.*?</h1>|<h2[^>]*>.*?</h2>|<h3[^>]*>.*?</h3>#is', '', $sum);
		$txt = strip_tags($sum);
	} else {
		if (preg_match('#<p>(.*?)</p>#is', $html, $m)) $txt = strip_tags($m[1]); else $txt = '';
	}
	$txt = preg_replace('/\s+/', ' ', $txt);
	return mb_strimwidth($txt, 0, 220, '…', 'UTF-8');
}
function extract_tags_meta($html) {
	// Optional: allow a simple meta tag list inside a comment for speed
	// <!-- tags: forecasting, thought-leadership -->
	if (preg_match('/<!--\s*tags:\s*(.*?)\s*-->/', $html, $m)) {
		$raw = strtolower($m[1]);
		$arr = array_filter(array_map('trim', explode(',', $raw)));
		return $arr;
	}
	return []; // no tags
}

/**
 * Build article list
 */
$files = glob($ARTICLES_DIR . '/*.php');
$items = [];
foreach ($files as $f) {
	$html = file_get_contents($f);
	$title = extract_title($html);
	$summary = extract_summary($html);
	$date_human = human_date_from_filename($f);
	$slug = slug_from_filename($f);
	$href = "/articles/" . basename($f);
	$tags = extract_tags_meta($html);

	$hero_url = sprintf($HERO_PATTERN_URL, $slug);
	$hero_fs  = sprintf($HERO_PATTERN_FS,  $slug);
	$has_hero = file_exists($hero_fs);

	$items[] = [
		'title' => $title,
		'summary' => $summary,
		'date' => $date_human,
		'href' => $href,
		'hero' => $has_hero ? $hero_url : null,
		'slug' => $slug,
		'tags' => $tags,
		'time' => filemtime($f),
	];
}
usort($items, fn($a,$b) => $b['time'] <=> $a['time']);

/** Tag filter */
$activeTag = isset($_GET['tag']) ? strtolower(trim($_GET['tag'])) : '';
if ($activeTag) {
	$items = array_values(array_filter($items, function($it) use ($activeTag) {
		return in_array($activeTag, $it['tags']);
	}));
}
?>
<style>
/* ======== Article Hub ======== */
.hub { max-width: 1100px; margin: 0 auto; padding: 2.25rem 1.25rem 4rem; }
.hub-header { display:flex; align-items:end; justify-content:space-between; gap:1rem; margin-bottom:1.25rem; }
.hub-title { margin:0; line-height:1.15; }
.hub-meta { opacity:.75; }

.tag-row { display:flex; flex-wrap:wrap; gap:.5rem; margin:.5rem 0 1.25rem; }
.tag { display:inline-flex; align-items:center; gap:.4rem; padding:.3rem .6rem; border-radius:999px;
	   background: rgba(0,0,0,.05); font-size:.9rem; text-decoration:none; color:inherit; }
.tag.active { background:#111; color:#fff; }

.grid { display:grid; grid-template-columns: 1fr; gap: 1rem; }
@media (min-width:720px){ .grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width:1040px){ .grid { grid-template-columns: repeat(3, 1fr); } }

.card { background:#fff; border:1px solid rgba(0,0,0,.08); border-radius:14px; overflow:hidden;
		box-shadow:0 8px 24px rgba(0,0,0,.05); display:flex; flex-direction:column; }
.card-media { aspect-ratio: 16 / 9; background: radial-gradient(800px 240px at 60% -40%, rgba(0,0,0,.06), transparent 60%); position:relative; }
.card-media img { width:100%; height:100%; object-fit:cover; display:block; }
.card-media svg { position:absolute; inset:0; width:100%; height:100%; }

.card-body { padding:1rem 1rem 1.1rem; }
.card-title { margin:.1rem 0 .25rem; line-height:1.25; }
.card-title a { text-decoration:none; }
.card-title a:hover { text-decoration:underline; }
.card-meta { font-size:.9rem; opacity:.7; margin-bottom:.35rem; }
.card-summary { margin:.4rem 0 0; }

.card-tags { display:flex; flex-wrap:wrap; gap:.35rem; margin-top:.6rem; }
.card-tags .tag { font-size:.8rem; background:rgba(0,0,0,.04); }

.hub-cta { 
  text-align:center; background: radial-gradient(1200px 400px at 50% -20%, rgba(0,0,0,.06), transparent 60%);
  border:1px solid rgba(0,0,0,.06); border-radius:14px; padding:1.8rem 1rem; margin-top:1.5rem;
}
.hub-cta .btn { display:inline-block; margin-top:.6rem; padding:.6rem 1rem; border-radius:8px; background:#111; color:#fff; text-decoration:none; font-weight:600; }
.hub-cta .btn:hover { transform: translateY(-1px); opacity:.95; }

/* Placeholder SVG uses currentColor */
.card-media.placeholder { color: rgba(0,0,0,.3); }

.card-readmore { margin:.45rem 0 0; }
.card-readmore a { text-decoration:none; font-weight:600; }
.card-readmore a:hover { text-decoration:underline; }
</style>
<div class="hub">
  <div class="hub-header">
	<h1 class="hub-title">Articles</h1>
	<div class="hub-meta"><?php echo count($items); ?> available</div>
  </div>
  <!-- PATTERN_DEBUG url="<?php echo htmlspecialchars($HERO_PATTERN_URL); ?>" fs="<?php echo htmlspecialchars($HERO_PATTERN_FS); ?>" -->

  <!-- Optional tags row (collect all tags that exist) -->
  <?php
	$allTags = [];
	foreach ($items as $it) foreach ($it['tags'] as $t) $allTags[$t]=true;
	$allTags = array_keys($allTags);
	if ($allTags) {
	  echo '<div class="tag-row">';
	  echo '<a class="tag'.(!$activeTag?' active':'').'" href="/pages/articles.php">All</a>';
	  foreach ($allTags as $t) {
		$is = ($activeTag===$t) ? ' active' : '';
		echo '<a class="tag'.$is.'" href="/pages/articles.php?tag='.urlencode($t).'">'.htmlspecialchars(ucwords($t)).'</a>';
	  }
	  echo '</div>';
	}
  ?>

  <div class="grid">
	<?php foreach ($items as $it): ?>
	  <article class="card">
		  <?php if (!$has_hero): ?>
			<!-- DEBUG: missing hero
				 slug=<?php echo htmlspecialchars($slug); ?>
				 hero_fs=<?php echo htmlspecialchars($hero_fs); ?>
				 exists=<?php echo file_exists($hero_fs) ? '1' : '0'; ?> -->
		  <?php else: ?>
			<!-- DEBUG: hero_url=<?php echo htmlspecialchars($hero_url); ?> -->
		  <?php endif; ?>
		<div class="card-media <?php echo $it['hero'] ? '' : 'placeholder'; ?>">
		  <?php if ($it['hero']): ?>
			<img src="<?php echo htmlspecialchars($it['hero']); ?>" alt="">
		  <?php else: ?>
			<!-- simple inline placeholder -->
			<svg viewBox="0 0 800 450" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Article image placeholder">
			  <rect x="0" y="0" width="800" height="450" fill="currentColor" opacity=".06"/>
			  <g fill="none" stroke="currentColor" stroke-width="3" opacity=".35">
				<path d="M40,360 C180,280 260,320 400,260 S620,220 760,140" />
				<rect x="540" y="80" width="220" height="120" rx="12" opacity=".25"/>
				<path d="M540,160 L760,160" opacity=".25"/>
			  </g>
			</svg>
		  <?php endif; ?>
		</div>
		<div class="card-body">
		  <div class="card-meta"><?php echo htmlspecialchars($it['date']); ?></div>
		  <h3 class="card-title"><a href="<?php echo htmlspecialchars($it['href']); ?>"><?php echo htmlspecialchars($it['title']); ?></a></h3>
		  <p class="card-summary"><?php echo htmlspecialchars($it['summary']); ?></p>
		  <p class="card-readmore"><a href="<?php echo htmlspecialchars($it['href']); ?>">Read more…</a></p>
		  <?php if (!empty($it['tags'])): ?>
			<div class="card-tags">
			  <?php foreach ($it['tags'] as $tg): ?>
				<a class="tag" href="/pages/articles.php?tag=<?php echo urlencode($tg); ?>"><?php echo htmlspecialchars(ucwords($tg)); ?></a>
			  <?php endforeach; ?>
			</div>
		  <?php endif; ?>
		</div>
	  </article>
	<?php endforeach; ?>
  </div>

  <div class="hub-cta">
	<h3>Be first to try StaffCast</h3>
	<p>Join the beta for Accelulator’s people-cost forecasting — built by finance, for finance.</p>
	<a class="btn" href="https://accelulator.com/includes/register.php">Get beta access</a>
  </div>
</div>

<?php require_once('../includes/footer.php'); ?>