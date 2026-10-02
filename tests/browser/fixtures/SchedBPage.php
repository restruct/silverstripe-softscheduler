<?php

namespace Restruct\SchedBrowser;

use Page;
use Restruct\SilverStripe\SoftScheduler\EmbargoExpiryExtension;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * BROWSER-TEST FIXTURE ONLY - a page type with the scheduling extension applied, as the README
 * tells a project to do for its own page types (here through $extensions instead of YAML).
 *
 * Never loaded by a real install: it lives under tests/browser/, which carries a _manifest_exclude
 * marker, and the browser-test runner copies it into a scratch host's app/ before dev/build.
 * Written to load on both Silverstripe 5 and 6.
 *
 * Every dev/build (the runner does one per run) deletes and re-creates the seeded pages, all
 * published, with dates relative to now so the states hold whenever the run happens:
 *
 * - "Sched visible"  no dates                          visible
 * - "Sched window"   embargo passed, expiry ahead      visible
 * - "Sched embargo"  embargo a week ahead              scheduled: 404 for visitors
 * - "Sched expired"  expiry a week ago                 expired: 404 for visitors
 * - "Sched edit"     no dates, for the CMS edit spec
 */
class SchedBPage extends Page
{
    private static $table_name = 'SchedBPage';

    private static $extensions = [
        EmbargoExpiryExtension::class,
    ];

    /** Seeded pages: title => [Embargo offset, Expiry offset] (strtotime() strings or null). */
    public const SEEDS = [
        'Sched visible' => [null, null],
        'Sched window' => ['-1 day', '+1 week'],
        'Sched embargo' => ['+1 week', null],
        'Sched expired' => [null, '-1 week'],
        'Sched edit' => [null, null],
    ];

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();
        # requireDefaultRecords() runs for every class in the hierarchy; seed once, from this class.
        if (static::class !== self::class) {
            return;
        }

        # Old copies are removed with plain SQL, from every stage table. The ORM cannot be used here:
        # the extension's own query filter (augmentSQL) hides scheduled and expired pages from
        # queries on this class on the Live stage, and from everyone without VIEW_DRAFT_CONTENT -
        # which includes this CLI dev/build - so self::get() never finds them, and doArchive() on a
        # copy found through SiteTree leaves the Live row behind (Versioned looks the Live record up
        # through a query on this class, which comes back empty).
        $titles = array_keys(self::SEEDS);
        $placeholders = DB::placeholders($titles);
        $ids = DB::prepared_query(
            "SELECT \"ID\" FROM \"SiteTree\" WHERE \"ClassName\" = ? AND \"Title\" IN ($placeholders)"
            . " UNION SELECT \"ID\" FROM \"SiteTree_Live\" WHERE \"ClassName\" = ? AND \"Title\" IN ($placeholders)",
            array_merge([self::class], $titles, [self::class], $titles)
        )->column();
        if ($ids) {
            $idPlaceholders = DB::placeholders($ids);
            foreach (['SiteTree', 'SiteTree_Live', 'SchedBPage', 'SchedBPage_Live'] as $table) {
                DB::prepared_query("DELETE FROM \"$table\" WHERE \"ID\" IN ($idPlaceholders)", $ids);
            }
            foreach (['SiteTree_Versions', 'SchedBPage_Versions'] as $table) {
                DB::prepared_query("DELETE FROM \"$table\" WHERE \"RecordID\" IN ($idPlaceholders)", $ids);
            }
        }
        $now = DBDatetime::now()->getTimestamp();
        $sort = 100;
        foreach (self::SEEDS as $title => [$embargo, $expiry]) {
            $page = self::create([
                'Title' => $title,
                'URLSegment' => strtolower(str_replace(' ', '-', $title)),
                'Content' => '<p>Content of ' . $title . '</p>',
                'ShowInMenus' => true,
                'Sort' => $sort++,
                'Embargo' => $embargo ? date('Y-m-d H:i:s', strtotime($embargo, $now)) : null,
                'Expiry' => $expiry ? date('Y-m-d H:i:s', strtotime($expiry, $now)) : null,
            ]);
            $page->write();
            $page->publishRecursive();
        }
    }
}
