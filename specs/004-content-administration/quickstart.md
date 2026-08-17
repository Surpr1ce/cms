# Phase 1 Quickstart: Content Administration

**Feature**: `004-content-administration` | **Date**: 2026-08-17

## Write something and publish it

```bash
php bin/console doctrine:fixtures:load --no-interaction
php bin/console tailwind:build --minify
symfony serve
```

Sign in at `/login` as `editor@example.com` with the password in
`UserFactory::DEVELOPMENT_PASSWORD`, then:

1. **Articles → New article.** Give it a title and a body. Markup is allowed.
2. **Create.** It is a draft, attributed to you, at an address derived from the
   title.
3. **Change the title and save.** The address follows it — this is the gap
   feature 001 recorded and could not close on its own.
4. **Publish.** Open the address on the public site; it is there.
5. **Change the title again.** The address does *not* move now. People may have
   linked to it.

## See the sanitiser work

Put this in the body of an article and save it:

```html
<h2>A heading</h2>
<p onclick="steal()">A paragraph with a handler.</p>
<script>steal()</script>
<a href="javascript:steal()">A link that would run</a>
<iframe src="https://elsewhere.example"></iframe>
<p>Ordinary text with <strong>emphasis</strong> and <a href="https://example.com">a real link</a>.</p>
```

Reopen it. The heading, the paragraph text, the emphasis and the real link are
all there. The handler, the script, the executable link target and the frame are
not — and they are not merely hidden, they were never stored.

Confirm that last part rather than taking it on trust:

```bash
php bin/console dbal:run-sql "select content from article order by id desc limit 1"
```

That is the assertion the tests make too. Checking the rendered page instead
would pass with the sanitiser deleted, because Twig escapes by default — it
would be testing Twig.

## See the permissions work

Sign in as one of the author accounts instead:

```bash
php bin/console dbal:run-sql "select email, roles from app_user where roles like '%AUTHOR%'"
```

- The article list shows their own work and what is already published, and no
  other author's drafts.
- Opening somebody else's draft by address: refused.
- Opening their own *published* article: refused. It stopped being theirs alone
  when readers could see it.
- There is no publish control, and submitting
  `POST /admin/articles/{id}/publish` anyway: refused.
- There is no Pages link, and every page address: refused.

Each of those is a test in `tests/Functional/Admin/AdminPermissionsTest.php`, and
each is proven by submitting the address rather than by noting that a button is
missing — a hidden control is a suggestion, not a permission.

## Run the checks

```bash
composer qa                                                    # 597 tests, 1232 assertions
vendor/bin/phpunit --filter ContentSanitiserTest               # the hostile catalogue, no database
vendor/bin/phpunit --filter SanitisingOnStoreTest              # what actually gets stored
vendor/bin/phpunit --filter AdminPermissionsTest               # every address × every role
```

## What this feature does not give you

- No screens for sections, labels, files or accounts.
- No uploading — the lead-image picker offers what is already catalogued, and
  only files that have alternative text.
- No rich-text editor. The body is a text area, deliberately: sanitising must not
  depend on an editor behaving.
- No optimistic locking. Two people editing the same article: the second save
  wins, silently.
