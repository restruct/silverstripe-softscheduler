<?php

namespace Restruct\SoftScheduler\Tests;

use Restruct\SilverStripe\SoftScheduler\EmbargoExpiryExtension;
use Restruct\SoftScheduler\Tests\Stub\FailingDeleteExtension;
use Restruct\SoftScheduler\Tests\Stub\SchedPage;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Versioned\Versioned;

/**
 * The fix for #4 lifts the Live filter while an unpublish or archive runs. These tests check that an
 * unpublish which throws half-way does not leave the filter lifted for the rest of the process: in a queue
 * runner that would expose embargoed pages to every later job.
 *
 * The two tests run in declaration order on purpose: the second one checks that nothing carries over from
 * the first into a following test in the same process.
 */
class EmbargoExpiryFailedUnpublishTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SchedPage::class,
    ];

    # FailingDeleteExtension after ours, as a third-party module's extension would be
    protected static $required_extensions = [
        SchedPage::class => [
            EmbargoExpiryExtension::class,
            FailingDeleteExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        # A visitor, not the ADMIN that SapphireTest logs in for database tests
        $this->logOut();
        DBDatetime::set_mock_now('2030-06-15 12:00:00');
        FailingDeleteExtension::$throwOnDelete = false;
    }

    protected function tearDown(): void
    {
        # A public static on a test class must not outlive it
        FailingDeleteExtension::$throwOnDelete = false;
        parent::tearDown();
    }

    private function publishedPage(string $segment, ?string $embargo, ?string $expiry): SchedPage
    {
        $page = SchedPage::create([
            'Title' => $segment,
            'URLSegment' => $segment,
            'Embargo' => $embargo,
            'Expiry' => $expiry,
        ]);
        $page->write();
        $page->publishSingle();

        return $page;
    }

    private function liveSegments(): array
    {
        return Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::LIVE);
            return SchedPage::get()->sort('URLSegment')->column('URLSegment');
        });
    }

    public function testAScheduledPageStaysHiddenAfterAnUnpublishThatThrew()
    {
        $this->publishedPage('embargoed', '2030-07-01 00:00:00', null);
        $victim = $this->publishedPage('victim', null, null);

        FailingDeleteExtension::$throwOnDelete = true;
        try {
            $victim->doUnpublish();
            $this->fail('The unpublish was expected to throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure inside delete()', $e->getMessage());
        }
        FailingDeleteExtension::$throwOnDelete = false;

        # "The next job in the same runner": a visitor's Live listing of the extended class
        $this->assertSame(['victim'], $this->liveSegments(), 'The embargoed page must stay hidden');
    }

    public function testTheFilterStillAppliesInTheNextTest()
    {
        $this->publishedPage('embargoed2', '2030-07-01 00:00:00', null);
        $this->publishedPage('plain2', null, null);

        $this->assertSame(['plain2'], $this->liveSegments());
    }
}
