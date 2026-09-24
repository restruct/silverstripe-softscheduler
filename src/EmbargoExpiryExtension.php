<?php


namespace Restruct\SilverStripe\SoftScheduler;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Core\Extension;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
//use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Requirements;

/**
 * PublishScheduler SiteTree Extension
 *
 * Adds a very simple way to schedule (Embargo/Expire) SiteTree items,
 * basically we just add two datetimefields & check if within from canView()
 *
 * @package SoftScheduler
 * @author  Michael van Schaik, partly based on Embargo/Expiry module by Simon Welsh
 * Some parts also extracted from micmania1/silverstripe-blogger
 */
# Extension, not SiteTreeExtension: SiteTreeExtension is deprecated in Silverstripe 5.3 and removed in 6,
# while Extension is the base class on both majors (its private statics such as $db are still merged into
# the owner's config by ExtensionMiddleware).
class EmbargoExpiryExtension extends Extension
{
    private static $db = [
        'Embargo' => DBDatetime::class,
        'Expiry'  => DBDatetime::class,
    ];

    /**
     * Adds EmbargoExpiry time fields to the CMS
     *
     * @param FieldList $fields
     */
    public function updateCMSFields(FieldList $fields)
    {
        $publishDate = DatetimeField::create("Embargo", _t("Scheduler.Embargo", "Page available from"))
            ->setDescription(_t("Scheduler.LeaveEmptyEmbargo", "Leave empty to have page available right away (after publishing)"));

        $unpublishDate = DatetimeField::create("Expiry", _t("Scheduler.Expiry", "Page expires on"))
            ->setDescription(_t("Scheduler.LeaveEmptyExpire", "Leave empty to leave page published indefinitely"));

        # insertBefore(name, field): the (field, name) order of Silverstripe 3 is a TypeError on 5 and 6
        $fields->insertBefore(
            'Content',
            ToggleCompositeField::create(
                'SoftScheduler',
                _t('SoftScheduler.Schedule', 'Schedule publishing & unpublishing of this page'),
                [
                    $publishDate,
                    $unpublishDate
                ]
            )
                ->setHeadingLevel(4)
                ->addExtraClass('stacked')
        );
    }

    /*
     *  Show 'lozenges' for scheduled & expired
     */
    // convenience for use with partial caching
    public function publishedStatus()
    {
        if ( !$this->owner->getScheduledStatus() && !$this->owner->getExpiredStatus() ) {
            return true;
        }

        return false;
    }

    // is scheduled for publication in future
    public function getScheduledStatus()
    {
        if ( !$this->owner->isPublished() ) {
            return false;
        }
        $embargo = $this->owner->dbObject("Embargo");
        //Debug::dump(($this->owner->Embargo)? true: false);
        if ( $this->owner->Embargo && $embargo->InFuture() ) {
            return true;
        }

        return false;
    }

    // has been scheduled for expiry in past
    public function getExpiredStatus()
    {
        if ( !$this->owner->isPublished() ) {
            return false;
        }
        $expiry = $this->owner->dbObject("Expiry");
        if ( $this->owner->Expiry && $expiry->InPast() ) {
            return true;
        }

        return false;
    }

    public function getEmbargoIsSet()
    {
        if ( !$this->owner->isPublished() ) return false;
        $embargo = $this->owner->dbObject("Embargo");
        //Debug::dump(($this->owner->Embargo)? true: false);
        if ( $this->owner->Embargo ) {
            return true;
        }

        return false;
    }

    public function getExpiryIsSet()
    {
        if ( !$this->owner->isPublished() ) return false;
        $expiry = $this->owner->dbObject("Expiry");
        if ( $this->owner->Expiry ) {
            return true;
        }

        return false;
    }

    public function updateStatusFlags(&$flags)
    {
        if ( $this->owner->getScheduledStatus() ) {
            $flags[ 'status-scheduled' ] = _t("Scheduler.SCHEDULED", "Scheduled");
        }
        if ( $this->owner->getExpiredStatus() ) {
            $flags[ 'status-expired' ] = _t("Scheduler.EXPIRED", "Expired");
        }

        return $flags;
    }

    /*
     * Return nice statusses for use in Gridfields (eg. GridFieldPages module or descendants)
     */
    function updateStatus(&$status, &$statusflag)
    {
        Requirements::customCSS('.col-ScheduledStatusDataColumn i {
            width: 16px;
            height: 16px;
            display: block;
            float: left;
            margin-right: 6px;
        }', 'ScheduledStatusDataColumn_Icons');
        if ( $this->owner->getEmbargoIsSet() ) {
            if ( $this->owner->getScheduledStatus() ) {
                // Under Embargo
                $status .= '<i class="font-icon-eye btn--icon-md text-danger" title="Embargo active"></i>'.$this->owner->dbObject("Embargo")->Nice();
            } else {
                // Embargo expired
                $status .= '<i class="font-icon-eye btn--icon-md text-info" title="Embargo expired"></i>'.$this->owner->dbObject("Embargo")->Nice();
            }
        }
        if ( $this->owner->getExpiryIsSet() ) {
            if ( $this->owner->getEmbargoIsSet() ) {
                $status .= '<br />'; // add a break if both set
            }
            if ( $this->owner->getExpiredStatus() ) {
                // Expired/unpublished
                $status .= '<i class="font-icon-eye-with-line btn--icon-md text-danger" title="Expired/unpublished"></i>'.$this->owner->dbObject("Expiry")->Nice();
            } else {
                // Scheduled to expire
                $status .= '<i class="font-icon-eye-with-line btn--icon-md text-warning" title="Scheduled to expire"></i>'.$this->owner->dbObject("Expiry")->Nice();
            }
        }
    }

    public function ScheduledStatusDataColumn()
    {
        $sched_status = '';
        $sched_flag = '';
        $this->updateStatus($sched_status, $sched_flag);

        return DBField::create_field('HTMLText', $sched_status);
    }

    /**
     * Checks if a user can view the page
     *
     * The user can view the current page if:
     * - They have the VIEW_DRAFT_CONTENT permission or
     * - The current time is after the Embargo time (if set) and before the Expiry time (if set)
     *
     * @param Member $member
     *
     * @return boolean
     */
    public function canView($member = null)
    {
        # This hook only ever DENIES. It abstains (null) where it used to return true, because
        # DataObject::extendedCan() lets any non-null extension answer override the page's own checks:
        # returning true made a page restricted to logged-in users or to specific groups viewable by
        # anyone, merely because this extension was applied to its class.

        // if CMS user with sufficient rights:
        # checkMember() honours the $member being asked about; with no $member it uses the current user
        if ( Permission::checkMember($member, "VIEW_DRAFT_CONTENT") ) {
            //if(Permission::checkMember($member, 'VIEW_EMBARGOEXPIRY')) {
//            return true;
            return null;
        }

        // if on front, controller should be a subclass of ContentController (ties it to CMS, = ok...)
        # Compared by class, not by the unqualified string "ContentController": that string never matched
        # the namespaced class, so this branch never ran on Silverstripe 4 and later.
        $ctr = self::currentController();
//        if ( is_subclass_of($ctr, "ContentController") ) {
        if ( $ctr instanceof ContentController ) {
            if ( $this->owner->getScheduledStatus() || $this->owner->getExpiredStatus() ) {

                # Visiting the page itself is answered with a 404 by contentcontrollerInit() below, which runs
                # before ContentController checks canView(). The redirect that used to live here could not
                # work: HTTPResponse::redirect() rejects 404 as a redirect code (warning, falls back to 302),
                # and the false returned below then made ContentController replace the response with
                # Security::permissionFailure(), i.e. the login screen this was meant to avoid.
//                // if $this->owner is the actual page being visited (Director::get_current_page());
//                $curpage = Director::get_current_page();
//                if ( $curpage->ID == $this->owner->ID ) {
//                    // we have to prevent visitors from actually visiting this page by redirecting to a 404
//                    // This is a bit of a hack (redirect), but else visitors will be presented with a
//                    // 'login' screen in order to acquire sufficient privileges to view the page)
//                    $errorPage = ErrorPage::get()->filter('ErrorCode', 404)->first();
//                    if ( $errorPage ) {
//                        $ctr->redirect($errorPage->Link(), 404);
//                    } else {
//                        // fallback (hack): redirect to anywhere, with a 404
//                        $ctr->redirect(rtrim($this->owner->Link(), '/') . "-404", 404);
//                        //$ctr->redirect(Page::get()->first()->Link(), 404);
//                    }
//                }

                # Deny: hides the page from menus and other canView()-filtered lists on the front end
                return false;
//            } else {
//                return true;
            }
        }

        // else, allow
        # ...by abstaining, so the page's own CanViewType/ViewerGroups settings decide
//        return true;
        return null;
    }

    /**
     * Answer a request for a scheduled or expired page with a 404, for visitors without VIEW_DRAFT_CONTENT.
     *
     * ContentController::init() fires this hook on the page before it checks canView(), so the visitor
     * gets the site's normal "page not found" response (the 404 ErrorPage, when one exists) instead of a
     * login form. This is the check that protects a page opened by its URL: the page is looked up by
     * SiteTree::get_by_link(), a query on SiteTree, which the augmentSQL() filter below does not reach
     * when the extension is applied to a SiteTree subclass.
     *
     * @param ContentController $controller
     */
    protected function contentcontrollerInit($controller)
    {
        if ( Permission::check('VIEW_DRAFT_CONTENT') ) {
            return;
        }
        if ( $this->owner->getScheduledStatus() || $this->owner->getExpiredStatus() ) {
            $controller->httpError(404);
        }
    }

    /**
     * The current controller, or null when none is on the stack.
     *
     * Framework 5's Controller::curr() raises E_USER_WARNING "No current controller available" on an
     * empty stack (CLI scripts, queue runners), and augmentSQL() runs on every query; framework 6
     * removed has_curr() and returns null silently. Drop the has_curr() branch when ^5 is dropped.
     */
    private static function currentController(): ?Controller
    {
        # has_curr() is deprecated in framework 5.4 through noticeWithNoReplacment(), which wraps the notice in
        # withSuppressedNotice(): it is only output with Deprecation::enable(true), and de-duplicated per
        # message, so at most one line per process - not one per query. There is no non-deprecated way on 5
        # to ask for the controller without the warning, so the call stays.
        if ( method_exists(Controller::class, 'has_curr') && !Controller::has_curr() ) {
            return null;
        }

        return Controller::curr();
    }

    # ?DataQuery: an implicitly nullable parameter is deprecated as of PHP 8.4
    public function augmentSQL(SQLSelect $query, ?DataQuery $dataQuery = null)
    {
        # Extension has no augmentSQL(); the DataExtension/SiteTreeExtension parent was an empty stub
//        parent::augmentSQL($query, $dataQuery);
        # Never filter a lazy load, see augmentLoadLazyFields() below
        if ( $dataQuery && $dataQuery->getQueryParam('SoftScheduler.LazyLoad') ) {
            return;
        }
        $stage = Versioned::get_stage();
//        if ( Controller::curr() instanceof LeftAndMain ) {
        if ( self::currentController() instanceof LeftAndMain ) {
            return;
        }
        if ( $stage === 'Live' || !Permission::check('VIEW_DRAFT_CONTENT') ) {
            # Compare against Silverstripe's clock, not the database server's NOW(). Datetimes are stored
            # in PHP's time zone, while NOW() uses the MySQL session time zone, so a UTC database behind a
            # Europe/Amsterdam site shifted every embargo and expiry by one or two hours. It also ignored
            # DBDatetime::set_mock_now(). date() rather than DBDatetime::Format(), which is locale-aware.
            $now = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp());
//            $query->addWhere('("Embargo" IS NULL OR "Embargo" < NOW()) AND ("Expiry" IS NULL OR "Expiry" > NOW())');
            $query->addWhere([
                '("Embargo" IS NULL OR "Embargo" < ?) AND ("Expiry" IS NULL OR "Expiry" > ?)' => [$now, $now],
            ]);
        }
    }

    /**
     * Mark lazy-load queries so augmentSQL() leaves them alone.
     *
     * A record fetched through a query on a parent class (SiteTree::get(), SiteTree::get_by_link(), a
     * menu or a Children() list) arrives without this class's own columns; DataObject::loadLazyFields()
     * fetches them later with a query on this class, and fires augmentSQL() on it. Filtering that query
     * does not hide the record, which is already loaded; it only makes the lazy load come back empty, so
     * Embargo, Expiry and every other column of the extended class read as null on a scheduled or expired
     * page - which in turn made the page look unscheduled to every check in this class.
     */
    protected function augmentLoadLazyFields(SQLSelect &$query, ?DataQuery &$dataQuery, $dataObject)
    {
        if ( $dataQuery ) {
            $dataQuery->setQueryParam('SoftScheduler.LazyLoad', true);
        }
    }

}
