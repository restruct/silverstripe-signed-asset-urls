<?php

namespace Restruct\SauBrowser;

use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Member;
use SilverStripe\Security\PasswordEncryptor;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;

/**
 * BROWSER-TEST FIXTURE ONLY - routed at /sau-browser by fixtures/_config/routes.yml.
 *
 *   GET /sau-browser/seed (ADMIN only) makes sure these exist, and answers their IDs as JSON:
 *     - sau-browser/protected.png  published, CanViewType LoggedInUsers  -> protected, signed
 *     - sau-browser/draft.png      not published                         -> protected, signed,
 *                                                                           refused when served
 *     - sau-browser/public.png     published, anyone can view            -> plain /assets URL
 *     - a member visitor@sau.test (password in VISITOR_PASSWORD) without any CMS permission.
 *   GET /sau-browser/page?policy=m  a front-end page, open to anyone, with the three images
 *     through AutoURL(policy) plus a download link. Like a template would render them, but
 *     without one (fixtures cannot carry templates); it goes through the full middleware stack.
 *
 * The runner copies tests/browser/fixtures/ into the scratch host's app/; in the module itself it
 * sits behind tests/browser/_manifest_exclude, so no real install ever loads it.
 */
class SauBrowserController extends Controller
{
    private static $allowed_actions = ['seed', 'page'];

    public const VISITOR_EMAIL = 'visitor@sau.test';

    public const VISITOR_PASSWORD = 'Sau-visitor-7Kq!m2Zr-browser';

    public function seed(HTTPRequest $request): HTTPResponse
    {
        if (!Permission::check('ADMIN')) {
            return $this->httpError(403);
        }
        # A front-end request reads (and so writes) the Live stage; writing the files there would
        # publish the "draft" one as a side effect. Seed in the draft stage, as the CMS would.
        $ids = Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::DRAFT);
            $folder = Folder::find_or_make('sau-browser');
            return [
                'protected' => $this->image($folder, 'protected.png', 'LoggedInUsers', true, [200, 40, 40])->ID,
                'draft' => $this->image($folder, 'draft.png', 'Inherit', false, [40, 40, 200])->ID,
                'public' => $this->image($folder, 'public.png', 'Inherit', true, [40, 160, 40])->ID,
            ];
        });

        $member = Member::get()->filter('Email', self::VISITOR_EMAIL)->first();
        if (!$member) {
            $member = Member::create(['FirstName' => 'Visitor', 'Email' => self::VISITOR_EMAIL]);
            $member->write();
        }
        # Set it unless it is already the current one (password history refuses a repeat), and check
        # it took: a password the validator refuses leaves the member without one, silently
        # (Silverstripe 6's default validator wants more entropy than 5's).
        $current = $member->Password && $member->PasswordEncryption
            && PasswordEncryptor::create_for_algorithm($member->PasswordEncryption)
                ->check($member->Password, self::VISITOR_PASSWORD, $member->Salt, $member);
        if (!$current) {
            $result = $member->changePassword(self::VISITOR_PASSWORD);
            if (!$result->isValid()) {
                return $this->httpError(500, 'visitor password refused: ' . json_encode($result->getMessages()));
            }
        }

        return $this->json($ids);
    }

    public function page(HTTPRequest $request): HTTPResponse
    {
        $policy = (string) ($request->getVar('policy') ?: 'm');
        $html = ['<!DOCTYPE html><html><head><title>Signed asset URLs</title></head><body>'];
        foreach (['protected', 'draft', 'public'] as $name) {
            # Read from the draft stage: a front-end request reads Live, where the draft file does
            # not exist. Its URL stands in for a link handed out before the file was unpublished.
            $image = Versioned::get_by_stage(Image::class, Versioned::DRAFT)
                ->filter('FileFilename', "sau-browser/$name.png")->first();
            $url = $image ? $image->File->AutoURL($policy) : '';
            $html[] = sprintf('<img id="%s" alt="%s" src="%s">', $name, $name, htmlspecialchars((string) $url));
        }
        $protected = Image::get()->filter('FileFilename', 'sau-browser/protected.png')->first();
        if ($protected) {
            # ?d=att is appended by hand, as the README says a caller does for a download prompt.
            $html[] = sprintf('<a id="download" href="%s">Download</a>', htmlspecialchars($protected->File->AutoURL($policy) . '&d=att'));
        }
        $html[] = '</body></html>';

        return HTTPResponse::create(implode("\n", $html))->addHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** Find or create one 40x30 PNG of a solid colour in the folder, with the given view rule and stage. */
    private function image(Folder $folder, string $name, string $canView, bool $publish, array $rgb): Image
    {
        $image = Image::get()->filter('FileFilename', "sau-browser/$name")->first();
        if (!$image) {
            $gd = imagecreatetruecolor(40, 30);
            imagefill($gd, 0, 0, imagecolorallocate($gd, ...$rgb));
            ob_start();
            imagepng($gd);
            $png = ob_get_clean();

            $image = Image::create();
            $image->setFromString($png, "sau-browser/$name");
            $image->ParentID = $folder->ID;
            $image->Title = $name;
            $image->CanViewType = $canView;
            $image->write();
        }
        if ($publish && !$image->isPublished()) {
            $image->publishSingle();
        }
        # Back to draft-only if an earlier seed published it.
        if (!$publish && $image->isPublished()) {
            $image->doUnpublish();
        }
        return $image;
    }

    private function json(array $data): HTTPResponse
    {
        return HTTPResponse::create(json_encode($data))->addHeader('Content-Type', 'application/json');
    }
}
