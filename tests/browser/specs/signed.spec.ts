import {
    test,
    expect,
    failedToLoad,
    loadedSize,
    maxAge,
    memberContext,
    openFixturePage,
    seed,
    src,
    visitorContext,
    withParam,
} from './support';

// Signed asset URLs as a browser meets them: images and links on a front-end page, other browser
// contexts (= other people), and the cache headers of the page and the file.

const SIGNED = /^\/signed-asset\/sau-browser\/protected\.png\?s=[0-9a-f]{16}&e=\d+$/;
const SIGNED_SESSION = /^\/signed-asset\/sau-browser\/protected\.png\?s=[0-9a-f]{16}&e=\d+&ss=1$/;

test.beforeEach(async ({ page }) => {
    await seed(page);
});

test.describe('Signed URLs for a visitor', () => {
    test('the protected image loads through its signed URL; the public one keeps its /assets URL', async ({ browser, baseURL }) => {
        const context = await visitorContext(browser, baseURL);
        try {
            const page = await context.newPage();
            await openFixturePage(page, 'm');
            const signed = await src(page, 'protected');
            expect(signed).toMatch(SIGNED);
            expect(await loadedSize(page.locator('img#protected'))).toEqual({ w: 40, h: 30 });
            expect(await src(page, 'public')).toBe('/assets/sau-browser/public.png');
            expect(await loadedSize(page.locator('img#public'))).toEqual({ w: 40, h: 30 });

            // Without the signature the visitor gets nothing: Silverstripe's own protection on the
            // plain URL, and the module refusing an unsigned /signed-asset/ request.
            const plain = await context.request.get('/assets/sau-browser/protected.png', { maxRedirects: 0 });
            expect(plain.headers()['content-type'] ?? '').not.toContain('image/png');
            // (No e parameter reads as expiry 0, so this one answers 410 Gone rather than 403.)
            const unsigned = await context.request.get('/signed-asset/sau-browser/protected.png');
            expect([403, 410]).toContain(unsigned.status());
            expect(unsigned.headers()['content-type'] ?? '').not.toContain('image/png');
        } finally {
            await context.close();
        }
    });

    test('the file is served inline, and as an attachment with &d=att', async ({ browser, baseURL }) => {
        const context = await visitorContext(browser, baseURL);
        try {
            const page = await context.newPage();
            await openFixturePage(page, 'm');
            const inline = await context.request.get(await src(page, 'protected'));
            expect(inline.status()).toBe(200);
            expect(inline.headers()['content-type']).toBe('image/png');
            expect(inline.headers()['content-disposition']).toMatch(/^inline; filename="protected\.png"/);

            const href = await page.locator('a#download').getAttribute('href');
            const attachment = await context.request.get(href!);
            expect(attachment.status()).toBe(200);
            expect(attachment.headers()['content-disposition']).toMatch(/^attachment; filename="protected\.png"/);
        } finally {
            await context.close();
        }
    });

    test('a changed signature, a changed expiry or a missing signature is refused', async ({ browser, baseURL }) => {
        const context = await visitorContext(browser, baseURL);
        try {
            const page = await context.newPage();
            await openFixturePage(page, 'm');
            const signed = await src(page, 'protected');
            const s = new URL(signed, 'http://x').searchParams.get('s')!;
            const e = Number(new URL(signed, 'http://x').searchParams.get('e'));

            const flipped = s.slice(0, -1) + (s.endsWith('0') ? '1' : '0');
            expect((await context.request.get(withParam(signed, 's', flipped))).status(), 'changed signature').toBe(403);
            expect((await context.request.get(withParam(signed, 'e', String(e + 3600)))).status(), 'later expiry').toBe(403);
            expect((await context.request.get(withParam(signed, 's', null))).status(), 'no signature').toBe(403);
            // The untouched URL still works, so the refusals above are about the change.
            expect((await context.request.get(signed)).status()).toBe(200);
        } finally {
            await context.close();
        }
    });

    test('an unpublished file is not served to a visitor, even with a valid signature', async ({ browser, baseURL }) => {
        const context = await visitorContext(browser, baseURL);
        try {
            const page = await context.newPage();
            await openFixturePage(page, 'm');
            const draft = await src(page, 'draft');
            expect(draft).toMatch(/^\/signed-asset\/sau-browser\/draft\.png\?s=/);
            await failedToLoad(page.locator('img#draft'));
            const response = await context.request.get(draft);
            expect([403, 404]).toContain(response.status());
        } finally {
            await context.close();
        }
    });
});

test.describe('CMS users', () => {
    test('bypass the signature check', async ({ page }) => {
        // The page's HTML only (not rendered: its draft image is the subject of the fixme below).
        const html = await (await page.request.get('/sau-browser/page?policy=m')).text();
        const signed = /<img id="protected" alt="protected" src="([^"]+)"/.exec(html)![1].replace(/&amp;/g, '&');
        expect(signed).toMatch(SIGNED);
        const response = await page.request.get(withParam(signed, 's', '0000000000000000'));
        expect(response.status(), 'a CMS user is served even with a wrong signature').toBe(200);
        expect(response.headers()['content-type']).toBe('image/png');
    });

    // https://github.com/restruct/silverstripe-signed-asset-urls/issues/7 - the image request runs in
    // the Live reading mode (Versioned.use_session is false), so the draft File is not found: 404,
    // also for the CMS user. Measured red on SS5 and SS6 (2026-10-02).
    test.fixme('see the unpublished image when previewing the draft stage', async ({ page }) => {
        // ?stage=Stage puts the session in the draft reading mode, so the image request (which has
        // no stage parameter of its own) finds the draft file; the CMS user skips the published check.
        await openFixturePage(page, 'm', '&stage=Stage');
        expect(await loadedSize(page.locator('img#draft'))).toEqual({ w: 40, h: 30 });
        expect(await loadedSize(page.locator('img#protected'))).toEqual({ w: 40, h: 30 });
    });
});

test.describe('Session binding', () => {
    test('a session-bound URL works in the session that got it, and nowhere else', async ({ browser, baseURL }) => {
        const member = await memberContext(browser, baseURL);
        const otherSession = await memberContext(browser, baseURL);
        const stranger = await visitorContext(browser, baseURL);
        try {
            const page = await member.newPage();
            await openFixturePage(page, 'ms');
            const bound = await src(page, 'protected');
            expect(bound).toMatch(SIGNED_SESSION);
            expect(await loadedSize(page.locator('img#protected'))).toEqual({ w: 40, h: 30 });

            // Same URL, other people: a visitor without a session, and the same member logged in
            // a second time (a different session).
            expect((await stranger.request.get(bound)).status(), 'a visitor without a session').toBe(403);
            expect((await otherSession.request.get(bound)).status(), 'the same member in another session').toBe(403);
            // Dropping the flag does not turn it into a shareable URL (the session token is signed in).
            expect((await stranger.request.get(withParam(bound, 'ss', null))).status(), 'ss=1 removed').toBe(403);
            // And it still works where it was issued.
            expect((await member.request.get(bound)).status()).toBe(200);
        } finally {
            await Promise.all([member.close(), otherSession.close(), stranger.close()]);
        }
    });

    // https://github.com/restruct/silverstripe-signed-asset-urls/issues/6 - without a PHP session the
    // session token is '' at signing and again at validation, so another browser is served (200).
    // Measured red on SS5 and SS6 (2026-10-02).
    test.fixme('a session-bound URL handed to a visitor without a session is bound too', async ({ browser, baseURL }) => {
        const visitor = await visitorContext(browser, baseURL);
        const stranger = await visitorContext(browser, baseURL);
        try {
            const page = await visitor.newPage();
            await openFixturePage(page, 'ss');
            const bound = await src(page, 'protected');
            expect(bound).toMatch(SIGNED_SESSION);
            expect((await stranger.request.get(bound)).status(), 'another browser with the same URL').toBe(403);
        } finally {
            await Promise.all([visitor.close(), stranger.close()]);
        }
    });
});

test.describe('Cache headers', () => {
    test('the page may not be cached longer than its shortest-lived signed URL', async ({ browser, baseURL }) => {
        const context = await visitorContext(browser, baseURL);
        try {
            const page = await context.newPage();
            for (const [policy, ttl] of [['s', 30], ['m', 3600], ['l', 86400]] as const) {
                const before = Math.floor(Date.now() / 1000);
                const response = await openFixturePage(page, policy);
                const age = maxAge(response.headers()['cache-control']);
                expect(age, `page max-age for policy ${policy}`).not.toBeNull();
                expect(age!).toBeLessThanOrEqual(ttl);
                expect(age!).toBeGreaterThanOrEqual(ttl - 5);
                const expires = Date.parse(response.headers()['expires'] ?? '') / 1000;
                expect(expires - before, `Expires for policy ${policy}`).toBeGreaterThanOrEqual(ttl - 5);
                expect(expires - before).toBeLessThanOrEqual(ttl + 5);

                // The file itself: private, and no longer than its URL lives.
                const file = await context.request.get(await src(page, 'protected'));
                const fileAge = maxAge(file.headers()['cache-control']);
                expect(file.headers()['cache-control']).toContain('private');
                expect(fileAge!).toBeLessThanOrEqual(ttl);
                expect(fileAge!).toBeGreaterThanOrEqual(ttl - 5);
            }
        } finally {
            await context.close();
        }
    });
});
