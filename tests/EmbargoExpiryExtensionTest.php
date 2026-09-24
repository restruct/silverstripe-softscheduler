<?php

namespace Restruct\SoftScheduler\Tests;

use Restruct\SilverStripe\SoftScheduler\EmbargoExpiryExtension;
use Restruct\SoftScheduler\Tests\Stub\SchedPage;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Group;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * Behavioural tests for EmbargoExpiryExtension: schema, CMS fields, status helpers, the Live-stage
 * query filter and canView().
 *
 * The clock is mocked throughout (DBDatetime::set_mock_now) to a fixed moment, so every date below is
 * relative to NOW rather than to the machine's clock.
 */
class EmbargoExpiryExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        SchedPage::class,
    ];

    protected static $required_extensions = [
        SchedPage::class => [
            EmbargoExpiryExtension::class,
        ],
    ];

    # The mocked "now" for every test
    private const NOW = '2030-06-15 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        # SapphireTest::setUp() logs in a member with ADMIN whenever the test uses a database; these tests
        # are about what a visitor sees, so start anonymous and log in explicitly where needed.
        $this->logOut();
        DBDatetime::set_mock_now(self::NOW);
    }

    /**
     * Create and publish a SchedPage with the given embargo/expiry (null = not set).
     */
    private function publishedPage(string $segment, ?string $embargo, ?string $expiry, array $extra = []): SchedPage
    {
        $page = SchedPage::create(array_merge([
            'Title' => $segment,
            'URLSegment' => $segment,
            'Embargo' => $embargo,
            'Expiry' => $expiry,
        ], $extra));
        $page->write();
        # publishSingle() does not check canPublish(), so no logged-in member is needed here
        $page->publishSingle();

        return $page;
    }

    /**
     * Run $callback with the given reading stage, restoring the previous mode afterwards.
     */
    private function inStage(string $stage, callable $callback)
    {
        return Versioned::withVersionedMode(function () use ($stage, $callback) {
            Versioned::set_stage($stage);
            return $callback();
        });
    }

    /**
     * Run $callback with a front-end ContentController as the current controller.
     */
    private function onFrontEnd(callable $callback)
    {
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        $controller = ContentController::create();
        $controller->setRequest($request);
        $controller->pushCurrent();
        try {
            return $callback();
        } finally {
            $controller->popCurrent();
        }
    }

    public function testExtensionAddsEmbargoAndExpiryColumnsToTheOwnerOnly()
    {
        $schema = DataObject::getSchema();

        $this->assertSame(DBDatetime::class, $schema->fieldSpec(SchedPage::class, 'Embargo', DataObjectSchema::DB_ONLY));
        $this->assertSame(DBDatetime::class, $schema->fieldSpec(SchedPage::class, 'Expiry', DataObjectSchema::DB_ONLY));
        # Stored on the table of the class the extension is applied to, not on SiteTree
        $this->assertSame('SoftSchedTestPage', $schema->tableForField(SchedPage::class, 'Embargo'));
        $this->assertNull($schema->fieldSpec(SiteTree::class, 'Embargo'));
    }

    public function testCmsFieldsAddTheScheduleToggleBeforeContent()
    {
        $fields = SchedPage::create()->getCMSFields();

        $toggle = $fields->fieldByName('Root.Main.SoftScheduler');
        $this->assertInstanceOf(ToggleCompositeField::class, $toggle);

        # Placed directly before Content, in the Main tab
        $names = array_map(function ($field) {
            return $field->getName();
        }, $fields->fieldByName('Root.Main')->Fields()->toArray());
        $this->assertSame(array_search('Content', $names) - 1, array_search('SoftScheduler', $names));

        # Both date fields live inside the toggle, as DatetimeFields
        $this->assertInstanceOf(DatetimeField::class, $toggle->fieldByName('Embargo'));
        $this->assertInstanceOf(DatetimeField::class, $toggle->fieldByName('Expiry'));
    }

    public function testStatusHelpersFollowTheDates()
    {
        $scheduled = $this->publishedPage('scheduled', '2030-07-01 00:00:00', null);
        $expired = $this->publishedPage('expired', null, '2030-06-01 00:00:00');
        $current = $this->publishedPage('current', '2030-06-01 00:00:00', '2030-07-01 00:00:00');
        $plain = $this->publishedPage('plain', null, null);

        $this->assertTrue($scheduled->getScheduledStatus());
        $this->assertFalse($scheduled->getExpiredStatus());
        $this->assertFalse($scheduled->publishedStatus());

        $this->assertFalse($expired->getScheduledStatus());
        $this->assertTrue($expired->getExpiredStatus());
        $this->assertFalse($expired->publishedStatus());

        $this->assertFalse($current->getScheduledStatus());
        $this->assertFalse($current->getExpiredStatus());
        $this->assertTrue($current->publishedStatus());
        $this->assertTrue($current->getEmbargoIsSet());
        $this->assertTrue($current->getExpiryIsSet());

        $this->assertTrue($plain->publishedStatus());
        $this->assertFalse($plain->getEmbargoIsSet());
        $this->assertFalse($plain->getExpiryIsSet());
    }

    public function testStatusHelpersAreFalseForAnUnpublishedPage()
    {
        $draft = SchedPage::create([
            'Title' => 'draft',
            'Embargo' => '2030-07-01 00:00:00',
            'Expiry' => '2030-06-01 00:00:00',
        ]);
        $draft->write();

        $this->assertFalse($draft->getScheduledStatus());
        $this->assertFalse($draft->getExpiredStatus());
        $this->assertFalse($draft->getEmbargoIsSet());
        $this->assertFalse($draft->getExpiryIsSet());
    }

    public function testStatusFlagsMarkScheduledAndExpiredPages()
    {
        $scheduled = $this->publishedPage('scheduled', '2030-07-01 00:00:00', null);
        $expired = $this->publishedPage('expired', null, '2030-06-01 00:00:00');
        $plain = $this->publishedPage('plain', null, null);

        $this->assertArrayHasKey('status-scheduled', $scheduled->getStatusFlags(false));
        $this->assertArrayNotHasKey('status-expired', $scheduled->getStatusFlags(false));
        $this->assertArrayHasKey('status-expired', $expired->getStatusFlags(false));
        $this->assertArrayNotHasKey('status-scheduled', $plain->getStatusFlags(false));
        $this->assertArrayNotHasKey('status-expired', $plain->getStatusFlags(false));
    }

    public function testScheduledStatusDataColumnDescribesBothDates()
    {
        $scheduled = $this->publishedPage('scheduled', '2030-07-01 00:00:00', '2030-08-01 00:00:00');
        $html = (string) $scheduled->ScheduledStatusDataColumn();
        $this->assertStringContainsString('title="Embargo active"', $html);
        $this->assertStringContainsString('title="Scheduled to expire"', $html);
        $this->assertStringContainsString('<br />', $html);

        $past = $this->publishedPage('past', '2030-06-01 00:00:00', '2030-06-10 00:00:00');
        $html = (string) $past->ScheduledStatusDataColumn();
        $this->assertStringContainsString('title="Embargo expired"', $html);
        $this->assertStringContainsString('title="Expired/unpublished"', $html);

        # Nothing set: empty column
        $plain = $this->publishedPage('plain', null, null);
        $this->assertSame('', (string) $plain->ScheduledStatusDataColumn());
    }

    public function testLiveQueriesHideEmbargoedAndExpiredPages()
    {
        $this->publishedPage('scheduled', '2030-07-01 00:00:00', null);
        $this->publishedPage('expired', null, '2030-06-01 00:00:00');
        $this->publishedPage('current', '2030-06-01 00:00:00', '2030-07-01 00:00:00');
        $this->publishedPage('plain', null, null);

        $segments = $this->inStage(Versioned::LIVE, function () {
            return SchedPage::get()->sort('URLSegment')->column('URLSegment');
        });

        $this->assertSame(['current', 'plain'], $segments);
    }

    public function testDraftQueriesShowEverythingOnlyToDraftViewers()
    {
        $this->publishedPage('scheduled', '2030-07-01 00:00:00', null);
        $this->publishedPage('plain', null, null);

        # Anonymous on the draft stage: filtered like Live
        $anonymous = $this->inStage(Versioned::DRAFT, function () {
            return SchedPage::get()->sort('URLSegment')->column('URLSegment');
        });
        $this->assertSame(['plain'], $anonymous);

        # A draft viewer on the draft stage sees the embargoed page too
        $this->logInWithPermission('VIEW_DRAFT_CONTENT');
        $viewer = $this->inStage(Versioned::DRAFT, function () {
            return SchedPage::get()->sort('URLSegment')->column('URLSegment');
        });
        $this->assertSame(['plain', 'scheduled'], $viewer);
    }

    /**
     * Regression: a scheduled page fetched through a SiteTree query (get_by_link, menus, Children())
     * lazy-loads its own class's columns with a second query, and the Live filter emptied that query,
     * so Embargo and Expiry read as null and the page looked unscheduled to every check.
     */
    public function testAScheduledPageLoadedThroughSiteTreeKeepsItsOwnFields()
    {
        $this->publishedPage('scheduled', '2030-07-01 00:00:00', '2030-08-01 00:00:00');

        $page = $this->inStage(Versioned::LIVE, function () {
            return SiteTree::get()->filter('URLSegment', 'scheduled')->first();
        });

        $this->assertInstanceOf(SchedPage::class, $page);
        $this->assertSame('2030-07-01 00:00:00', $page->Embargo);
        $this->assertSame('2030-08-01 00:00:00', $page->Expiry);
        $this->assertTrue($page->getScheduledStatus());
    }

    /**
     * Regression: the filter compared against the database server's NOW(), not Silverstripe's clock.
     * The two differ whenever the MySQL session time zone differs from PHP's (a UTC database behind an
     * Europe/Amsterdam site shifts every embargo by one or two hours), and a mocked clock was ignored.
     * Here the mocked clock is years before the real one: by Silverstripe's clock the page is still
     * embargoed, by the database server's clock it is not.
     */
    public function testLiveQueryFilterUsesSilverstripesClockNotTheDatabaseServers()
    {
        DBDatetime::set_mock_now('2001-01-01 00:00:00');
        $this->publishedPage('future-by-ss-clock', '2002-01-01 00:00:00', null);
        $this->publishedPage('expired-by-ss-clock', null, '2000-01-01 00:00:00');
        $this->publishedPage('current-by-ss-clock', null, '2002-01-01 00:00:00');

        $segments = $this->inStage(Versioned::LIVE, function () {
            return SchedPage::get()->column('URLSegment');
        });

        $this->assertSame(['current-by-ss-clock'], $segments);
    }

    /**
     * Regression: augmentSQL() called Controller::curr() unguarded, which on Silverstripe 5 raises
     * E_USER_WARNING "No current controller available" for every query run without a controller
     * (CLI scripts, queue runners). Framework 6 returns null silently, so this can only fail on 5.
     */
    public function testLiveQueryRunsWithoutACurrentController()
    {
        $this->publishedPage('scheduled', '2030-07-01 00:00:00', null);
        $this->publishedPage('plain', null, null);

        # Empty the controller stack for the duration of the query, then restore it
        $popped = [];
        while ($current = $this->currentControllerOrNull()) {
            $current->popCurrent();
            $popped[] = $current;
        }
        try {
            $segments = $this->inStage(Versioned::LIVE, function () {
                return SchedPage::get()->column('URLSegment');
            });
        } finally {
            foreach (array_reverse($popped) as $controller) {
                $controller->pushCurrent();
            }
        }

        # And the filter still applies without a controller
        $this->assertSame(['plain'], $segments);
    }

    private function currentControllerOrNull(): ?Controller
    {
        # has_curr() exists on framework 5 only; on 6 curr() is silently nullable
        if (method_exists(Controller::class, 'has_curr') && !Controller::has_curr()) {
            return null;
        }
        return Controller::curr();
    }

    /**
     * Regression: canView() returned TRUE whenever it did not deny, and DataObject::extendedCan() lets
     * any non-null extension answer override the page's own checks. So a page restricted to logged-in
     * users (or specific groups) became viewable by anyone as soon as this extension was applied.
     */
    public function testCanViewDoesNotOverrideThePagesOwnViewerRestrictions()
    {
        $restricted = $this->publishedPage('restricted', null, null, [
            'CanViewType' => InheritedPermissions::LOGGED_IN_USERS,
        ]);

        $this->assertFalse($restricted->canView(), 'Anonymous must not see a logged-in-only page');
        $this->onFrontEnd(function () use ($restricted) {
            $this->assertFalse($restricted->canView(), 'Not on the front end either');
        });

        $this->logInWithPermission('SOME_UNRELATED_PERMISSION');
        $this->assertTrue($restricted->canView(), 'A logged-in member may');
    }

    /**
     * Same defect, draft-viewer branch: VIEW_DRAFT_CONTENT lets a member see scheduled pages, but it
     * must not also lift a page's viewer-group restriction (it answered true there as well).
     */
    public function testCanViewDoesNotLetDraftViewersPastViewerGroups()
    {
        $group = Group::create(['Title' => 'Insiders']);
        $group->write();
        $restricted = $this->publishedPage('insiders', null, null, [
            'CanViewType' => InheritedPermissions::ONLY_THESE_USERS,
        ]);
        $restricted->ViewerGroups()->add($group);

        $this->logInWithPermission('VIEW_DRAFT_CONTENT');

        $this->assertFalse($restricted->canView());
    }

    /**
     * Regression: the front-end check compared the controller against the unqualified string
     * "ContentController", which never matches the namespaced class, so embargoed and expired pages
     * reported canView() true on the front end (and showed up in menus built from SiteTree lists).
     */
    public function testCanViewDeniesEmbargoedAndExpiredPagesOnTheFrontEnd()
    {
        $scheduled = $this->publishedPage('scheduled', '2030-07-01 00:00:00', null);
        $expired = $this->publishedPage('expired', null, '2030-06-01 00:00:00');
        $plain = $this->publishedPage('plain', null, null);

        $this->onFrontEnd(function () use ($scheduled, $expired, $plain) {
            $this->assertFalse($scheduled->canView());
            $this->assertFalse($expired->canView());
            $this->assertTrue($plain->canView());
        });

        # A draft viewer still sees them on the front end
        $this->logInWithPermission('VIEW_DRAFT_CONTENT');
        $this->onFrontEnd(function () use ($scheduled, $expired) {
            $this->assertTrue($scheduled->canView());
            $this->assertTrue($expired->canView());
        });
    }

    public function testCanViewDoesNotHideScheduledPagesOutsideTheFrontEnd()
    {
        # Outside a ContentController (the CMS, CLI, a plain controller) the dates do not deny access:
        # CMS users without VIEW_DRAFT_CONTENT must still see the page in the site tree.
        $scheduled = $this->publishedPage('scheduled', '2030-07-01 00:00:00', null);

        $this->assertTrue($scheduled->canView());
    }

    /**
     * Regression: canView($member) must answer for the member it is asked about. It used to check the
     * CURRENT user's VIEW_DRAFT_CONTENT, so code asking about another member while an editor is logged
     * in (a per-recipient digest, a sitemap built in an editor's session) saw scheduled and expired
     * pages as viewable for that member.
     */
    public function testCanViewAnswersForTheMemberPassedInNotTheCurrentUser()
    {
        $scheduled = $this->publishedPage('scheduled', '2030-07-01 00:00:00', null);
        $visitor = Member::create(['FirstName' => 'Visitor', 'Email' => 'visitor@example.com']);
        $visitor->write();

        # The logged-in editor may see the scheduled page; the member passed in, who lacks the
        # permission, may not
        $this->logInWithPermission('VIEW_DRAFT_CONTENT');
        $this->onFrontEnd(function () use ($scheduled, $visitor) {
            $this->assertTrue($scheduled->canView(), 'The logged-in draft viewer may');
            $this->assertFalse($scheduled->canView($visitor), 'The member asked about may not');
        });
    }

    /**
     * Inside the CMS (a LeftAndMain controller on the stack) nothing is filtered or denied, also on the
     * Live stage and for CMS users without VIEW_DRAFT_CONTENT: the site tree and GridFields must list
     * scheduled and expired pages so editors can find and change them.
     */
    public function testNothingIsHiddenInsideTheCms()
    {
        $scheduled = $this->publishedPage('scheduled', '2030-07-01 00:00:00', null);
        $this->publishedPage('expired', null, '2030-06-01 00:00:00');
        $this->publishedPage('plain', null, null);

        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $request = new HTTPRequest('GET', '/admin/pages');
        $request->setSession(new Session([]));
        $cms = LeftAndMain::create();
        $cms->setRequest($request);
        $cms->pushCurrent();
        try {
            $segments = $this->inStage(Versioned::LIVE, function () {
                return SchedPage::get()->sort('URLSegment')->column('URLSegment');
            });
            $canView = $scheduled->canView();
        } finally {
            $cms->popCurrent();
        }

        $this->assertSame(['expired', 'plain', 'scheduled'], $segments);
        $this->assertTrue($canView);
    }
}
