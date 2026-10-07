<?php

namespace Restruct\SoftScheduler\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Stands in for a third-party extension, applied after EmbargoExpiryExtension, whose onBeforeDelete()
 * throws on demand: a database deadlock, a lock wait timeout or a search indexer failing inside delete().
 * An exception there skips Versioned's onAfterUnpublish/onAfterArchive hooks.
 */
class FailingDeleteExtension extends Extension implements TestOnly
{
    /**
     * @var bool
     */
    public static $throwOnDelete = false;

    protected function onBeforeDelete()
    {
        if ( self::$throwOnDelete ) {
            throw new \RuntimeException('simulated failure inside delete()');
        }
    }
}
