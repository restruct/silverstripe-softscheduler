<?php

namespace Restruct\SoftScheduler\Tests;

use Restruct\SilverStripe\SoftScheduler\EmbargoExpiryExtension;
use Restruct\SoftScheduler\Tests\Stub\SchedPage;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * What a visitor gets when requesting a scheduled page by its URL.
 *
 * The page is resolved by SiteTree::get_by_link(), which queries SiteTree - so the extension's Live
 * query filter (applied only to queries on the class that carries the extension) never sees this
 * lookup. The request-level check is the only thing standing between a visitor and the page.
 */
class EmbargoExpiryRequestTest extends FunctionalTest
{
    # Required even without a fixture: with silverstripe/session-manager installed, setUp()'s
    # logOut() queries LoginSession, which only exists in a temp database.
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SchedPage::class,
    ];

    protected static $required_extensions = [
        SchedPage::class => [
            EmbargoExpiryExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DBDatetime::set_mock_now('2030-06-15 12:00:00');
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

    public function testAPublishedPageInsideItsWindowRenders()
    {
        $this->publishedPage('current', '2030-06-01 00:00:00', '2030-07-01 00:00:00');

        $response = $this->get('current/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('SOFTSCHED-PAGE-RENDERED', $response->getBody());
    }

    /**
     * Regression: the page rendered normally to anonymous visitors before its embargo date.
     */
    public function testAnEmbargoedPageIsNotFoundForVisitors()
    {
        $this->publishedPage('scheduled', '2030-07-01 00:00:00', null);

        $response = $this->get('scheduled/');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('SOFTSCHED-PAGE-RENDERED', (string) $response->getBody());
    }

    /**
     * Regression: the page kept rendering to anonymous visitors after its expiry date.
     */
    public function testAnExpiredPageIsNotFoundForVisitors()
    {
        $this->publishedPage('expired', null, '2030-06-01 00:00:00');

        $response = $this->get('expired/');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('SOFTSCHED-PAGE-RENDERED', (string) $response->getBody());
    }

    public function testADraftViewerCanStillOpenAnEmbargoedPage()
    {
        $this->publishedPage('scheduled', '2030-07-01 00:00:00', null);
        $this->logInWithPermission('VIEW_DRAFT_CONTENT');

        $response = $this->get('scheduled/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('SOFTSCHED-PAGE-RENDERED', $response->getBody());
    }
}
