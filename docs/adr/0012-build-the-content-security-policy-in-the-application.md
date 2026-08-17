# 12. Build the content security policy in the application rather than pulling in a bundle

- **Status**: Accepted
- **Date**: 2026-08-17
- **Feature**: [specs/008-hardening](../../specs/008-hardening/spec.md)

## Context

[ADR 10](0010-sanitise-markup-on-the-way-in.md) closed with a sentence this
feature exists to act on: sanitising is one layer, and a content security policy
belongs on top of it rather than instead of it. Sanitising decides what is
stored; a policy decides what a browser will run if something hostile is stored
anyway — because the allow-list was wrong, because the content predates the
sanitiser, or because a template gained a mistake.

A policy is only worth having if it forbids inline script. That is the directive
the whole layer rests on, and it is also the one that breaks pages: this
application emits three inline scripts of its own from `importmap()`, and the
generic administration screens EasyAdmin renders emit more. Each has to be marked
with a per-response nonce that the header names, or the screen stops working.

`nelmio/security-bundle` is the obvious answer and does all of this. Two things
argued against it:

- It brings `ua-parser/uap-php` and `composer/ca-bundle` with it — three packages
  and a user-agent database, for a header and a random string.
- Most of what it does is what this project has already decided not to need: it
  offers `unsafe-inline` fallbacks by user agent, signed cookies, forced HTTPS,
  and a reporting endpoint that would report to nowhere.

The observation that settled it: EasyAdmin's templates already contain
`{% guard function csp_nonce %}` around their script tags. The guard marks them
if a Twig function named `csp_nonce` exists and leaves them alone if it does not.
That function is the entire integration contract, and it is four lines.

## Decision

Build the policy in the application.

- `App\Security\Csp\NonceGenerator` holds one value per request.
- `App\Twig\CspExtension` exposes it as `csp_nonce()`, matching the name
  EasyAdmin already guards on, so those screens mark their own scripts without a
  bundle or a template override.
- `App\EventSubscriber\SecurityHeadersSubscriber` builds the policy on
  `kernel.response` and sets it, along with the plain headers, on every response.

Take one new dependency for the other half of the feature — `symfony/rate-limiter`,
without which `login_throttling` does not exist — and none for this half.

## Consequences

**The policy is ours to keep correct.** Nothing warns us when a template gains an
inline script; a functional test does, by comparing the nonce in the header
against the nonce on every inline script in the same response, on every screen.
That test is the maintenance contract and it is why every screen is listed in it
by name.

**`style-src` keeps `unsafe-inline`.** The administration screens carry style
attributes on elements this project does not author, and an attribute cannot be
marked with a nonce. Naming a nonce in `style-src` would make matters worse
rather than better — a browser that sees a nonce there ignores `unsafe-inline`
entirely, and those screens would break. Recorded in `docs/status.md` as a known
gap. A style can deface a page; a script can take an editor's session, and those
are not the same risk.

**The importmap polyfill is off.** It is fetched from a CDN, and allowing a
third-party origin in `script-src` to support browsers released before 2023 costs
more than it buys in a self-hosted CMS. Every page here works without JavaScript.

**Three inline scripts became visible.** One of them existed only because
`assets/app.js` imported a stylesheet that the layout already links; AssetMapper
answers such an import with an empty `data:` module, which would have required
`data:` in `script-src` — a real hole opened to load nothing. The import is gone.

**Symfony strips the policy from exception responses while debugging**, so that
its own exception page renders. That is correct and it means the 404 test has to
run with debug off, which is what a reader gets anyway.

**The policy you read in a browser during development is not the policy a reader
gets.** The web debug toolbar rewrites it on the way out — it appends its own
nonce and `'unsafe-inline'` to `script-src` so that the toolbar itself runs. That
is Symfony's `ContentSecurityPolicyHandler`, it happens only where the profiler
is loaded, and it is the reason every assertion about the policy lives in the
test suite rather than in a browser: the test environment has no toolbar, and
what it sees is what production sends.

**Two subtleties cost a working day between them**, and both are recorded where
they bit:

- The nonce cannot be stored on the request object. When a controller throws,
  Symfony pops the request off the stack and *then* renders the error page, so
  anything keyed to "the current request" is unreachable at exactly the moment a
  404 needs a policy. It lives in a property that a `kernel.request` listener
  clears.
- Sub-requests must be included when setting headers. An error response *is* a
  sub-request's response; guarding on `isMainRequest()` delivers every 404 and
  every 500 with no policy at all.

## Alternatives considered

**`nelmio/security-bundle`.** Rejected above: three packages for a header, most
of its surface unwanted. Worth revisiting the day this project wants reporting or
per-user-agent behaviour, neither of which is on any list.

**Report-only first.** A report-only policy with nowhere to send reports is a
comment. Enforcing it and letting a test find what breaks is what FR-012 asks
for, and it found two things.

**Hashes instead of a nonce.** Would allow a static policy and no per-request
work, but every hash has to be recomputed whenever an inline script changes —
including the ones inside EasyAdmin, which change when it is upgraded. A nonce
survives upgrades.
