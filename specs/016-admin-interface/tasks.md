---

description: "Task list for feature 016 — administration interface"
---

# Tasks: Administration Interface

**Input**: Design documents from `/specs/016-admin-interface/`

**Written after the report and before the fixes.** Like feature 015, none of this
was planned: it came from somebody opening the running site, taking two
screenshots, and asking a question that turned out to be the important one.

## The risk this feature actually carries

Replacing three working screens with three new ones is a way to lose rules.

EasyAdmin's screens were not plain scaffolding — each was overridden for a
reason, and the reasons outlive the bundle. An address generated once and then
fixed. A section's deletion uncategorising its articles and moving its
subsections up to their grandparent rather than to the top. A password field
bound to nothing, so a stored hash is never rendered and never assigned from a
form. An administrator unable to delete their own account. An account owning
content refused with a sentence rather than a foreign-key error.

Every one of those is invisible in a screenshot and would be lost silently. So
the replacement screens are tested against the same assertions the generic ones
were, written against **what survived** rather than which class was called — a
test that checked for a service call would pass while the articles were
destroyed.

The second risk is the form theme quietly breaking a form somewhere else. It is
registered globally, which is the point: every form in the application goes
through it, including the ones on the public site.

---

## Phase 1: US1 — forms that can be used

- [x] T001 `templates/form/theme.html.twig` — label above, field, help, errors; one set of classes for every control
- [x] T002 Register it globally in `config/packages/twig.yaml`
- [x] T003 Confirm the rendered markup against every form the application has

## Phase 2: US2 — one administration area

- [x] T004 [P] Rewrite `tests/Functional/Admin/ManageScreensTest.php` **first**, against the addresses the new screens will have
- [x] T005 `SectionCommand`, `LabelCommand`, `AccountCommand` and their form types
- [x] T006 `TaxonomyEditor` — creates and updates sections and labels, generating an address once
- [x] T007 `AccountEditor` — hashes a password, leaves a blank one alone, records creation and a change of permissions
- [x] T008 `SectionController`, `LabelController`, `AccountsController`, `ManageController`
- [x] T009 Templates for all four, in the same layout as articles and pages
- [x] T010 Remove the EasyAdmin controllers, template and dependency
- [x] T011 Drop `unsafe-inline` from `style-src`, which nothing needs any more

## Phase 3: US3 — a landing page that answers something

- [x] T012 `DashboardController` — counts, the viewer's own drafts, recent log entries
- [x] T013 `templates/admin/dashboard.html.twig`, with every tile behind the permission that governs what it counts

## Phase 4: Polish

- [x] T014 [P] Update `docs/status.md` and `CLAUDE.md` — the stack no longer includes EasyAdmin
- [x] T015 Run `composer qa`
- [x] T016 Walk the whole running site again
- [ ] T017 `symfony-reviewer` pass — expected to remain open

## What this cost and what it bought

**Removed**: `easycorp/easyadmin-bundle` and two packages it brought with it.

**Gained**: one interface instead of two, and a strictly tighter content security
policy — `style-src 'unsafe-inline'` existed only because those screens carried
style attributes on elements this project did not author. The concession was
documented, justified and correct, and it stopped being needed the moment the
thing that needed it was gone. A recorded concession is worth re-reading whenever
the reason for it changes.

## What the work found

- **Twig's `default` filter treats `false` as empty.** `canBeDeleted|default(true)`
  turned a genuine "no, this account owns things" into `true` and offered the
  delete button anyway. Found by a test that expected the explanation and got the
  button.
- **The dashboard offered an author a link to the pages screen.** The counts were
  unconditional while the links below them were not. Caught by a permissions test
  written four features earlier, which is the one piece of good news here.
- **A stale non-debug test container survived the dependency removal** and failed
  with "class TwigComponentBundle not found". The same trap as feature 011:
  Symfony does not rebuild that container on a file change.
