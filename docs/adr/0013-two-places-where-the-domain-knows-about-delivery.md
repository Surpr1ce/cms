# 13. Two places where the domain knows about delivery, and why they stay

- **Status**: Accepted
- **Date**: 2026-08-17
- **Feature**: Found by the audit after feature 017, not by any feature that
  introduced them

## Context

The constitution's first principle is marked non-negotiable:

> **Domain Independent of Delivery.** The domain must not know about HTTP or
> Twig. Controllers translate requests into service calls and hand results to
> templates.

An audit of every file under `src/Entity`, `src/Repository` and `src/Service`
found four imports that contradict it. They had been there for features without
anybody saying so, which is the part worth fixing whatever is decided about the
code.

**The media services take `Symfony\Component\HttpFoundation\File\File` and
`UploadedFile`.** `MediaStorage`, `MediaUploader` and `UploadedFileValidator`
all do.

**`AuditLog` takes `Symfony\Bundle\SecurityBundle\Security`** and reads the
signed-in account from it, rather than being told who acted.

## Decision

Both stay. Both are recorded here rather than left to be discovered again.

**The file classes stay because the alternative is a wrapper that adds nothing.**
`File` is a path and a way to ask what is in it; it lives in HttpFoundation for
historical reasons rather than because it is about HTTP. `UploadedFile` genuinely
is about HTTP — but a service whose entire job is to accept an upload cannot be
made ignorant of uploads by renaming its parameter. A `MediaUploader` taking some
`UploadedFileInterface` of our own would be the same class with an extra file
between it and the framework, and the tests would still have to construct a real
file to mean anything.

**`AuditLog` reads the actor from the session because the alternative fails
quietly.** Every call site would otherwise have to thread the current person
through — from a controller that has one, through a service that does not care,
into the log. A caller that forgot would record an action with no actor, and "no
actor" is the value this application reserves for *there genuinely was nobody*,
which is what a console command produces. A mistake and a truth would become
indistinguishable in the one record that exists to tell them apart.

## Consequences

**The principle now has two named exceptions rather than an unknown number.**
That is the actual gain here. An audit can check that the list has not grown; it
could not check a rule everybody believed was absolute while four files broke it.

**`AuditLog` cannot be unit-tested without a container.** It is exercised through
functional tests, which is where the interesting cases live anyway — who is
recorded, and whether a refused action is recorded at all.

**If a second delivery mechanism ever writes to the log** — a queue worker, an
import — it will record no actor, correctly, and the reasoning above will need
revisiting rather than merely reading.

## Alternatives considered

**Refactor both.** An interface over `File`, and an `Actor` value object passed
into every `AuditLog::record()`. Rejected above: the first adds a layer with no
behaviour, and the second turns a guarantee into a convention every future caller
has to remember.

**Relax the principle.** Rejected. It is doing real work everywhere else: no
entity, no repository and no other service knows what HTTP is, which is what
makes the same domain serveable through Twig, a JSON API, a feed, a sitemap and a
console command. A principle with two recorded exceptions is stronger than one
quietly weakened.

## Amendment, 2026-08-17 (feature 019) — a third place, and a test that closes the list

`PasswordResetController` used to compose the only email this application sends:
it built the message, rendered the body with `renderView()` and generated the
link. The architecture pass before the release moved all three into
`App\Service\Account\PasswordResetMailer`, which is the right layer for them — a
console command reissuing a link could not have reused a controller — but the
move carried a template name inward with it:

**`PasswordResetMailer` imports `Symfony\Bridge\Twig\Mime\TemplatedEmail` and
names `email/reset_password.txt.twig`.**

**It stays, and it is the acceptable shape of this compromise.** `TemplatedEmail`
is a message that says *which* template; the rendering happens in the mailer's own
listener, outside this layer. The alternative is what was there before — the
delivery boundary owning what a reset email says — or an interface over "a thing
that turns a template name and some values into a body", which is the Twig bridge
with a different name.

**What this amendment is really for is the list.** The consequence recorded above
— "an audit can check that the list has not grown" — was, until this release,
still only an intention. It is now
`LayeringTest::testTheExceptionsRecordedInAdr13HaveNotGrown()`, which pins each
exception to the file allowed to hold it: the three media services for
`HttpFoundation`, `AuditLog` for `SecurityBundle`, and `PasswordResetMailer` for
`Symfony\Bridge\Twig`. A fourth file doing any of the three fails the suite. The
same pass found that the general rule had a door beside it — the `Service` row
forbade `Twig\Environment` while admitting every `Symfony\Bridge` and
`Symfony\Bundle` class, including `BodyRenderer`, which really does render, and
`AbstractController` — and closed it.

So the count in this file's title is out of date by one, and that is left as it
is: a document that renames itself when it changes is harder to follow back than
one whose amendments are visible.
