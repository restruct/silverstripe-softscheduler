<?php


namespace Restruct\SilverStripe\SoftScheduler;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\CMS\Model\SiteTreeExtension;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\ErrorPage\ErrorPage;
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
class EmbargoExpiryExtension extends SiteTreeExtension
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

        $fields->insertBefore(
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
            ,
            'Content'
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
        // if CMS user with sufficient rights:
        if ( Permission::check("VIEW_DRAFT_CONTENT") ) {
            //if(Permission::checkMember($member, 'VIEW_EMBARGOEXPIRY')) {
            return true;
        }

        // if on front, controller should be a subclass of ContentController (ties it to CMS, = ok...)
        $ctr = Controller::curr();
        if ( is_subclass_of($ctr, "ContentController") ) {
            if ( $this->owner->getScheduledStatus() || $this->owner->getExpiredStatus() ) {

                // if $this->owner is the actual page being visited (Director::get_current_page());
                $curpage = Director::get_current_page();
                if ( $curpage->ID == $this->owner->ID ) {
                    // we have to prevent visitors from actually visiting this page by redirecting to a 404
                    // This is a bit of a hack (redirect), but else visitors will be presented with a
                    // 'login' screen in order to acquire sufficient privileges to view the page)
                    $errorPage = ErrorPage::get()->filter('ErrorCode', 404)->first();
                    if ( $errorPage ) {
                        $ctr->redirect($errorPage->Link(), 404);
                    } else {
                        // fallback (hack): redirect to anywhere, with a 404
                        $ctr->redirect(rtrim($this->owner->Link(), '/') . "-404", 404);
                        //$ctr->redirect(Page::get()->first()->Link(), 404);
                    }
                }

                return false;
            } else {
                return true;
            }
        }

        // else, allow
        return true;
    }

    public function augmentSQL(SQLSelect $query, DataQuery $dataQuery = null)
    {
        parent::augmentSQL($query, $dataQuery);
        $stage = Versioned::get_stage();
        if ( Controller::curr() instanceof LeftAndMain ) {
            return;
        }
        if ( $stage === 'Live' || !Permission::check('VIEW_DRAFT_CONTENT') ) {
            $query->addWhere('("Embargo" IS NULL OR "Embargo" < NOW()) AND ("Expiry" IS NULL OR "Expiry" > NOW())');
        }
    }

}

