# Changelog

## 3.0.0 (2026-09-25)

**Silverstripe 5 and 6.** One line supports both. Silverstripe 4 stays on the `2.0` tag; nothing here
is backported. Upgrade guide: [UPGRADING.md](UPGRADING.md).

Requires PHP `^8.1`, `silverstripe/framework ^5 || ^6` and `silverstripe/cms ^5 || ^6`.

### Changed

- **`silverstripe/cms` is now required** (#1). The extension subclassed a CMS class without declaring
  the dependency, so installing it without the CMS left a class whose parent could not be loaded.
- The extension now extends `SilverStripe\Core\Extension`; `SiteTreeExtension` is removed in
  Silverstripe 6. The class name, `Restruct\SilverStripe\SoftScheduler\EmbargoExpiryExtension`, is
  unchanged.
- `composer.json` declares PSR-4 autoloading, a `funding` entry and a `3.x-dev` branch alias for
  `main`, the default branch (renamed from `master`). The repository has an MIT `LICENSE` file to match
  the licence it already declared, naming both copyright holders in its history.

### Fixed

- **Pages with restricted viewers became public.** `canView()` returned `true` whenever it did not
  deny, and an extension's non-null answer overrides the page's own checks, so a page type with this
  extension ignored "Only logged-in users" and group restrictions. It now abstains unless it denies.
- **Scheduled and expired pages rendered when opened by their URL.** The page is looked up with a
  query on `SiteTree`, which the Live filter does not reach, and the front-end check in `canView()`
  compared the controller with the unqualified string `ContentController`, which never matches the
  namespaced class. Visitors now get a 404; `canView()` is false for them on the front end.
- **A scheduled page loaded through a `SiteTree` query lost its own fields.** Silverstripe fetches a
  subclass's columns lazily with a second query on that class; the Live filter emptied that query,
  so `Embargo`, `Expiry` and every other column of the extended page type read as null. Lazy-load
  queries are no longer filtered.
- **Embargo and expiry were compared against the database server's clock.** The filter used SQL
  `NOW()`, which follows the MySQL session time zone rather than PHP's, the time zone the dates are
  stored in - so a UTC database behind a site in another zone moved every embargo and expiry by the
  difference. It now uses Silverstripe's clock, which also honours `DBDatetime::set_mock_now()`.
- **The page edit form failed.** `updateCMSFields()` passed `insertBefore()` its arguments in the
  Silverstripe 3 order, a TypeError on Silverstripe 5 and 6.
- **Warnings from queries without a controller.** On Silverstripe 5 every filtered query run outside a
  request (CLI scripts, queue runners) raised "No current controller available".

### Removed

- The redirect to the 404 error page inside `canView()`. It could not work: a redirect does not accept
  a 404 status, and returning false afterwards replaced the response with a login form. The 404 is
  now produced by the `contentcontrollerInit` hook; the old code is kept, commented out, in the class.
- `.travis.yml`, a Silverstripe 3 configuration that targeted a tests directory that no longer
  existed. CI runs on GitHub Actions.
- `.scrutinizer.yml`, which filtered a `code/` directory that no longer exists.
- `client/dist/css/styles.css`, which was exposed but never loaded; its selectors targeted the
  Silverstripe 3 CMS. The site tree's Scheduled and Expired badges use the CMS's own badge style.

### Added

- A test suite (21 tests, identical on Silverstripe 5 and 6) with a regression test for each fix
  above, and a GitHub Actions matrix: Silverstripe 5 on PHP 8.1 and 8.3, Silverstripe 6 on PHP 8.3
  and 8.4.
- README: what the module does for whom, the public API, limitations, version compatibility and how
  to run the tests. The installation instructions named the wrong package and class.

## 2.0

Silverstripe 4 port.
