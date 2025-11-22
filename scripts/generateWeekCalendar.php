<?php
require_once __DIR__ . '/../includes/functions.php'; // has $pdo

/**
 * Build a 52/53-week Monday–Sunday calendar for a given company prefix.
 *
 * @param int      $companyRef   e.g. 0, 1, 2
 * @param DateTime $yearStart    Monday that is week 1
 * @param int      $weeksInYear  52 or 53
 */
function buildWeekCalendar(int $companyRef, DateTime $yearStart, int $weeksInYear): void
{
	global $pdo;

	$t_week_calendar = $companyRef . '_week_calendar';

	$pdo->beginTransaction();

	// Clear existing rows for this calendar year (optional but useful while testing)
	$delete = $pdo->prepare("
		DELETE FROM {$t_week_calendar}
		WHERE CALENDAR_YEAR = :year
	");
	$delete->execute([
		':year' => (int)$yearStart->format('Y'),
	]);

	$insert = $pdo->prepare("
		INSERT INTO {$t_week_calendar}
		(WEEK_START, WEEK_END, WEEK_OF_YEAR, CALENDAR_YEAR, CALENDAR_MONTH, IS_WEEK_53)
		VALUES (:start, :end, :week_no, :cal_year, :cal_month, :is_53)
	");

	$weekStart = clone $yearStart;

	for ($weekNum = 1; $weekNum <= $weeksInYear; $weekNum++) {

		$weekEnd = (clone $weekStart)->modify('+6 days');

		// Mid-point (Thursday) decides which calendar month the week mostly belongs to
		$midPoint      = (clone $weekStart)->modify('+3 days');
		$calendarYear  = (int)$midPoint->format('Y');
		$calendarMonth = (int)$midPoint->format('m');

		$insert->execute([
			':start'    => $weekStart->format('Y-m-d'),
			':end'      => $weekEnd->format('Y-m-d'),
			':week_no'  => $weekNum,
			':cal_year' => $calendarYear,
			':cal_month'=> $calendarMonth,
			':is_53'    => ($weeksInYear === 53 && $weekNum === 53) ? 1 : 0,
		]);

		$weekStart->modify('+7 days');
	}

	$pdo->commit();
}

// Quick manual run for your demo company:
// Adjust these three values and hit the script once in a browser or CLI.
$companyRef  = 7;                          // 0_actuals, 0_week_calendar
$yearStart   = new DateTime('2025-01-06'); // first Monday of your 2025 pattern
$weeksInYear = 52;                         // flip to 53 for a week-53 year

buildWeekCalendar($companyRef, $yearStart, $weeksInYear);

echo "Week calendar generated for company {$companyRef}.\n";