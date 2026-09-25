<?php

namespace Restruct\SoftScheduler\Tests\Stub;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Dev\TestOnly;

/**
 * Renders a fixed marker instead of a template, so request tests only measure access control.
 */
class SchedPageController extends ContentController implements TestOnly
{
    public function index()
    {
        return 'SOFTSCHED-PAGE-RENDERED';
    }
}
