# Implementation Plan: Content Administration

**Branch**: `004-content-administration` | **Date**: 2026-08-17 | **Spec**: [spec.md](spec.md)

## Summary

The screens through which content is written, and the sanitiser that makes them
safe to have.

The technical approach in one sentence: **the entity is never handed text a
person typed — a form produces a command object, a service sanitises it and
applies it, and the controller does neither.**

That indirection buys the property FR-006 asks for. If a controller mapped a form
straight onto an entity, sanitising would be something each controller remembers
to do, and the day somebody adds a screen is the day one of them forgets. With a
service in between there is one place body text can enter the model, and it
sanitises unconditionally.

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Symfony Form and Validator (installed), and
**`symfony/html-sanitizer`, newly added** — the first runtime dependency since the
skeleton. See [ADR 10](../../docs/adr/0010-sanitise-markup-on-the-way-in.md).

**Storage**: no schema change. **No migration.**

**Testing**: PHPUnit 13. The sanitising tests assert on what was **stored**, read
back from the database, never on what a page displayed — a test that checks the
rendered output passes even with sanitising removed, because Twig escapes by
default.

**Constraints**: PHPStan level max. `strict_variables` in test. `composer qa`
untouched.

**Scale/Scope**: 1 sanitising service, 2 command objects, 2 form types, 2
controllers, ~10 templates, an admin layout. Roughly 80 tests.

## Constitution Check

| Principle | Verdict |
| --- | --- |
| **I. Domain Independent of Delivery** | **Pass.** The command objects are plain data, the sanitising service takes strings and returns strings, and neither knows about a request. `Article` and `Page` gain nothing. |
| **II. Specification Before Implementation** | **Pass** — spec, plan and tasks before code, as in feature 003. |
| **III. Quality Gate Is Not Negotiable** | **Pass, planned.** |
| **IV. Tests Prove Failure Paths** | **Pass.** Every address gets the anonymous case and the insufficient-permission case. Every sanitising rule gets a hostile input. Every refusal gets a test that submits directly rather than looking for an absent button — SC-004 says so explicitly, because a hidden control is not a permission. |
| **V. Decisions Are Recorded** | **Pass** — ADR 10, for the new dependency and for sanitising on the way in rather than on the way out. |
| **VI. Status Is Reported Honestly** | **Pass** — including that concurrent edits are last-write-wins and that content stored before this feature was never sanitised. |

## Project Structure

```text
src/
├── Controller/Admin/
│   ├── DashboardController.php       # replaces the feature-003 placeholder
│   ├── ArticleController.php         # list, new, edit, delete, state changes
│   └── PageController.php
├── Form/
│   ├── ArticleType.php
│   ├── PageType.php
│   └── Command/
│       ├── ArticleCommand.php        # plain data; what a form produces
│       └── PageCommand.php
└── Service/Content/
    ├── ContentSanitiser.php          # the one place markup is cleaned
    ├── ArticleEditor.php             # create/apply, slug assignment, sanitising
    ├── PageEditor.php
    └── PublicationService.php        # the four transitions, from a screen

templates/admin/
├── layout.html.twig
├── dashboard.html.twig
├── article/{index,form,delete}.html.twig
└── page/{index,form,delete}.html.twig

tests/
├── Unit/Service/Content/ContentSanitiserTest.php     # the hostile catalogue
├── Integration/Service/Content/ArticleEditorTest.php # slug regeneration, sanitising on store
└── Functional/Admin/
    ├── ArticleAdministrationTest.php
    ├── PageAdministrationTest.php
    └── AdminPermissionsTest.php                      # every address × every role
```

**Structure Decision**: `src/Form/` is not in the `CLAUDE.md` architecture tree
and is added there in the same change — the fourth time this correction has been
needed, after `Exception/`, `Factory/` and `Command/`.

The command objects live under `src/Form/Command/` rather than in the domain,
because they exist to carry what a form collected and nothing else. Putting them
in `src/Entity/` would make the domain aware of a delivery concern; putting them
in `src/Service/` would suggest they are the service's interface, when they are
the form's output.

## Complexity Tracking

| Choice | Why needed | Simpler alternative rejected because |
|--------|------------|--------------------------------------|
| A command object between the form and the entity | The entity has no setters for status, slug or author, and `Article` requires a title, a slug and an author in its constructor. A form cannot produce that, and giving the entity setters to suit a form would undo feature 001's design | Mapping the form onto the entity directly was rejected on that ground, and because it would put sanitising in every controller instead of in one service |
| An editor service per content type rather than one generic one | Articles and pages differ in what they carry — an author and a section against a parent and a menu position — and a generic service would need the union of both with half of it null | One service with optional everything was rejected; the nulls would be where the rules go to hide |
| Sanitising in a service rather than in a Doctrine listener | A listener would catch every path including the fixtures, which is tempting. Rejected because it makes the rule invisible at the point where content is written, and because a listener that rewrites data on flush is very hard to reason about when it is wrong | A form data transformer was the other candidate: closer to the input, but it binds sanitising to the form layer, so a future import or API path would silently skip it |
