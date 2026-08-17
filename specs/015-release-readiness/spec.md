---

description: "Specification for feature 015 — making a fresh checkout of this CMS actually look like it works"
---

# Feature Specification: Release Readiness

**Feature Branch**: `015-release-readiness`
**Created**: 2026-08-17
**Status**: Draft
**Input**: Everything found while walking the running site before cutting a first release. Not a planned feature — a list of things that were true, wrong, and invisible from the test suite.

## Why this feature exists

Fourteen features pass 865 tests and the site answers on every address. It still
did not *look* like it worked, and two of the reasons were real defects rather
than cosmetics.

**Every image on the development site was one pixel.** The fixtures wrote a
one-by-one placeholder, which was right when nothing resized anything — a browser
stretches it to whatever the layout asks for. Feature 012 changed that: the site
now asks for a thumbnail, a medium and a large, and a single pixel scaled to
sixteen hundred is not a picture of anything. Anybody opening this CMS for the
first time saw grey rectangles and concluded the images were broken.

**The test suite wrote its files into the developer's uploads directory.** Both
environments resolved `app.upload_directory` to `var/uploads`, so every upload
test and every request for a derived size left debris among real uploads. It also
meant no test could tidy up after itself: deleting a stray file might delete a
real one.

**Reloading the fixtures left the disk behind.** `doctrine:fixtures:load` purges
the database and does not touch files, so every reload orphaned a full set of
uploads and derived copies. After a few reloads the directory is mostly rubbish
and nothing can tell which files are live.

None of these is visible from a green test suite, which is the point worth
recording: a suite proves the rules hold, not that somebody opening the thing
sees what they should.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A fresh installation looks like a working site (Priority: P1)

Somebody clones this, runs the setup, opens it, and sees articles with pictures.

**Independent Test**: Load the fixtures, open the site, look at it.

**Acceptance Scenarios**:

1. **Given** a freshly loaded dataset, **When** the site is opened, **Then** the
   images are pictures rather than stretched pixels
2. **Given** a lead image, **When** each derived size is requested, **Then** each
   is genuinely smaller than the one above it
3. **Given** the repository, **When** it is cloned, **Then** it carries no binary
   image assets — the pictures are drawn, not shipped

### User Story 2 - The suite does not touch the developer's files (Priority: P1)

Running the tests leaves the uploads directory exactly as it was.

**Independent Test**: Note what is in `var/uploads`, run the whole suite, look
again.

**Acceptance Scenarios**:

1. **Given** files in the development uploads directory, **When** the suite runs,
   **Then** nothing there is added, changed or removed
2. **Given** a test that needs an empty directory, **When** it empties one,
   **Then** it cannot reach anything outside the test environment's own

### User Story 3 - The disk can be brought back in step with the catalogue (Priority: P2)

An operator can remove derived images nothing points at, and see what would go
before it goes.

**Independent Test**: Create an orphan, run the command with and without
`--dry-run`.

**Acceptance Scenarios**:

1. **Given** derived files whose originals are no longer catalogued, **When** the
   command runs, **Then** they are removed
2. **Given** derived files whose originals *are* catalogued, **When** the command
   runs, **Then** they are kept
3. **Given** a file whose name the application does not recognise, **When** the
   command runs, **Then** it is left alone
4. **Given** `--dry-run`, **When** the command runs, **Then** it lists and
   removes nothing
5. **Given** a reload of the development fixtures, **When** it finishes, **Then**
   the disk holds exactly what the catalogue holds

### Edge Cases

- **A pruner must never delete what it does not understand.** Somebody may have
  put a file there.
- **A fixture load must not be able to delete a production upload.** It only runs
  after a command that has already emptied the database, and it still refuses
  names that do not look generated.
- **A drawn placeholder must match the type the record claims**, or `nosniff`
  stops the browser rendering it — the mistake feature 001's fixtures already
  made once.

## Requirements *(mandatory)*

- **FR-001**: Development placeholder images MUST be large enough that every
  derived size is a genuine reduction
- **FR-002**: Placeholders MUST be generated, not committed as binary assets
- **FR-003**: A placeholder MUST be encoded in the type its record claims
- **FR-004**: The same record MUST produce the same picture on every load
- **FR-005**: The test environment MUST use an uploads directory of its own
- **FR-006**: A command MUST remove derived images whose originals are no longer
  catalogued
- **FR-007**: That command MUST keep everything still in use, and MUST leave any
  name it cannot parse alone
- **FR-008**: That command MUST offer a dry run
- **FR-009**: Loading the development fixtures MUST leave the disk holding
  exactly what the catalogue holds

## Success Criteria *(mandatory)*

- **SC-001**: A first-time reader of this repository sees a site with pictures
- **SC-002**: Running the suite leaves the developer's uploads untouched
- **SC-003**: Reloading fixtures leaves no orphans
- **SC-004**: The pruner cannot delete anything it did not create
- **SC-005**: `composer qa` passes and the whole suite grows

## Assumptions

- **Two flat bands of colour, not a photograph.** Nobody should mistake
  development data for real content, and a picture that looks real invites
  screenshots that misrepresent the project.
- **Orphaned *originals* are pruned only by the fixtures**, never by a general
  command. A derived image is a cache and can be remade; an original is the only
  copy of somebody's upload, and a command that removes uncatalogued ones would
  destroy an upload whose row failed to save.
