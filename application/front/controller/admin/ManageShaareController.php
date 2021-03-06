<?php

declare(strict_types=1);

namespace Shaarli\Front\Controller\Admin;

use Shaarli\Bookmark\Bookmark;
use Shaarli\Bookmark\Exception\BookmarkNotFoundException;
use Shaarli\Formatter\BookmarkMarkdownFormatter;
use Shaarli\Render\TemplatePage;
use Shaarli\Thumbnailer;
use Slim\Http\Request;
use Slim\Http\Response;

/**
 * Class PostBookmarkController
 *
 * Slim controller used to handle Shaarli create or edit bookmarks.
 */
class ManageShaareController extends ShaarliAdminController
{
    /**
     * GET /admin/add-shaare - Displays the form used to create a new bookmark from an URL
     */
    public function addShaare(Request $request, Response $response): Response
    {
        $this->assignView(
            'pagetitle',
            t('Shaare a new link') .' - '. $this->container->conf->get('general.title', 'Shaarli')
        );

        return $response->write($this->render(TemplatePage::ADDLINK));
    }

    /**
     * GET /admin/shaare - Displays the bookmark form for creation.
     *                     Note that if the URL is found in existing bookmarks, then it will be in edit mode.
     */
    public function displayCreateForm(Request $request, Response $response): Response
    {
        $url = cleanup_url($request->getParam('post'));

        $linkIsNew = false;
        // Check if URL is not already in database (in this case, we will edit the existing link)
        $bookmark = $this->container->bookmarkService->findByUrl($url);
        if (null === $bookmark) {
            $linkIsNew = true;
            // Get shaare data if it was provided in URL (e.g.: by the bookmarklet).
            $title = $request->getParam('title');
            $description = $request->getParam('description');
            $tags = $request->getParam('tags');
            $private = filter_var($request->getParam('private'), FILTER_VALIDATE_BOOLEAN);

            // If this is an HTTP(S) link, we try go get the page to extract
            // the title (otherwise we will to straight to the edit form.)
            if (empty($title) && strpos(get_url_scheme($url) ?: '', 'http') !== false) {
                $retrieveDescription = $this->container->conf->get('general.retrieve_description');
                // Short timeout to keep the application responsive
                // The callback will fill $charset and $title with data from the downloaded page.
                $this->container->httpAccess->getHttpResponse(
                    $url,
                    $this->container->conf->get('general.download_timeout', 30),
                    $this->container->conf->get('general.download_max_size', 4194304),
                    $this->container->httpAccess->getCurlDownloadCallback(
                        $charset,
                        $title,
                        $description,
                        $tags,
                        $retrieveDescription
                    )
                );
                if (! empty($title) && strtolower($charset) !== 'utf-8') {
                    $title = mb_convert_encoding($title, 'utf-8', $charset);
                }
            }

            if (empty($url) && empty($title)) {
                $title = $this->container->conf->get('general.default_note_title', t('Note: '));
            }

            $link = escape([
                'title' => $title,
                'url' => $url ?? '',
                'description' => $description ?? '',
                'tags' => $tags ?? '',
                'private' => $private,
            ]);
        } else {
            $formatter = $this->container->formatterFactory->getFormatter('raw');
            $link = $formatter->format($bookmark);
        }

        return $this->displayForm($link, $linkIsNew, $request, $response);
    }

    /**
     * GET /admin/shaare/{id} - Displays the bookmark form in edition mode.
     */
    public function displayEditForm(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'] ?? '';
        try {
            if (false === ctype_digit($id)) {
                throw new BookmarkNotFoundException();
            }
            $bookmark = $this->container->bookmarkService->get((int) $id);  // Read database
        } catch (BookmarkNotFoundException $e) {
            $this->saveErrorMessage(sprintf(
                t('Bookmark with identifier %s could not be found.'),
                $id
            ));

            return $this->redirect($response, '/');
        }

        $formatter = $this->container->formatterFactory->getFormatter('raw');
        $link = $formatter->format($bookmark);

        return $this->displayForm($link, false, $request, $response);
    }

    /**
     * POST /admin/shaare
     */
    public function save(Request $request, Response $response): Response
    {
        $this->checkToken($request);

        // lf_id should only be present if the link exists.
        $id = $request->getParam('lf_id') ? intval(escape($request->getParam('lf_id'))) : null;
        if (null !== $id && true === $this->container->bookmarkService->exists($id)) {
            // Edit
            $bookmark = $this->container->bookmarkService->get($id);
        } else {
            // New link
            $bookmark = new Bookmark();
        }

        $bookmark->setTitle($request->getParam('lf_title'));
        $bookmark->setDescription($request->getParam('lf_description'));
        $bookmark->setUrl($request->getParam('lf_url'), $this->container->conf->get('security.allowed_protocols', []));
        $bookmark->setPrivate(filter_var($request->getParam('lf_private'), FILTER_VALIDATE_BOOLEAN));
        $bookmark->setTagsString($request->getParam('lf_tags'));

        if ($this->container->conf->get('thumbnails.mode', Thumbnailer::MODE_NONE) !== Thumbnailer::MODE_NONE
            && false === $bookmark->isNote()
        ) {
            $bookmark->setThumbnail($this->container->thumbnailer->get($bookmark->getUrl()));
        }
        $this->container->bookmarkService->addOrSet($bookmark, false);

        // To preserve backward compatibility with 3rd parties, plugins still use arrays
        $formatter = $this->container->formatterFactory->getFormatter('raw');
        $data = $formatter->format($bookmark);
        $this->executePageHooks('save_link', $data);

        $bookmark->fromArray($data);
        $this->container->bookmarkService->set($bookmark);

        // If we are called from the bookmarklet, we must close the popup:
        if ($request->getParam('source') === 'bookmarklet') {
            return $response->write('<script>self.close();</script>');
        }

        if (!empty($request->getParam('returnurl'))) {
            $this->container->environment['HTTP_REFERER'] = escape($request->getParam('returnurl'));
        }

        return $this->redirectFromReferer(
            $request,
            $response,
            ['add-shaare', 'shaare'], ['addlink', 'post', 'edit_link'],
            $bookmark->getShortUrl()
        );
    }

    /**
     * GET /admin/shaare/delete - Delete one or multiple bookmarks (depending on `id` query parameter).
     */
    public function deleteBookmark(Request $request, Response $response): Response
    {
        $this->checkToken($request);

        $ids = escape(trim($request->getParam('id') ?? ''));
        if (empty($ids) || strpos($ids, ' ') !== false) {
            // multiple, space-separated ids provided
            $ids = array_values(array_filter(preg_split('/\s+/', $ids), 'ctype_digit'));
        } else {
            $ids = [$ids];
        }

        // assert at least one id is given
        if (0 === count($ids)) {
            $this->saveErrorMessage(t('Invalid bookmark ID provided.'));

            return $this->redirectFromReferer($request, $response, [], ['delete-shaare']);
        }

        $formatter = $this->container->formatterFactory->getFormatter('raw');
        $count = 0;
        foreach ($ids as $id) {
            try {
                $bookmark = $this->container->bookmarkService->get((int) $id);
            } catch (BookmarkNotFoundException $e) {
                $this->saveErrorMessage(sprintf(
                    t('Bookmark with identifier %s could not be found.'),
                    $id
                ));

                continue;
            }

            $data = $formatter->format($bookmark);
            $this->executePageHooks('delete_link', $data);
            $this->container->bookmarkService->remove($bookmark, false);
            ++ $count;
        }

        if ($count > 0) {
            $this->container->bookmarkService->save();
        }

        // If we are called from the bookmarklet, we must close the popup:
        if ($request->getParam('source') === 'bookmarklet') {
            return $response->write('<script>self.close();</script>');
        }

        // Don't redirect to where we were previously because the datastore has changed.
        return $this->redirect($response, '/');
    }

    /**
     * GET /admin/shaare/visibility
     *
     * Change visibility (public/private) of one or multiple bookmarks (depending on `id` query parameter).
     */
    public function changeVisibility(Request $request, Response $response): Response
    {
        $this->checkToken($request);

        $ids = trim(escape($request->getParam('id') ?? ''));
        if (empty($ids) || strpos($ids, ' ') !== false) {
            // multiple, space-separated ids provided
            $ids = array_values(array_filter(preg_split('/\s+/', $ids), 'ctype_digit'));
        } else {
            // only a single id provided
            $ids = [$ids];
        }

        // assert at least one id is given
        if (0 === count($ids)) {
            $this->saveErrorMessage(t('Invalid bookmark ID provided.'));

            return $this->redirectFromReferer($request, $response, [], ['change_visibility']);
        }

        // assert that the visibility is valid
        $visibility = $request->getParam('newVisibility');
        if (null === $visibility || false === in_array($visibility, ['public', 'private'], true)) {
            $this->saveErrorMessage(t('Invalid visibility provided.'));

            return $this->redirectFromReferer($request, $response, [], ['change_visibility']);
        } else {
            $isPrivate = $visibility === 'private';
        }

        $formatter = $this->container->formatterFactory->getFormatter('raw');
        $count = 0;

        foreach ($ids as $id) {
            try {
                $bookmark = $this->container->bookmarkService->get((int) $id);
            } catch (BookmarkNotFoundException $e) {
                $this->saveErrorMessage(sprintf(
                    t('Bookmark with identifier %s could not be found.'),
                    $id
                ));

                continue;
            }

            $bookmark->setPrivate($isPrivate);

            // To preserve backward compatibility with 3rd parties, plugins still use arrays
            $data = $formatter->format($bookmark);
            $this->executePageHooks('save_link', $data);
            $bookmark->fromArray($data);

            $this->container->bookmarkService->set($bookmark, false);
            ++$count;
        }

        if ($count > 0) {
            $this->container->bookmarkService->save();
        }

        return $this->redirectFromReferer($request, $response, ['/visibility'], ['change_visibility']);
    }

    /**
     * GET /admin/shaare/{id}/pin - Pin or unpin a bookmark.
     */
    public function pinBookmark(Request $request, Response $response, array $args): Response
    {
        $this->checkToken($request);

        $id = $args['id'] ?? '';
        try {
            if (false === ctype_digit($id)) {
                throw new BookmarkNotFoundException();
            }
            $bookmark = $this->container->bookmarkService->get((int) $id);  // Read database
        } catch (BookmarkNotFoundException $e) {
            $this->saveErrorMessage(sprintf(
                t('Bookmark with identifier %s could not be found.'),
                $id
            ));

            return $this->redirectFromReferer($request, $response, ['/pin'], ['pin']);
        }

        $formatter = $this->container->formatterFactory->getFormatter('raw');

        $bookmark->setSticky(!$bookmark->isSticky());

        // To preserve backward compatibility with 3rd parties, plugins still use arrays
        $data = $formatter->format($bookmark);
        $this->executePageHooks('save_link', $data);
        $bookmark->fromArray($data);

        $this->container->bookmarkService->set($bookmark);

        return $this->redirectFromReferer($request, $response, ['/pin'], ['pin']);
    }

    /**
     * Helper function used to display the shaare form whether it's a new or existing bookmark.
     *
     * @param array $link data used in template, either from parameters or from the data store
     */
    protected function displayForm(array $link, bool $isNew, Request $request, Response $response): Response
    {
        $tags = $this->container->bookmarkService->bookmarksCountPerTag();
        if ($this->container->conf->get('formatter') === 'markdown') {
            $tags[BookmarkMarkdownFormatter::NO_MD_TAG] = 1;
        }

        $data = [
            'link' => $link,
            'link_is_new' => $isNew,
            'http_referer' => escape($this->container->environment['HTTP_REFERER'] ?? ''),
            'source' => $request->getParam('source') ?? '',
            'tags' => $tags,
            'default_private_links' => $this->container->conf->get('privacy.default_private_links', false),
        ];

        $this->executePageHooks('render_editlink', $data, TemplatePage::EDIT_LINK);

        foreach ($data as $key => $value) {
            $this->assignView($key, $value);
        }

        $editLabel = false === $isNew ? t('Edit') .' ' : '';
        $this->assignView(
            'pagetitle',
            $editLabel . t('Shaare') .' - '. $this->container->conf->get('general.title', 'Shaarli')
        );

        return $response->write($this->render(TemplatePage::EDIT_LINK));
    }
}
