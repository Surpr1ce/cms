# Feature Specification: Media Uploads

**Feature Branch**: `005-media-uploads`

**Created**: 2026-08-17

**Status**: Draft

**Input**: User description: "Media uploads: receiving, validating, storing and serving files, with alternative text. Somebody signed in can upload a file, describe it, see what has been uploaded, and use it as a lead image. The file is validated by its content rather than its name, stored under a generated name, and served back. No taxonomy or account screens in this feature."

## Overview

Feature 001 catalogued files. Feature 004 lets somebody choose one as a lead
image. Neither puts any bytes anywhere, so an article with a lead image renders
without it — which the public site handles deliberately and which nobody would
call finished.

This feature closes that. It is short in scope and unusually exposed in nature:
it is the only place in the CMS where somebody hands the server a file, and file
upload is the classic way a content management system is turned into a web shell.

The constitution already names the rules, and has since before anything was
built:

> Uploaded files MUST be stored under generated filenames, validated by content
> rather than by extension, and served through a controller that applies
> authorisation.

Feature 001 built the first of those into `Media` — the stored name is generated
and has no setter. This feature has to do the other two, and to keep the first
true when real bytes arrive.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Uploading a file (Priority: P1)

Somebody signed in chooses a file, describes it for people who cannot see it, and
it becomes available to use.

**Why this priority**: nothing else in the feature exists without it.

**Acceptance Scenarios**:

1. **Given** an editor and an image, **When** they upload it with a description, **Then** it is catalogued and appears in the file list.
2. **Given** an uploaded file, **When** its record is inspected, **Then** the stored name is generated, the supplied name is kept only for display, and the type recorded is the one detected from the content.
3. **Given** an upload with no description, **When** it is submitted, **Then** it is refused and nothing is stored.
4. **Given** an upload of a type the CMS does not accept, **When** it is submitted, **Then** it is refused with an explanation and nothing is stored.
5. **Given** an upload larger than the limit, **When** it is submitted, **Then** it is refused and nothing is stored.
6. **Given** an upload without the expected one-time token, **When** it is submitted, **Then** it is refused.

---

### User Story 2 - What is uploaded cannot execute (Priority: P1)

A file put on this server cannot be made to run, whatever it is named, whatever
it claims to be, and whatever is inside it.

**Why this priority**: this is the requirement the whole feature is judged on. An
uploads directory that will execute a PHP file is a remote shell, reachable by
anybody who can sign in as an author — and authors are the least trusted people
with accounts.

**Independent Test**: submit a catalogue of hostile files and inspect what was
stored, where, and under what name; then request them back.

**Acceptance Scenarios**:

1. **Given** a file named `evil.php` containing PHP source, **When** it is uploaded, **Then** it is refused because its content is not an accepted type.
2. **Given** a PHP file renamed `evil.jpg`, **When** it is uploaded, **Then** it is refused, because the type is decided by the content and not the name.
3. **Given** an image with PHP source appended to it, **When** it is uploaded, **Then** the stored file is served with the detected image type and never interpreted.
4. **Given** a file named `../../public/index.php`, **When** it is uploaded, **Then** the supplied name reaches no filesystem path at all.
5. **Given** any stored file, **When** its location is inspected, **Then** it is outside the web root, so no web server configuration can serve it directly.
6. **Given** any stored file, **When** it is served, **Then** it is sent with the type recorded for it and with headers that stop a browser guessing a different one.
7. **Given** an SVG, **When** it is uploaded, **Then** it is refused, because an SVG is a document that can carry script.

---

### User Story 3 - Using and managing files (Priority: P2)

Uploaded files are listed, described, used as lead images, and removed.

**Acceptance Scenarios**:

1. **Given** files that have been uploaded, **When** the file list is opened, **Then** they are shown newest first with a preview, their supplied name, size and uploader.
2. **Given** an uploaded file, **When** its description is changed, **Then** the change is stored.
3. **Given** a described file, **When** an article is edited, **Then** it can be chosen as the lead image and appears on the article.
4. **Given** a file used as a lead image, **When** it is deleted, **Then** the article survives without a lead image and the bytes are removed from storage.
5. **Given** an author, **When** they open the file list, **Then** they are refused — files are an editorial concern.

---

### User Story 4 - Serving a file to a reader (Priority: P2)

A file used in published content is reachable by a reader; the bytes are served
through the application rather than by the web server.

**Acceptance Scenarios**:

1. **Given** a stored file, **When** its address is requested by anybody, **Then** the bytes are returned with the recorded type.
2. **Given** an address that matches no file, **When** it is requested, **Then** the response is not-found.
3. **Given** a stored file whose bytes have gone missing, **When** it is requested, **Then** the response is not-found rather than an error page.
4. **Given** any served file, **When** the response is inspected, **Then** it carries a header stopping the browser from guessing the type, and a disposition that does not invite execution.

---

### Edge Cases

- An upload that fails partway through must leave neither a catalogue row nor a
  file on disk.
- A catalogue row whose file is missing must not break the file list.
- Two uploads of identical content are two files. Deduplication is not attempted.
- A file deleted from the catalogue while an article still points at it must
  leave the article intact — the model already guarantees this and it must stay
  true once bytes are involved.
- An upload arriving with no file at all must be refused rather than storing an
  empty record.

## Requirements *(mandatory)*

### Functional Requirements

**Receiving**

- **FR-001**: Somebody with permission MUST be able to upload a file with a description.
- **FR-002**: An upload MUST be refused unless it carries a description.
- **FR-003**: An upload MUST be refused if its **detected** type is not one the CMS accepts.
- **FR-004**: The accepted types MUST be an allow-list, and MUST NOT include any type that can carry script, including SVG.
- **FR-005**: An upload MUST be refused above a size limit, and the limit MUST be stated to the person uploading.
- **FR-006**: An upload MUST require the expected one-time token.
- **FR-007**: A refused upload MUST store neither a catalogue row nor a file.

**Storing**

- **FR-008**: The stored name MUST be generated and MUST NOT derive from the supplied name.
- **FR-009**: The supplied name MUST be kept for display only and MUST NOT reach any filesystem path.
- **FR-010**: Files MUST be stored outside the web root.
- **FR-011**: The recorded type MUST be the detected one, never the one claimed by the upload.

**Serving**

- **FR-012**: Files MUST be served by the application, not directly by the web server.
- **FR-013**: A served file MUST carry the recorded type and a header preventing the browser from guessing a different one.
- **FR-014**: A served file MUST carry a disposition that does not invite the browser to treat it as a document to execute.
- **FR-015**: An unknown address, or a record whose bytes are missing, MUST produce not-found.

**Managing**

- **FR-016**: Uploaded files MUST be listed, newest first, with a preview and their details.
- **FR-017**: A description MUST be editable.
- **FR-018**: A file MUST be deletable, and deleting it MUST remove both the record and the bytes while leaving content intact.
- **FR-019**: Every file address MUST enforce its permission — managing files is editorial.

**Evidence**

- **FR-020**: Every hostile case in User Story 2 MUST have a test asserting on what was **stored**, not on what was displayed.
- **FR-021**: Every address MUST have a test for the anonymous case and the insufficient-permission case.
- **FR-022**: The absence of executable content in the storage directory MUST be provable by test.

### Key Entities

No new entities. `Media` already carries everything; this feature finally gives
its `filename` something to point at.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: No file in the hostile catalogue is stored in a form that could be executed.
- **SC-002**: 100% of stored files live outside the web root.
- **SC-003**: No supplied filename influences any path on disk, verified for path-traversal and null-byte attempts.
- **SC-004**: Every file address is refused to an anonymous visitor and to an author.
- **SC-005**: A lead image chosen in the article screen is visible to a reader on the published article.
- **SC-006**: The existing suite continues to pass unchanged.
- **SC-007**: The quality gate passes with no rule relaxed, no suppression added and no test skipped.

## Out of Scope

- Image resizing, thumbnails, cropping and format conversion.
- A media library browser inside the article editor beyond the existing picker.
- Uploading from a URL, drag and drop, or multiple files at once.
- Cloud or object storage. Files go on the local disk behind the application.
- Virus scanning.
- Deduplication of identical uploads.

## Assumptions

- **Accepted types are JPEG, PNG, GIF, WebP, AVIF and PDF**, matching the
  allow-list feature 001 already built into the stored-name generator. SVG is
  deliberately absent and that decision is already recorded.
- **The size limit is 8 MB**, which comfortably holds a photograph and is small
  enough that a refusal is cheap.
- **Files are stored under `var/uploads/`** — outside the web root, alongside the
  other things this application writes.
- **Serving is unauthenticated.** A file used in a published article must be
  readable by anybody, and the CMS has no notion of a private file. Recorded
  because the constitution says files are served "through a controller that
  applies authorisation", and the authorisation this controller applies is
  "anybody may read". Restricting files to the content that uses them is a
  reasonable later feature and is stated here rather than implied.
- **PDFs are served as attachments**, images inline. A PDF rendered inline is a
  document the browser executes a plugin for.

## Dependencies

- Feature 001: `Media`, its generated stored name, and `StoredFilenameGenerator`
  with the allow-list this feature reuses.
- Feature 003: the voters that decide who may manage files.
- Feature 004: the lead-image picker that becomes useful once files exist.
