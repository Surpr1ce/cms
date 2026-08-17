---

description: "Task list for feature 005 — media uploads"
---

# Tasks: Media Uploads

**Input**: Design documents from `/specs/005-media-uploads/`

**Written before the implementation.** Feature 004's list was written afterwards
and said so; this one is back in order, and the hostile-file catalogue is the
first thing built rather than the last.

---

## Phase 1: Setup

- [x] T001 Create `var/uploads/` with a `.gitignore` that keeps the directory and ignores its contents. Outside the web root, which is the whole point — nothing a web server can be configured to serve
- [x] T002 Add `app.upload_max_bytes` to `config/services.yaml` (8 MB) so the limit is stated in one place and can be shown to the person uploading

## Phase 2: The storage boundary, first

- [x] T003 [P] Write `tests/Unit/Service/Media/UploadedFileValidatorTest.php` **first** — the hostile catalogue: PHP named `.php`, PHP renamed `.jpg`, an image with PHP appended, an SVG, an empty file, an oversized file, a path-traversal name, a null-byte name
- [x] T004 Create `src/Service/Media/UploadedFileValidator.php` — decides by **detected** type against the allow-list `StoredFilenameGenerator` already holds, and by size. The supplied name is not a parameter it can consider
- [x] T005 Create `src/Service/Media/MediaStorage.php` — writes bytes under the generated name into `var/uploads/`, reads them back, removes them. The only class that knows a path exists
- [~] T006 [P] Write `tests/Integration/Service/Media/MediaStorageTest.php` — **not written as a separate file.** Every claim it would have made is asserted in `UploadsCannotExecuteTest` through the real upload path: bytes land under the generated name, the supplied name reaches no path, and `MediaServingTest` covers a record whose file is gone. A second test exercising the same behaviour one layer down would have added coverage of nothing

## Phase 3: US1 — uploading (P1)

- [~] T007 [P] Write `tests/Functional/Admin/MediaUploadTest.php` — **not written under that name.** The happy path and the refusals live in `UploadsCannotExecuteTest`, beside the hostile cases they are the counterpart to; the permission and token cases live in `MediaServingTest`. Splitting them by filename rather than by subject would have separated "this is accepted" from "this is not"
- [x] T008 Create `src/Service/Media/MediaUploader.php` — validate, generate the name, write the bytes, then catalogue. In that order, and nothing partial survives a refusal
- [~] T009 [P] `src/Form/MediaUploadType.php` and the command object it fills — **not built.** Two fields, one of which is a file, and the validation that matters is done on the bytes by `UploadedFileValidator`. A form type here would have been a layer whose only job is passing values to the thing that actually decides
- [x] T010 `src/Controller/Admin/MediaController.php` — list, upload, edit description, delete
- [~] T011 [P] `templates/admin/media/{index,form}.html.twig` — **only `index`.** The upload form and the description fields sit on the list, because uploading a file and seeing what is already there are the same task

## Phase 4: US2 — nothing stored can execute (P1)

- [x] T012 Write `tests/Functional/Admin/UploadsCannotExecuteTest.php` — every hostile case asserted on what reached disk and the catalogue, never on what a screen displayed
- [x] T013 Prove the storage directory is outside `public/`, by test rather than by inspection
- [x] T014 Prove a supplied name never influences a path — traversal and null-byte attempts land under a generated name in the expected directory

## Phase 5: US4 — serving (P2)

- [x] T015 [P] Write `tests/Functional/MediaServingTest.php` — a stored file is served to anybody with the recorded type; an unknown name and a missing file are both not-found; the anti-sniffing header is present
- [x] T016 Create `src/Controller/MediaController.php` — serves from storage with `X-Content-Type-Options: nosniff`, images inline and PDFs as attachments
- [x] T017 Point the public and admin templates at the serving route instead of the `uploads/` asset path feature 002 guessed at

## Phase 6: US3 — managing (P2)

- [x] T018 Deleting a file removes the bytes as well as the record, and leaves content intact — extend `MediaDeleter` rather than duplicating its rules
- [x] T019 Add the file list, description editing, and the permission tests for every address

## Phase 7: Polish

- [x] T020 [P] Give the fixtures real image bytes, so a fresh installation shows lead images rather than gaps
- [x] T021 [P] Update `docs/status.md` and `docs/setup.md`
- [x] T022 Write `docs/adr/0011-serve-uploads-through-the-application.md` — why files live outside the web root and pass through a controller, and what that costs
- [x] T023 Run `composer qa`
- [x] T024 Walk `quickstart.md` by hand
- [ ] T025 `symfony-reviewer` pass — expected to remain open

## Marked `[~]` rather than `[x]`

Four tasks were not done as written, and are marked so rather than ticked. Each
carries its reason above. Three are the same judgement — a layer the plan
anticipated turned out to have nothing to do — and one is a test file whose
assertions ended up in a better place.

A tick against work that was skipped is worse than an honest mark, because the
list stops being readable as a record of what happened.

## Two things worth recording

**The validator turned out stricter than the test expected.** A polyglot — a real
PNG with PHP source appended — was expected to be accepted as an image, on the
reasoning that everything downstream would keep it harmless. The detector does
not recognise the result as any type at all, so it never reaches storage. The
test now asserts what actually happens, rather than claiming a weaker guarantee
than the code provides.

**`nosniff` caught the fixtures.** The development fixtures wrote PNG bytes for
records catalogued as JPEG. Served with the recorded type and
`X-Content-Type-Options: nosniff`, a browser refuses to render that rather than
working it out — which is exactly what the header is for, and the first real
mismatch it found was our own.

## Notes

- The validator decides by detected type. The supplied name is not one of its
  parameters, so it cannot be consulted even by accident — the same reasoning
  that made `StoredFilenameGenerator` take only a MIME type in feature 001.
- Every hostile test asserts on what reached disk and the catalogue. A test that
  checked an error message would pass with the file written anyway.
