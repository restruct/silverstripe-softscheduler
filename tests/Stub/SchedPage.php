<?php

namespace Restruct\SoftScheduler\Tests\Stub;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

/**
 * Test-only page type the extension is applied to (via $required_extensions in each test), so the
 * suite does not depend on how a host project configures its own Page class.
 *
 * Deliberately a plain SiteTree subclass rather than Page: SiteTree::get_by_link() queries SiteTree,
 * which is exactly the case where the extension's augmentSQL() filter does NOT apply.
 */
class SchedPage extends SiteTree implements TestOnly
{
    # Short table name: keeps well clear of MySQL's 64-character identifier limit
    private static $table_name = 'SoftSchedTestPage';

    # A controller that renders without a theme, so a request test does not depend on templates
    private static $controller_name = SchedPageController::class;
}
