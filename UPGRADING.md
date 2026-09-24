# Upgrading

## From 2.0 to 3.0

3.0 supports Silverstripe 5 and 6. Projects on Silverstripe 4 stay on `2.0`.

```
composer require restruct/silverstripe-softscheduler:^3
```

then flush and build the database. The class name and your YAML stay as they are; the database
columns (`Embargo`, `Expiry`) are unchanged.

### Things that behave differently

Each of these is a fix, but a site that grew used to the old behaviour will notice it:

- **"Who can view this page" is respected again on scheduled page types.** 2.0 made every page type
  carrying the extension viewable by anyone, whatever its viewer settings. If a page type was only
  public because of that, it is no longer; check the viewer settings of those pages.
- **Scheduled and expired pages answer 404 when opened by their URL**, and disappear from menus and
  other lists that check `canView()`, for visitors without `VIEW_DRAFT_CONTENT`. In 2.0 they were
  only left out of queries on the extended page type, and still rendered by URL.
- **Embargo and expiry moments follow Silverstripe's clock** (PHP's time zone) instead of the
  database server's. If your database runs in a different time zone from PHP, the moment a page
  appears or disappears moves by that difference - to the time the editor actually entered.
- **`silverstripe/cms` is required.** The module never worked without it; now Composer says so.

### Removed

- The redirect to the 404 error page from inside `canView()`. It never produced a 404 (see
  CHANGELOG); the 404 now comes from the `contentcontrollerInit` hook. If your project worked around
  the old behaviour, for example with its own redirect for scheduled pages, that workaround can go.
