---

description: "Task list for feature 008 — hardening"
---

# Tasks: Hardening

**Input**: Design documents from `/specs/008-hardening/`

**Written before the implementation.**

## The risk this feature actually carries

It is not that the measures fail to work. It is that they *appear* to work.

A content security policy is a header. A header is present whether or not it
protects anything, and both a real policy and a policy loose enough to permit
everything read as "CSP: yes" in an audit. The same is true of throttling: a
limiter configured but not wired refuses nothing, and the sign-in form looks
exactly as it did.

So every requirement here is tested from the outside, against behaviour: the
sixth attempt is refused *without checking the password*; the policy names a
nonce that *matches the one on the script tag in that same response*. A test
asserting that a configuration key holds a value would pass on a broken
installation.

The second risk is breakage. This feature touches every response the application
produces, which is the largest blast radius of any feature so far, and it does so
to screens built across five previous features. FR-012 exists for that, and it is
tested per screen rather than by a single assertion that "the site still works".

---

## Phase 1: Setup

- [x] T001 Install `symfony/rate-limiter` — the only new dependency, and required for `login_throttling` to exist at all
- [x] T002 Write the ADR recording why the policy is built here rather than pulled in, and what the nonce contract is — shipped as `docs/adr/0012-build-the-content-security-policy-in-the-application.md`
- [x] T003 Confirm a cache pool exists for the limiter's counters and record what it means when the process restarts — `cache.rate_limiter`, on the filesystem, so it survives a restart. Overridden in the test environment for the reason `config/packages/cache.yaml` gives

## Phase 2: US1 — sign-in throttling

- [x] T004 [P] [US1] Write `tests/Functional/Security/SignInThrottlingTest.php` **first** — the limit, the refusal, the message, the independence of one client from another, the exemption of a signed-in person
- [x] T005 [US1] Enable `login_throttling` on the `main` firewall in `config/packages/security.yaml`, limited per address and per handle
- [x] T006 [US1] Make the refusal legible on the sign-in page — a person told nothing assumes the site is broken (FR-003)
- [x] T007 [US1] Confirm the refusal reveals nothing about whether the handle exists

## Phase 3: US2 — content security policy

- [x] T008 [P] [US2] Write `tests/Functional/Security/SecurityHeadersTest.php` **first** (named for what it grew into, US2 and US3 together) — the policy is present, forbids inline script, names the nonce that is actually on the response's own script tag, and differs between two responses
- [x] T009 [US2] `src/Security/Csp/NonceGenerator.php` — one value per request, generated once and reused within it
- [x] T010 [US2] `src/Twig/CspExtension.php` — a `csp_nonce(usage)` function. Named to match what the generic administration templates already guard on, so they mark their own scripts without being modified
- [x] T011 [US2] `src/EventSubscriber/SecurityHeadersSubscriber.php` — builds the policy from the request's nonce and sets it on the response
- [x] T012 [US2] Pass the nonce to `importmap()` in `templates/base.html.twig`, which is the one inline script the public site emits
- [x] T013 [US2] Walk every screen — home, article, section, label, page, 404, sign-in, all six administration areas — and fix what the policy breaks (FR-012)

## Phase 4: US3 — the remaining headers

- [x] T014 [P] [US3] Write the header assertions into `SecurityHeadersTest` — nosniff, frame refusal, referrer policy, on a public and an administration response
- [x] T015 [US3] Add them in the same subscriber, so there is one place a header comes from

## Phase 5: Polish

- [x] T016 [P] Update `docs/status.md` — both bold "not done" rows become done, and the `unsafe-inline` for styles moves to "known gaps"
- [x] T017 Run `composer qa`
- [x] T018 Verify by hand on the dev server: read the headers, exhaust the limit, load an administration screen with the browser console open
- [ ] T019 `symfony-reviewer` and `security-auditor` passes — expected to remain open

## Notes

- The nonce function is named `csp_nonce` because EasyAdmin's templates already
  contain `{% guard function csp_nonce %}`. Defining a function by that name is
  the whole integration; no bundle and no template override is needed.
- The policy is asserted against the *same response* that carries the script
  tag. A test that read the header and a script tag from two different requests
  would pass against a nonce that never matches.

## What the tests found

- **The 404 carried no headers at all.** An error response is a *sub-request's*
  response — the main request never reaches `kernel.response` when a controller
  throws — so guarding on `isMainRequest()` skipped every 404 and every 500.
- **The nonce could not live on the request object.** Symfony pops the request
  off the stack before rendering the error page, so a value keyed to "the current
  request" was unreachable at exactly the moment a 404 needed a policy.
- **Throttling broke the rest of the suite before it was isolated.** The limiter's
  counters are ordinary cache entries and outlived the test that created them, so
  every test signing in shared one allowance and the suite failed in different
  places depending on the order it ran in. Fixed in `config/packages/cache.yaml`,
  not by weakening the limit.
- **Three existing tests compared two responses byte for byte.** They exist to
  prove a draft address is indistinguishable from one that means nothing, and a
  per-response nonce is a legitimate difference. They now blank that one value
  and compare everything else, which keeps the assertion rather than relaxing it.
- **A stale non-debug container hid all of this** for half an hour. Symfony does
  not rebuild that container when a file changes, and the only tests that boot it
  are the ones asserting about 404 pages.
