---

description: "Task list for feature 015 — release readiness"
---

# Tasks: Release Readiness

**Input**: Design documents from `/specs/015-release-readiness/`

**Written after the discovery and before the fixes**, which is the honest order:
none of this was planned. It came out of walking the running site with a script
that requested every address the sitemap lists, every administration screen, and
every derived size of every image.

## The risk this feature actually carries

Every change here deletes files. That is the whole shape of it, and it is why the
same rule appears in three places and is tested in all three:

**Leave alone anything you do not understand.** The pruning command parses a
derived name into a size and a stored filename, both of which this application
generated; anything that does not parse is left where it is. The fixture cleanup
goes further and requires a name to *look* generated — thirty-two hexadecimal
characters and an extension — before it will consider removing it.

A pruner that deletes what it cannot identify is a pruner nobody should be
willing to run, and the test that matters is not "the orphan went" but **"the
live file and the stranger both stayed"**.

The second risk is the one that made this feature necessary at all: a green suite
proves the rules hold, not that somebody opening the site sees what they should.
Nothing here would have been caught by another test — it needed somebody to look.

---

## Phase 1: US1 — a site that looks like a site

- [x] T001 `src/DataFixtures/PlaceholderImage.php` — draws a 1200×800 picture with GD from a seed, encoded as the type the record claims
- [x] T002 `AppFixtures` uses it, seeded with the stored filename so each file is a different picture and the same one every load

## Phase 2: US2 — the suite keeps to itself

- [x] T003 A `when@test` override of `app.upload_directory`, pointing at `var/test-uploads`
- [x] T004 Confirm the whole suite still passes against a directory it has to create for itself

## Phase 3: US3 — bringing the disk back in step

- [x] T005 [P] Write `tests/Functional/PruneDerivedImagesTest.php` **first** — the orphan goes, the live file stays, the unrecognised file stays, the dry run removes nothing
- [x] T006 `DerivedImages::orphans()` and `::remove()`
- [x] T007 `src/Command/PruneDerivedImagesCommand.php` with `--dry-run`
- [x] T008 `AppFixtures` removes what the previous dataset left behind, refusing any name that does not look generated

## Phase 4: Polish

- [x] T009 Update `docs/status.md` and `docs/setup.md`
- [x] T010 Run `composer qa`
- [x] T011 Walk the whole running site again — every public address, every sitemap entry, every administration screen, every image at every size
- [ ] T012 `symfony-reviewer` pass — expected to remain open

## What the walk found

Listed in the order it was found, because the order is instructive: each one was
invisible to the test suite and obvious within seconds of looking.

1. **Every image was one pixel.** Right until feature 012, wrong from feature 012
   onwards, and nothing failed.
2. **The suite wrote into the developer's uploads directory.** Both environments
   resolved the same path. Found because a test needed to empty a directory and
   it was not safe to.
3. **Reloading fixtures orphaned every file.** The database is purged; the disk
   is not. Six uploads and ninety-eight derived copies were sitting there.
4. **Two files of my own were among them** — debris from an earlier draft of the
   pruning test, written before the test environment had a directory of its own.
   The pruner refused to remove them, correctly, because it did not recognise the
   names. They were removed by hand.

## Notes

- The placeholder is deliberately not a photograph. Two flat bands of colour:
  enough shape that a broken resize is obvious at a glance, and no chance of
  anybody mistaking development data for real content.
- Orphaned **originals** are removed only by the fixtures, never by a general
  command. A derived image is a cache and can be remade; an original is the only
  copy of somebody's upload, and a command that removed uncatalogued ones would
  destroy an upload whose database row failed to save.
