---

description: "Specification for feature 012 — serving images at a size a reader needs, and not serving them twice"
---

# Feature Specification: Media Delivery

**Feature Branch**: `012-media-delivery`
**Created**: 2026-08-17
**Status**: Draft
**Input**: Two rows `docs/status.md` has carried since feature 005 — "Image resizing, thumbnails, format conversion: not started. Every image is served at the size it was uploaded" and "A caching layer in front of file serving: not started. A PHP process serves every image".

## Why this feature exists

Feature 005 made a deliberate trade: uploads live outside the web root and are
served by the application, so nothing in that directory can be reached as
anything but a response this CMS composed. ADR 11 said in as many words that the
cost was a PHP process per image and that it was worth measuring before
optimising.

It has not been measured, and two things about it are now visible without
measuring:

**Every byte is sent again on every page view.** The response carries no
validators and no caching instructions, so a reader scrolling a listing of twelve
articles downloads twelve lead images, and downloads them all again when they
come back tomorrow. The stored name is generated and never reused, which means
the bytes at an address can never change — the strongest possible caching
position, and the CMS currently declines it.

**Every image is sent at the size it was uploaded.** An author uploading a
photograph from a telephone uploads four thousand pixels of it, and a listing
that shows it two hundred pixels wide sends all four thousand. That is not a
detail: on a slow connection it is the difference between a page arriving and a
page giving up.

Neither is a security question, and that is worth saying — this feature must not
weaken anything feature 005 built. The bytes stay outside the web root, the type
still comes from what was detected at upload, and `nosniff` still applies to
everything.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An image is not downloaded twice (Priority: P1)

A reader who has already seen an image does not download it again.

**Why this priority**: It applies to every image on every page and costs
nothing.

**Independent Test**: Request an image, note what the response says about
caching, ask again with what it said, and observe that no bytes come back.

**Acceptance Scenarios**:

1. **Given** a stored image, **When** it is requested, **Then** the response
   carries a validator and instructions about how long it may be kept
2. **Given** a reader holding that validator, **When** they ask again, **Then**
   they are told it has not changed and receive no bytes
3. **Given** a stored image, **When** it is requested, **Then** it may be cached
   for a long time, because a stored name is generated once and never points at
   different bytes
4. **Given** a file that is not an image — a document — **Then** the same applies
   to it

### User Story 2 - An image arrives at the size it is shown (Priority: P1)

A listing showing an image at a few hundred pixels receives a few hundred pixels
of it, not four thousand.

**Why this priority**: Equal to US1 and for the same reason — it applies to
every reader on every page, and the readers it helps most are the ones on the
worst connections.

**Independent Test**: Request a named size of an image and compare its
dimensions and weight against the original.

**Acceptance Scenarios**:

1. **Given** a stored image, **When** a named size of it is requested, **Then**
   an image of at most those dimensions comes back
2. **Given** a resized image, **When** it is compared to the original, **Then**
   its proportions are the same — nothing is stretched or cropped
3. **Given** an image smaller than the size asked for, **When** it is requested,
   **Then** it is not enlarged
4. **Given** a size that was never defined, **When** it is requested, **Then**
   the answer is "not found" rather than an image of an arbitrary size
5. **Given** the same size of the same image requested twice, **When** the second
   request arrives, **Then** the work is not done again
6. **Given** a file that is not an image, **When** a size of it is requested,
   **Then** the answer is "not found"

### User Story 3 - Listings use the smaller images (Priority: P2)

The site actually asks for the sizes it now has, rather than defining them and
continuing to send originals.

**Why this priority**: Without it the first two stories are a feature nobody
uses. Lower only because it is the consequence rather than the mechanism.

**Independent Test**: Read the markup of a listing and of an article and see
which addresses the images come from.

**Acceptance Scenarios**:

1. **Given** a listing with lead images, **When** it is rendered, **Then** the
   images are the listing size
2. **Given** an article page, **When** it is rendered, **Then** its lead image is
   a size suited to the column it sits in, not the original
3. **Given** an image with no alternative text, **Then** nothing about this
   changes — it is still not shown

### Edge Cases

- **A derived image is still a file this application wrote.** It has to live
  where uploads live: outside the web root, named by something generated, served
  through the same controller with the same headers.
- **The derived name must not be forgeable into a path.** The same rule as the
  stored name, and for the same reason.
- **Two requests for the same missing size at the same moment** must not corrupt
  a half-written file.
- **A stored file whose bytes are gone** still has to answer "not found" rather
  than fail while trying to resize nothing.
- **An enormous image** must not be resized in a way that exhausts memory and
  takes the site down. A pixel budget, not just a byte budget.
- **Deleting a file must delete what was derived from it**, or an uploads
  directory grows forever with orphans nothing points at.
- **A PDF has no thumbnail** and asking for one is not an error in the sense of a
  crash; it is a "not found".

## Requirements *(mandatory)*

### Functional Requirements

**Caching**

- **FR-001**: A served file MUST carry a validator a browser can present later
- **FR-002**: A request presenting a current validator MUST be answered without
  the bytes
- **FR-003**: A served file MUST carry instructions permitting it to be cached
  for a long period, justified by stored names never being reused
- **FR-004**: Caching MUST apply to derived images exactly as to originals

**Derived sizes**

- **FR-005**: The application MUST offer a fixed, named set of sizes
- **FR-006**: A named size MUST produce an image fitting within those dimensions
  with its proportions unchanged
- **FR-007**: An image already smaller than the size asked for MUST NOT be
  enlarged
- **FR-008**: A size that is not one of the named ones MUST NOT be served
- **FR-009**: A derived image MUST be produced once and reused
- **FR-010**: A derived image MUST be stored outside the web root, under a
  generated name, and served with the same headers as an original
- **FR-011**: Only image types MUST be derivable; anything else MUST answer "not
  found"
- **FR-012**: Producing a derived image MUST be bounded, so that one upload
  cannot exhaust the memory of the process
- **FR-013**: Deleting a catalogued file MUST delete everything derived from it

**Everywhere**

- **FR-014**: Nothing in feature 005's guarantees may be weakened — bytes outside
  the web root, the recorded type, `nosniff`, no execution
- **FR-015**: The site's own templates MUST request the derived sizes

### Key Entities

None. A derived image is a file on disk that can be reproduced from an original
at any time, so it is a cache and not a record. Nothing is added to the database.

## Success Criteria *(mandatory)*

- **SC-001**: A reader revisiting a page downloads no image bytes at all
- **SC-002**: A listing sends a fraction of the bytes it sends today
- **SC-003**: No image is ever enlarged, stretched or cropped
- **SC-004**: A size nobody defined cannot be requested into existence
- **SC-005**: Deleting a file leaves nothing behind
- **SC-006**: Everything feature 005 proved about uploads is still proved
- **SC-007**: `composer qa` passes and the whole suite grows

## Assumptions

- **GD**, which is compiled in and supports every type this CMS accepts. Imagick
  is not installed and adding an extension to run a CMS is a heavier ask than
  using what PHP already has.
- **Three sizes**: a thumbnail for listings, a medium for article bodies, and a
  large for the lead of an article. Named rather than numeric, so that changing
  what "thumbnail" means is one edit rather than a search through templates.
- **Derived on first request**, not on upload. Resizing at upload time makes an
  editor wait for work that may never be needed, and adding a size later would
  require reprocessing everything.
- **A year of caching**, because a stored name is generated from random bytes and
  never reused. The address of changed bytes is a different address.
- **Format is preserved.** Converting to WebP is a real improvement and a
  separate decision, with its own compatibility question; this feature is about
  size and repetition.
