<?php

namespace Restruct\SoftScheduler\Tests;

use Restruct\SilverStripe\SoftScheduler\EmbargoExpiryExtension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * The extension applied to SiteTree itself rather than to a subclass. SiteTree::onBeforeDelete() lists the
 * children to delete along with a page through AllChildren(), a query on the base class, which is filtered
 * only in this configuration. Regression for #4: a scheduled child was left behind when its parent was
 * unpublished or archived outside the CMS.
 */
class EmbargoExpirySiteTreeTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        SiteTree::class => [
            EmbargoExpiryExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->logOut();
        DBDatetime::set_mock_now('2030-06-15 12:00:00');
    }

    private function publishedPage(string $segment, ?string $embargo, int $parentID = 0): SiteTree
    {
        $page = SiteTree::create([
            'Title' => $segment,
            'URLSegment' => $segment,
            'Embargo' => $embargo,
            'ParentID' => $parentID,
        ]);
        $page->write();
        $page->publishSingle();

        return $page;
    }

    # Plain SQL: the ORM query is exactly what the extension filters
    private function rows(int $id, string $table): int
    {
        return (int) DB::prepared_query("SELECT COUNT(*) FROM \"$table\" WHERE \"ID\" = ?", [$id])->value();
    }

    public function testUnpublishingAParentRemovesItsScheduledChildFromLive()
    {
        $parent = $this->publishedPage('parent', '2030-07-01 00:00:00');
        $child = $this->publishedPage('child', '2030-07-01 00:00:00', $parent->ID);

        $parent->doUnpublish();

        $this->assertSame(0, $this->rows($parent->ID, 'SiteTree_Live'), 'The parent is off Live');
        $this->assertSame(0, $this->rows($child->ID, 'SiteTree_Live'), 'The scheduled child is off Live');
    }

    public function testArchivingAParentRemovesItsScheduledChildFromBothStages()
    {
        $parent = $this->publishedPage('parent2', null);
        $child = $this->publishedPage('child2', '2030-07-01 00:00:00', $parent->ID);

        $parent->doArchive();

        $this->assertSame(0, $this->rows($child->ID, 'SiteTree_Live'), 'The scheduled child is off Live');
        $this->assertSame(0, $this->rows($child->ID, 'SiteTree'), 'The scheduled child is off draft');
    }
}
