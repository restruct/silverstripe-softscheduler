<?php

namespace Restruct\SchedBrowser;

use PageController;

/**
 * BROWSER-TEST FIXTURE ONLY - the front end of SchedBPage (see there).
 *
 * The scratch host has no theme, so this answers with a minimal HTML page of its own: the page
 * title and content, and the top-level menu (ContentController::getMenu(), which keeps only pages
 * whose canView() is true - the list the extension must hide scheduled and expired pages from).
 */
class SchedBPageController extends PageController
{
    public function index()
    {
        $items = '';
        foreach ($this->getMenu(1) as $item) {
            $items .= '<li>' . htmlspecialchars((string) $item->Title) . '</li>';
        }
        $title = htmlspecialchars((string) $this->data()->Title);
        return '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title></head><body>'
            . '<h1 id="title">' . $title . '</h1>'
            . '<div id="content">' . $this->data()->Content . '</div>'
            . '<ul id="menu">' . $items . '</ul>'
            . '</body></html>';
    }
}
