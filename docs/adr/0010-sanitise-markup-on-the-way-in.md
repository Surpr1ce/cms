# 10. Sanitise submitted markup on the way in, with symfony/html-sanitizer

- **Status**: Accepted
- **Date**: 2026-08-17
- **Feature**: [specs/004-content-administration](../../specs/004-content-administration/spec.md)

## Context

Since feature 002, `docs/status.md` has carried this:

> Content markup is rendered unsanitised. This is safe only because there is no
> editor yet and the only author is a developer loading fixtures. Whichever
> feature first lets somebody paste markup into the CMS inherits this obligation.

Feature 004 is that feature. It gives somebody holding the author role — the
least trusted role the system has — a text area whose contents every reader of
the site will load, and which an editor will open in their own browser in order
to review it.

The failure mode is specific and worth naming. An author submits an article
containing a script element. It looks fine in the list. An editor opens it to
review, and the script runs in the editor's browser with the editor's session:
it can publish anything, delete anything, and if the editor is an administrator,
create a new administrator. Then it is published and runs in every reader's
browser too. Nothing about this looks like a bug at any point.

Two questions follow. What sanitises, and when.

## Decision

**Use `symfony/html-sanitizer`.** It is added to `composer.json`, the first new
runtime dependency since the skeleton.

**Sanitise on the way in.** Body text is sanitised in a service before it reaches
the entity, so what is stored is already safe and what is rendered is exactly
what was stored.

**Title and summary are not markup at all** and are stored as plain text with any
tags stripped, because they are rendered as text everywhere.

## Alternatives considered

**Write our own sanitiser.** Rejected without much thought, and worth recording
precisely because it should not need thought. HTML sanitising is adversarial
parsing: the attacks are mutation XSS, namespace confusion, and the differences
between how a parser and a browser read malformed markup. This is the worst place
in an application to be inventive.

**Escape everything and store plain text.** The safest possible answer, and it
removes formatting from a content management system. Rejected on the grounds that
formatted articles are the product.

**A content security policy instead of sanitising.** A good second layer and not
a substitute: it does not stop injected markup from rewriting a page, and it is
one misconfigured header from being absent. Worth adding later, on top of this,
not instead of it.

**Sanitise on the way out**, at render time. Genuinely arguable, and it has one
real advantage: changing the policy retroactively cleans everything, including
content stored before the policy existed. Rejected for two reasons. A template
that forgets to sanitise is a template that ships an XSS, and there will be more
templates than there are storage paths. And what an editor reviews would not be
what is stored, so a review would be of the rendered form rather than of the
thing itself.

**Both ways.** Defensible, and rejected as a way of avoiding the decision. Two
sanitising points mean two policies that will drift, and the second one hides
failures in the first — the tests would pass with the input-side sanitiser
removed entirely.

## Consequences

- What is stored is what is served. A reader receives exactly the bytes an editor
  approved.
- **Changing the policy does not clean content already stored.** This is the real
  cost of sanitising on the way in. If the allow-list is ever tightened,
  everything written before then keeps whatever it was allowed at the time, and
  correcting it needs a deliberate migration pass. Recorded here because it will
  not be obvious to whoever tightens the policy.
- Every path that stores body text must go through the sanitising service. One
  service, called from one place per content type, and a test that asserts on
  what was **stored** rather than on what was displayed — a test that checks the
  rendered output would pass even if sanitising were removed and the template
  were escaping instead.
- The public templates still render body text with `|raw`, and that is now
  correct rather than merely accepted: the text is sanitised before it gets
  there.
- Content loaded by the fixtures bypasses the administration screens and is
  therefore not sanitised by this path. That is acceptable — the fixtures are
  written by developers and never run in production — and it is why the tests
  submit through the form rather than through a factory.
- A content security policy is still worth adding, as a second layer, and is not
  part of this feature.
