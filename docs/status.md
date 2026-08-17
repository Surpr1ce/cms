# Implementation status

Last updated: 2026-08-17

This file records what actually exists in the codebase, as distinct from what the
design documents describe. Documents that describe an intended design are marked
as such at the top of the file.

## Where this stands

Sixteen features, all on `master`, `composer qa` green after each and CI green
since feature 011. **851 tests, 2575 assertions.**

A reader can find the site, read it, search it and subscribe to it. An editor can
write, publish, upload, and get back in after forgetting their password. An
administrator can manage sections, labels, accounts and files, and read a record
of who did what — through one administration interface rather than two. Two
editors cannot silently overwrite each other, a draft is invisible through five
different delivery mechanisms, and every response carries a content security
policy that allows no inline script and no inline style.

The last two features contained no planned work at all. Both came from opening
the running site and looking at it, and between them they found images that were
one pixel, a test suite writing into the developer's uploads, forms whose fields
were invisible, and two administration areas that looked like different products.
**A test suite proves the rules hold; it does not prove somebody can use the
thing.**

What remains below falls into three kinds, and the distinction matters more than
the length of the list:

- **Deliberate absences** — public registration, private files, API
  authentication, a rich-text editor. Each is a decision with a reason recorded
  beside it, not an omission.
- **Optimisations and refinements** — format conversion, responsive images, page
  caching, filtering the log, snippet highlighting. None of them is load-bearing.
- **One real debt** — no feature has had the `symfony-reviewer` or
  `security-auditor` pass the constitution asks for at phase 4. That is the
  honest gap, and it is stated again in the table below rather than left to be
  inferred.

## Done

| Area | State |
| --- | --- |
| Symfony 8.1 skeleton | Installed, PHP 8.4 |
| Repository and remote | `Surpr1ce/cms`, private |
| PostgreSQL 16 on the development machine | Installed and running, dev and test databases migrated |
| PHPStan (level max) + Symfony/Doctrine extensions | Configured, passing with no baseline and no ignores |
| PHP-CS-Fixer (Symfony ruleset, PHP 8.4 migration) | Configured, passing |
| Rector (PHP 8.4 + quality sets) | Configured, passing |
| PHPUnit with Foundry and DAMA isolation | In use; `unit`, `integration` and `functional` suites defined |
| Composer quality-gate scripts (`composer qa`) | Defined and green |
| Spec Kit + project subagents | Installed |
| Conventions (`CLAUDE.md`), constitution, ADRs 1–6 | Written |

### Feature 001 — core content model

Branch `001-core-content-model`. Specification, plan, research, data model,
contract and task list in `specs/001-core-content-model/`.

| Area | State |
| --- | --- |
| Phases 1–8 of 9 | Built, `composer qa` green after each |
| Entities | `User`, `Article`, `Page`, `Category`, `Tag`, `Media`, the abstract `PublishableContent`, the `ContentStatus` enum, the `Slug` rule holder |
| Repositories | Six, plus the `SluggedRepository` interface. Published scope is one private method per repository; no method returns a `QueryBuilder` |
| Services | `SlugGenerator`, `UniqueSlugGenerator`, `StoredFilenameGenerator`, `CategoryDeleter`, `PageDeleter`, `MediaDeleter`, `UserDeleter` |
| Domain exceptions | Nine, each carrying its context as typed accessors |
| Migrations | Five, all generated with `doctrine:migrations:diff`, none hand-edited after commit |
| Foundry factories | Six, one per entity, used by both the tests and the fixtures |
| Development fixtures | `AppStory` builds 4 accounts, 3 sections, 5 labels, 12 articles across all three states, 6 pages and 6 files |
| Test suite | **279 tests, 586 assertions, passing** |

### Feature 002 — public website

Branch `002-public-website`. Merged into feature 001's model; the site renders.

| Area | State |
| --- | --- |
| Phases 1–8 | Built, `composer qa` green |
| Routes | `/`, `/articles/{slug}`, `/sections/{slug}`, `/topics/{slug}`, `/{slug}` — see [ADR 8](adr/0008-public-address-scheme.md) |
| Controllers | Five, thin; none queries directly and none checks a status — every route resolves through a repository method that cannot return unpublished content |
| Templates | Site layout, home, article, section, label, page, four components, and 404 and error pages inside the site's own layout |
| Styling | Tailwind v4.3.3, pinned, built from a standalone binary. No Node, no `package.json`, no `node_modules` |
| Pagination | 20 per page, next/previous only, one extra fetched row instead of a `COUNT` |
| Menu | One query per request, built by a Twig extension so no controller can forget it |
| Functional test suite | **73 tests** — the suite that was empty until this feature |
| Whole project | **379 tests, 780 assertions, passing** |

### Feature 003 — authentication and authorisation

Branch `003-authentication`. The door is fitted; the rooms behind it are feature 004.

| Area | State |
| --- | --- |
| Sign-in and sign-out | `/login`, `/logout`, form login against an entity provider, CSRF-protected |
| The gate | `^/admin` requires a signed-in account holding a content role. Anonymous → redirect; recognised but unpermitted → 403 |
| Voters | `ArticleVoter` (ownership plus role), `PageVoter` (role only — a page has no author), `AdministrationVoter` (taxonomy, files, accounts) |
| Role model | **No `role_hierarchy`.** An administrator is granted an editor's permissions explicitly in each voter, so every grant is visible and unit-testable. See [ADR 9](adr/0009-voters-instead-of-role-hierarchy.md) |
| Bootstrap | `php bin/console app:create-administrator` creates or promotes an account, so access exists before any interface does |
| Fixture accounts | All four can sign in, with the password written openly in `UserFactory::DEVELOPMENT_PASSWORD` |
| Whole project | **492 tests, 973 assertions, passing** |

**Two defects the tests found before the code shipped**: the article voter
granted permission on ownership alone, so an account whose author role had been
revoked would have kept every permission over everything it wrote; and two tests
of role revocation passed while proving nothing, because they wrote through a
discarded entity manager. Both are recorded in the feature's `tasks.md`.

### Feature 004 — content administration

Branch `004-content-administration`. Articles and pages can now be written,
published and read without leaving the browser.

| Area | State |
| --- | --- |
| Screens | Article list, create, edit, delete and the four transitions; the same for pages |
| **Markup sanitising** | **Implemented and proven.** `symfony/html-sanitizer` behind `ContentSanitiser`, applied on the way in. 23 hostile inputs neutralised, 15 forms of legitimate markup preserved, asserted on what is **stored** — see [ADR 10](adr/0010-sanitise-markup-on-the-way-in.md) |
| Permissions | Every screen asks the feature-003 voters about the specific content. Refusals are tested by submitting the address directly, not by looking for an absent button |
| Slug regeneration | **The gap feature 001 recorded is closed.** Renaming a draft moves its address; renaming a published article does not |
| New dependency | `symfony/html-sanitizer` — the first since the skeleton |
| Whole project | **597 tests, 1232 assertions, passing** |

**Four defects the tests found**: the sidebar form fields were outside the
`<form>` element and the CSRF token was never rendered, so every submission
returned 422 silently; `setParameters()` needs an `ArrayCollection` in ORM 3; and
two test assertions were wrong about their own requirements. All recorded in the
feature's `tasks.md`.

### Feature 005 — media uploads

Branch `005-media-uploads`. Lead images are real files now.

| Area | State |
| --- | --- |
| Uploading | `/admin/media` — editorial only. Accepted by **detected** type against an allow-list, size-limited to 8 MB, description required |
| Storage | `var/uploads/`, **outside the web root**. No web server configuration can serve those bytes directly, so nothing there can be executed |
| Serving | `/media/{filename}` through a controller, with the recorded type, `X-Content-Type-Options: nosniff`, images inline and everything else as an attachment. See [ADR 11](adr/0011-serve-uploads-through-the-application.md) |
| Hostile catalogue | PHP named `.php`, PHP renamed `.jpg`, a double extension, a polyglot PNG, an SVG, HTML, a shell script, an executable, an empty file — none catalogued, none written. Traversal and absolute-path names never touch a path at all |
| Whole project | **645 tests, 1498 assertions, passing** |

**`nosniff` caught the fixtures.** They wrote PNG bytes for records catalogued as
JPEG; a browser told "this is a JPEG" and handed a PNG refuses to render it. The
first real mismatch the header found was our own.

### Feature 006 — read-only JSON API

Branch `006-read-only-api`. The last piece of the stack that was installed and unused.

| Area | State |
| --- | --- |
| Addresses | `/api/articles`, `/api/pages`, `/api/sections`, `/api/tags`, each with an item address by slug. Eight routes, all `GET` |
| Read models | `src/ApiResource/` — plain objects, **not** mapped entities. A field not written there is not exposed, so an entity gaining a column cannot put an email address or a password hash into the API |
| Providers | `src/State/` — call the same repository methods the website's controllers call. **No provider contains a status comparison**, which is what makes ADR 2's claim structural rather than a matter of discipline |
| Read-only | Every write method against every address is refused, and the tests assert the content afterwards as well as the status |
| Whole project | **680 tests, 1645 assertions, passing** |

**The test worth knowing about**: `testTheApiAndTheWebsiteAgreeAboutWhatIsPublished`
asks both delivery mechanisms what is published and compares them. It is the
assertion [ADR 2](adr/0002-twig-monolith-with-read-only-api.md) exists to make
true, and nothing had checked it until this feature.

### Feature 007 — taxonomy and account administration

Branch `007-taxonomy-and-accounts`. The generic-CRUD half of the administration
area, which the conventions reserved for EasyAdmin and which had stayed empty
through five features.

| Area | State |
| --- | --- |
| Screens | Sections, labels and accounts under `/admin/manage`, EasyAdmin 5. The hand-written screens keep `/admin/articles`, `/admin/pages` and `/admin/media` |
| Addresses | Generated once through `UniqueSlugGenerator` and then fixed. **No screen exposes a slug field**, because a form offering to edit one invites breaking every link that already exists |
| Deletion | Routed through `CategoryDeleter` and `UserDeleter` rather than the scaffold. The scaffolded delete would have made subsections top-level instead of moving them up, and would have answered an owned account with a foreign-key name instead of a sentence |
| Passwords | The field is **unmapped**, so the stored hash is never loaded into a form and never rendered. Blank on edit means unchanged |
| Permissions | Taxonomy behind `MANAGE_TAXONOMY`, accounts behind `MANAGE_ACCOUNTS`, self-deletion refused by `DELETE_ACCOUNT`. The menu is filtered by the same voters the controllers check |
| Batch delete | Disabled everywhere. Both routes funnel through `deleteEntity()`, so the rule holds by override rather than by the absence of a button |
| Whole project | **717 tests, 1756 assertions, passing** |

**Three defects the tests found.** EasyAdmin passes the subject to a permission
check, `AdministrationVoter` abstained on anything with a subject, and every
manage screen was therefore silently denied — to administrators included. The
dashboard at `/admin/manage` was reachable by an author, because `access_control`
over `^/admin` only asks for a content role and the CRUD controllers guard their
own actions rather than the landing page. And the first override was on
`delete()`, which the batch route bypasses entirely.

### Feature 008 — hardening

Branch `008-hardening`. The two entries this file had carried in bold since
feature 003.

| Area | State |
| --- | --- |
| Sign-in throttling | **Implemented.** Five attempts in fifteen minutes, counted per client address *and* per submitted handle. The sixth is refused **without the password being checked**, which is what the tests distinguish |
| Lockout message | Says "too many", says the same thing for a known and an unknown address, and expires on its own |
| Content security policy | **Implemented and enforced**, not report-only. No `unsafe-inline` for scripts: every inline script the application emits carries a per-response nonce the header names |
| The generic administration screens | Mark their own scripts through `csp_nonce()` — a Twig function named to match the `{% guard function csp_nonce %}` already in EasyAdmin's templates. No bundle, no template override. See [ADR 12](adr/0012-build-the-content-security-policy-in-the-application.md) |
| Other headers | `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, on **every** response including the 404 |
| New dependency | `symfony/rate-limiter` — one, and only for the half that cannot exist without it |
| Removed | The importmap CDN polyfill, and a stylesheet import in `assets/app.js` that AssetMapper answered with an empty `data:` module — which would have meant allowing `data:` in `script-src` to load nothing |
| Whole project | **735 tests, 1861 assertions, passing** |

**Two defects the tests found, both about responses nobody produces.** The 404
carried no headers at all: an error response *is* a sub-request's response, and
guarding on `isMainRequest()` skips every one of them. And the nonce could not
live on the request object, because Symfony pops the request off the stack before
rendering the error page — so the value a 404 needed was unreachable at exactly
the moment it was needed.

**What you see in a browser during development is not what a reader gets.** The
web debug toolbar appends its own nonce and `'unsafe-inline'` to the policy so
that it can run. Every assertion about the policy is therefore in the test suite,
which has no toolbar.

### Feature 009 — concurrent editing

Branch `009-concurrent-editing`. The last entry under "known gaps" that described
work being **destroyed** rather than merely absent.

| Area | State |
| --- | --- |
| The rule | An article or page carries a version. The edit form carries the version it was opened on. Saving compares them and **refuses** when they differ |
| What the second editor gets | The same screen, HTTP 409, a sentence naming what happened, and **everything they typed still in the form** — a redirect would have discarded the hour of work the refusal exists to protect |
| What is stored | Nothing. The check runs before a single field is applied, so a refusal cannot half-write |
| Forging | A submission with no version, or one that was never real, is refused rather than trusted. The version travels through a browser, so it is under somebody else's control |
| Out of scope, deliberately | Creating (nothing to conflict with), publication transitions and deletion (they write a status, not a body) |
| Ordinary editing | Unchanged. One person saving repeatedly is never refused, and no field appears on any screen |
| Whole project | **746 tests, 1963 assertions, passing** |

**Refused, not merged.** Merging two versions of prose automatically is a guess
dressed as a feature: the result belongs to nobody and neither editor is told
what happened to their sentences. A future feature may show a comparison; this
one refuses and says why.

### Feature 010 — discoverability

Branch `010-discoverability`. Nine features had built a CMS that works and that
nobody can find.

| Area | State |
| --- | --- |
| Sitemap | `/sitemap.xml` — every published article, page, section and in-use label, absolute addresses, a change date per entry |
| Robots | `/robots.txt`, generated rather than static, naming the sitemap through the router so it cannot drift |
| Feed | `/feed.xml` — Atom, the twenty most recent published articles, advertised from every page of the site |
| Preview metadata | Open Graph title, description, address, type and image; a Twitter card; and a canonical address, on **every** public page |
| How that is guaranteed | Built in `base.html.twig` from the title and description a template already declares, using Twig's `block()`. A template gains a working preview by doing nothing and cannot acquire a broken one by forgetting |
| Descriptions | One shared rule in `src/Service/Seo/PlainText.php` — markup gone, whitespace collapsed, cut on a word boundary. Used by the tags and by the feed summaries alike |
| Canonical addresses | Drop the query string, except the page number: page two of a listing is genuinely a different page, and calling it canonical to page one asks a search engine to forget everything past the first twenty articles |
| Whole project | **784 tests, 2122 assertions, passing** |

**Nothing here writes a query.** Every list comes from a repository method the
public controllers already use — the ones that structurally cannot return
unpublished content — because a sitemap assembled from `findAll()` and filtered
afterwards is how a draft ends up announced to a search engine.

**The two assertions worth knowing about.** `SitemapTest` requests *every address
the sitemap contains*, because a sitemap listing a 404 teaches a crawler to
distrust the whole document and a comparison of two lists would never find it.
And `FeedTest` publishes an article whose body is a catalogue of things that
break XML — an unclosed tag, a bare ampersand, a `]]>` sequence — because one
such article would otherwise take the other nineteen entries with it.

### Feature 011 — search

Branch `011-search`. The last of the reader-facing gaps. Everything remaining is
now an operator concern or an optimisation.

| Area | State |
| --- | --- |
| Search | `/search?q=` — published articles and pages, ranked, paged the way every other listing pages |
| How | PostgreSQL full-text search. A title match weighs more than a passing mention; stemming means a search for *publishing* finds *published*, which a `LIKE` scan would not |
| Markup | Stripped before indexing. Without that, a body's tags are words and a search for `strong` matches most of the site |
| Indexes | GIN, over the same weighted expression the query uses — `idx_article_search`, `idx_page_search` |
| Injection | The reader's words are a bound parameter turned into a query by `plainto_tsquery`, which reads operators, quotes and punctuation as words. Nothing builds a query expression by concatenation |
| Bounds | Two characters minimum, two hundred maximum, and an empty query gets an invitation rather than a report of no results |
| Not indexed | The results page tells crawlers `noindex` — it is generated from somebody else's words and has no permanent existence |
| New directory | `src/Search/` — see `CLAUDE.md` |
| Whole project | **803 tests, 2178 assertions, passing** |

**This is the first delivery that does not read through a published-only
repository method.** Every earlier one — the website, the API, the feed, the
sitemap — is safe structurally, because the method it calls has no code path that
returns a draft. A search needs a `WHERE` clause of its own, and a line of SQL is
a thing that can be wrong.

So the load-bearing test is not "a draft is absent from the results". It is that
a word only a draft contains produces a response **identical** to a word nothing
contains at all — because a leaked count, a leaked total or a paging control
appearing would each answer "does unpublished work about this exist" without
showing any of it.

**One regression that test found, in feature 010's work rather than this one.**
The preview and canonical tags name the address that was asked for, so a 404 for
a draft and a 404 for an address that never existed had stopped being identical.
Nothing was disclosed — a reader is only shown the address they typed — but the
identity is the property that is checked, and a proxy with exceptions protects
nothing. The error templates now carry `noindex` and no preview metadata at all,
which is also what a 404 deserves on its own terms.

### Feature 012 — media delivery

Branch `012-media-delivery`. The two rows this file has carried since feature
005: every image served at the size it was uploaded, and every byte sent again on
every page view.

| Area | State |
| --- | --- |
| Caching | Every served file carries an ETag and a modification date, and may be kept for a year as immutable. A reader holding either is answered 304 with no bytes |
| Why a year is safe | A stored name is sixteen random bytes generated once and never reused, so the bytes at an address can never change. Changed bytes are a new upload at a new address |
| Sizes | `/media/{size}/{filename}` — `thumbnail`, `medium`, `large`. Named, not numeric: a template asks for `thumbnail`, and a reader cannot invent a dimension to make the server generate |
| Resizing | GD. Fits within a box, keeps proportions, **never crops and never enlarges**. A pixel budget of fifty megapixels, because a file inside the eight-megabyte upload limit can still decode to hundreds of megabytes |
| Where they live | Beside the originals, outside the web root, served through the same controller with the same headers. Written to a temporary name and renamed, so two readers asking at once cannot be served half a file |
| Lifecycle | A derived image is a cache, not a record — nothing about it reaches the database, and deleting a file deletes everything derived from it |
| Whole project | **831 tests, 2352 assertions, passing** |

**This feature found that two inline event handlers had been dead since feature
008.** The delete confirmations and the hide-a-broken-image handler are inline
script, which that feature's content security policy forbids — so the
confirmations had stopped asking and missing images had been showing a broken
icon. Nothing caught it because neither is something a functional test can see.
They are now data attributes handled by `assets/behaviours.js`.

That is the second time feature 008 broke something quietly. A policy forbidding
inline script breaks *every* inline handler on a site, and nothing enumerated
them at the time.

### Feature 013 — account recovery

Branch `013-account-recovery`. Until this, there was one way into the CMS and no
way back: a forgotten password meant finding somebody with a shell on the server,
and on a one-administrator installation it meant the site could no longer be
administered at all.

| Area | State |
| --- | --- |
| Forgotten password | `/reset-password` — ask for a link, receive it by email, set a new password, and be signed in |
| Telling a stranger nothing | The response for an address that holds an account and one that does not is **byte-for-byte identical**, and no message is sent for the second. Verified both in the suite and by hand |
| The stored token | A SHA-256 hash, never the token. A stolen database yields no working links, and the test reads the row to prove it |
| Why not the password hasher | It is 128 bits of randomness with nothing to guess. A deliberately slow hash on an unauthenticated lookup buys no strength and offers a way to exhaust the server |
| A link's life | One hour, one use. Asking again invalidates the earlier one. Invalid, expired, used and superseded all get the same refusal, because telling them apart tells whoever holds a stolen link which kind they have |
| Limiting | Five requests an hour per client, so this form cannot be used to send mail to somebody else's inbox on demand |
| Changing a password on purpose | `/admin/account`, and it **requires the current password** — a browser left open on a shared machine is not consent to hand an account over |
| Whole project | **854 tests, 2475 assertions, passing** |

**Registration is still deliberately absent.** Accounts are created by an
administrator; a public sign-up form is a way to fill a database with strangers.

**A password change does not end other sessions**, and the account screen says so
in as many words. This CMS keeps no registry of open sessions, so it cannot end
them — and a change that quietly implied otherwise would protect less than
somebody would assume.

### Feature 014 — audit log

Branch `014-audit-log`. The last row on the "not done" list that was a missing
capability rather than a deliberate absence or an optimisation.

| Area | State |
| --- | --- |
| What is recorded | The four publication transitions, content deleted, a file deleted, an account created or deleted, permissions changed, and a password changed |
| What is not | Editing an article records nothing. A log of every save is a log nobody reads and a database twice the size to store that somebody fixed a typo |
| Reading it | `/admin/log`, newest first, paged, behind `MANAGE_ACCOUNTS` — reading who did what is the same kind of authority as deciding who may do it |
| Outliving its subject | The subject is text, not a reference. An entry about a deleted article still names it, which is the only case anybody actually reaches for a log in |
| Outliving its actor | The account is kept twice: as a relation severed on deletion, and as the address in text. Deleting somebody leaves their history readable and attributed |
| Permanence | No route under `/admin/log` accepts anything but `GET`, and there is no service method that changes or removes an entry. Asserted by walking the router rather than promised |
| Failure | Writing an entry cannot undo what it records. If the write fails the article is still published and the failure goes to the application log |
| Whole project | **865 tests, 2545 assertions, passing** |

**Recorded in the services, not by a Doctrine lifecycle listener.** A listener
would catch every write automatically, which sounds better and is worse: it would
know neither what a change *meant* nor who made it.

### Feature 015 — release readiness

Branch `015-release-readiness`. Not a planned feature: a list of things found by
walking the running site with a script before cutting a release, every one of
which was invisible to a green test suite.

| Area | State |
| --- | --- |
| Development images | **Drawn, not shipped.** `PlaceholderImage` generates a 1200×800 picture with GD, seeded from the stored filename, encoded as the type the record claims. They were one-by-one pixels, which stopped being adequate the moment feature 012 started resizing |
| Test uploads | The test environment has an uploads directory of its own. Both environments used to resolve `app.upload_directory` to the same path, so the suite wrote into the developer's uploads and no test could safely tidy up after itself |
| Orphans | `php bin/console app:media:prune-derived`, with `--dry-run`. Removes derived images whose originals are no longer catalogued, keeps everything in use, and **leaves alone any name it cannot parse** |
| Fixture reloads | `doctrine:fixtures:load` now leaves the disk holding exactly what the catalogue holds. It purged the database and left every file behind, so a few reloads filled the directory with rubbish nothing could identify |
| Whole project | **871 tests, 2573 assertions, passing** |

**The lesson, recorded because it is the one this project keeps relearning:** a
green suite proves the rules hold, not that somebody opening the thing sees what
they should. None of these four would have been caught by another test. They
needed somebody to look.

### Feature 016 — administration interface

Branch `016-admin-interface`. Two screenshots and a question, neither of which
any of the 871 tests could have produced.

| Area | State |
| --- | --- |
| Forms | **`templates/form/theme.html.twig`**, registered globally. Every control carries the site's border, padding and width; labels sit above their fields; help beneath; errors beneath and attached to the field, which is itself marked |
| Why they were broken | `form_row()` renders Symfony's default markup and Tailwind's preflight strips the border and padding a browser would give it. The result was a label running into an invisible field — for twelve features, with every test passing, because a crawler finds fields by name and does not care what they look like |
| One administration area | Sections, labels and accounts are hand-written screens at `/admin/manage/sections`, `/labels` and `/accounts`, in the same layout as articles, pages and files. They were EasyAdmin, with its own layout, typeface, controls and navigation — a visible seam every time somebody moved between them |
| What that had to preserve | Every rule the generic screens were overridden to keep, each still tested: an address generated once and then fixed, articles surviving a section's deletion, subsections moving up to their grandparent, a stored hash never rendered, blank meaning unchanged, self-deletion refused, an owning account refused with a sentence |
| Removed | `easycorp/easyadmin-bundle` and two packages it brought |
| Tightened | **`style-src` no longer allows `unsafe-inline`.** It existed only because those screens carried style attributes on elements this project did not author |
| The landing page | Counts of what exists — only of things the viewer may open — the viewer's own unfinished drafts, and for an administrator the most recent log entries |
| Whole project | **851 tests, 2575 assertions, passing** |

**The fault behind all three**, and this is the second feature in a row to say
it: a test suite proves the rules hold, not that somebody opening the thing can
use it.

**A concession is worth re-reading when its reason changes.** The `unsafe-inline`
for styles was documented, justified and correct when it was written, and it
stopped being needed the moment the thing that needed it was replaced — for
reasons that had nothing to do with the policy.

## Not done

| Area | State |
| --- | --- |
| API authentication and rate limiting | **Deliberately absent.** The API exposes exactly what the public website exposes, so a key would protect nothing while suggesting it did. Recorded as a decision, not an omission |
| API search, filtering and sorting | Not started. Sections and labels only, newest first |
| Bulk operations on sections, labels and accounts | **Deliberately absent.** Every deletion is one thing at a time, with a confirmation. A bulk action is the one route most likely to be added later without anybody remembering it bypasses that |
| Format conversion | Not started. A derived image keeps the original's format; serving WebP or AVIF to browsers that accept them is a real improvement and a decision of its own |
| Responsive image markup | Not started. A page names one size rather than offering a `srcset`, so a narrow screen still receives the size a wide one would |
| A cache in front of the application | Still not started, and now much less pressing. A reader who has seen an image no longer asks for it again at all, which was most of the cost |
| Cleaning up derived images automatically | Not started, and probably not wanted. `app:media:prune-derived` does it on demand and a scheduled job to run it would be infrastructure this project does not otherwise assume |
| Removing orphaned **originals** | **Deliberately not offered as a command.** A derived image is a cache and can be remade; an original is the only copy of somebody's upload, and a command that removed uncatalogued ones would destroy an upload whose database row failed to save. The development fixtures do it, because they have just emptied the database and know what they are looking at |
| Private files | Not possible. Serving applies no restriction beyond "anybody may read", because a file in a published article has to be public and the CMS has no notion of a private one |
| Concurrent editing of **sections, labels, accounts and files** | Still last-write-wins. Feature 009 covers articles and pages, where a conflict costs an afternoon; a section is a name and a parent, and a file record a description and alternative text. Recorded rather than pretended away |
| Showing an editor *what* changed | Not started. A refused save says somebody else changed the content; it does not show their version beside yours |
| A rich-text editor | Not started, deliberately. The body is a text area containing markup, so sanitising does not depend on an editor behaving |
| ~~Inline **styles** are still allowed~~ | **Closed by feature 016.** `style-src` is `'self'` alone. The concession existed only for the generic administration screens, which no longer exist |
| A policy reporting endpoint | Not started. A `report-to` pointing nowhere is a comment, so the policy is enforced instead |
| Rate limiting on anything but sign-in | Not started. The public site and the read-only API are unthrottled |
| Public registration | **Deliberately absent.** Accounts are created by an administrator; a sign-up form is a way to fill a database with strangers |
| Ending other sessions on a password change | **Not possible.** This CMS keeps no registry of open sessions. The account screen says so rather than implying otherwise |
| Any email other than a reset link | Not started. Nothing notifies anybody of anything else, and `MAILER_DSN` is `null://null` until somebody configures it |
| Confirming a change of email address | Not started. An administrator can change an account's address on the accounts screen, and nothing verifies the new one belongs to anybody |
| "Remember me", two-factor, session expiry policy | Not started |
| Filtering the log | Not started. Newest first and paged is enough to be useful; filtering by person or by kind is a real improvement and its own work |
| Recording *what changed* in an edit | Deliberately absent. The log records decisions, not keystrokes; showing an editor the difference between two versions is feature 009's open follow-up rather than this one's |
| Expiring old entries | **Nothing expires, on purpose.** A record that deletes itself after ninety days cannot answer a question asked on the ninety-first. The table grows, and that is what a record does |
| Rate limiting on search | **Not implemented.** A public, unauthenticated, unbounded-cost endpoint, and the cheapest thing on the site to abuse. The query is bounded in length and the results in number, which is not the same as a limit. Belongs with the caching work below |
| Snippet highlighting in results | Not started. A result shows the same summary the rest of the site shows, rather than the sentence the match was in |
| Search in more than English | Not started. The stemming configuration is hard-coded, matching the language the constitution requires everything to be written in |
| The search index expression is duplicated | Between `src/Search/SiteSearch.php` and the migration that creates the GIN indexes. They must match character for character or PostgreSQL silently reads every row instead. Nothing enforces it |
| A sitemap index | Not needed yet, and recorded as a limit. One document holds fifty thousand addresses; past that the format requires an index of sitemaps, and this serves one document with a ten-thousand ceiling |
| Full article bodies in the feed | Deliberately absent. The feed carries summaries, so it announces rather than duplicates |
| Caching of **pages** | Not started, and deliberately so — the menu costs one query per request. Files are cached by the browser since feature 012; HTML is not cached at all |
| Security and quality audits | **Not started, and the largest process debt in the project.** The constitution requires a `symfony-reviewer` pass at phase 4 of every feature and none of the fourteen has had one, because no session that built them could spawn subagents. Mechanical checks were verified directly and the evidence is in each feature's `tasks.md`, but that is not the same thing |
| GitHub Actions CI | **Running, and green since feature 011.** It had been red on every merge since 007 and nobody had looked. See below |

## CI was red for four features, and this file said it had never run

Worth recording in full, because the failure was in the process rather than in
any line of code.

`docs/status.md` claimed the workflow was "written, never executed" from the
first feature until the eleventh. It had in fact been running on every push since
the beginning, and failing on every merge since feature 007. Nobody had looked,
including whoever wrote the sentence saying it had never run — the claim was
inherited from one commit to the next and stopped being checked.

What it was catching:

- **Three failures that the development machine could not see.** Symfony does not
  rebuild a non-debug container when a file changes, and the only tests that boot
  one are the ones asserting a 404 is identical whatever address missed. Locally
  they passed against a stale container; in CI, which builds from nothing every
  time, they failed. That is precisely the class of bug CI exists to find, and it
  found it four features before anybody read the log.
- **A security check whose answer depended on the operating system.** A PNG with
  PHP source appended was refused on Windows and accepted on Linux. The reason
  turned out to be neither PHP nor libmagic: **Windows Defender locks a temporary
  file containing `<?php system(...)`**, so `finfo` could not read it and reported
  no type at all. The test had been written to match that, and its comment
  explained the refusal as the detector being "stricter than expected".

Both are fixed. The 404 pages no longer carry preview metadata, and the polyglot
rule is now explicit in `App\Service\Media\TrailingDataDetector` — a file that
carries bytes past its own end is refused, the same way on every machine.

The lesson is one line, and it is the constitution's: **a status this file
reports must be a status somebody checked.** `gh run list` takes two seconds.

## Known gaps in what *is* built

Recorded because behaviour that looks complete and is not is worse than a missing
feature.

- **Slug regeneration is not enforced.** `PublishableContent` guarantees an
  address stops changing after publication, because that needs no other row. It
  cannot guarantee an address is regenerated when a draft's title changes,
  because uniqueness needs the database — that is `UniqueSlugGenerator`'s job,
  and a caller that sets a title without going through it leaves the slug stale.
  Acceptable while the only callers are tests and fixtures; it closes when the
  administration layer gives editing a single entry point. See
  [ADR 6](adr/0006-generate-slugs-in-a-service-and-freeze-them-at-publication.md).
- **A published address cannot be changed at all.** Renaming published content
  with a redirect is a legitimate future need and will need its own decision — an
  alias table — rather than a relaxation of the freeze.
- **`User::$password` starts empty.** Symfony's hasher needs the user object to
  choose a hasher, so the account exists before its hash does. An empty hash
  matches nothing, so the intermediate state cannot authenticate.
- ~~**Content markup is rendered unsanitised.**~~ **Closed by feature 004.**
  Everything stored through an administration screen is sanitised on the way in,
  so what a reader receives is what was reviewed. Two residual notes: content
  written before feature 004 — the development fixtures — never went through
  that path, and tightening the allow-list later will not retroactively clean
  what is already stored. Both are in
  [ADR 10](adr/0010-sanitise-markup-on-the-way-in.md).
- **A page can never be called `articles`, `sections`, `topics`, `api` or
  `admin`**, and any future root-level prefix adds to that list. See
  [ADR 8](adr/0008-public-address-scheme.md).

## Known constraints

- ~~**Docker is unavailable on the development machine.**~~ **No longer true as of
  2026-08-17.** Docker 29.7.2 with WSL2 is installed and working, and
  `compose.yaml` has been verified — `docker compose up -d database` reaches a
  healthy PostgreSQL 16.15 container. Native PostgreSQL remains the default
  because it holds the migrated databases; the compose stack is a supported
  alternative. See [ADR 7](adr/0007-docker-is-available-after-all.md), which
  supersedes [ADR 3](adr/0003-postgresql-natively-instead-of-docker.md).
- **A non-debug test container is not rebuilt when a file changes.** Symfony only
  checks for changes when debugging, and two test classes boot with debug off —
  the ones asserting about 404 pages. A change they should see can therefore be
  invisible to them while every other test passes. `php -d memory_limit=1G
  bin/console cache:clear --env=test --no-debug` is the fix, and the assertion in
  `SecurityHeadersTest` that this bit says so in its failure message.
- **The test environment needs `.env.test.local`.** Symfony deliberately does not
  load `.env.local` when `APP_ENV=test`, so local database credentials have to be
  repeated there. The file is gitignored; CI sets `DATABASE_URL` itself.
- **The API is read-only by design**, so it is not a complete headless interface.
  See [ADR 2](adr/0002-twig-monolith-with-read-only-api.md).
