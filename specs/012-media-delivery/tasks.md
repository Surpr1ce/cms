---

description: "Task list for feature 012 — media delivery"
---

# Tasks: Media Delivery

**Input**: Design documents from `/specs/012-media-delivery/`

**Written before the implementation.**

## The risk this feature actually carries

Feature 005 spent itself on one property: nothing in the uploads directory can be
reached as anything but a response this application composed. This feature adds
the first files the *application itself* writes there, and it adds them in
response to an anonymous request.

Every way that goes wrong is a way of relaxing something for a thumbnail. A
derived image written under `public/` because it is "only a cache". A size taken
from the address because an enum felt like ceremony — and a stranger able to ask
one server for ten thousand images at ten thousand sizes. A derived response
missing `nosniff` because the header was set in the original's branch and this is
a different branch.

So the derived route is tested against the *same* assertions as the original
one, and the sizes come from an enum the route is built from.

The second risk is memory. A file well within the eight-megabyte upload limit can
decode to hundreds of megabytes of pixels — the byte limit says nothing about the
pixel count. A resizer without a pixel budget is a way to take the site down with
one upload.

---

## Phase 1: Setup

- [x] T001 `src/Service/Media/ImageSize.php` — a fixed set of named sizes, so a template asks for `thumbnail` rather than a number and a reader cannot invent one
- [x] T002 `src/Exception/ImageCannotBeResized.php`

## Phase 2: US1 — caching

- [x] T003 [P] [US1] Write the caching assertions in `tests/Functional/MediaDeliveryTest.php` **first** — the validators, the instructions, and a second request that receives no bytes
- [x] T004 [US1] Set the validators and a long lifetime in `MediaController`, justified by stored names never being reused
- [x] T005 [US1] Answer a request holding a current validator with 304

## Phase 3: US2 — derived sizes

- [x] T006 [P] [US2] Write the size assertions **first** — within the box, proportions kept, nothing enlarged, an invented size refused, a document refused
- [x] T007 [US2] `src/Service/Media/ImageResizer.php` — GD, no cropping, no enlargement, a pixel budget
- [x] T008 [US2] `src/Service/Media/DerivedImages.php` — made once, kept, written through a temporary name and renamed
- [x] T009 [US2] A second route on `MediaController`, sharing every header with the first
- [x] T010 [US2] Delete derived images when the original goes (FR-013)

## Phase 4: US3 — the site uses them

- [x] T011 [US3] Article and page lead images at `large`; the administration file list at `thumbnail`; the social preview at `large`
- [x] T012 [US3] Update the tests that asserted the old addresses

## Phase 5: Polish

- [x] T013 [P] Update `docs/status.md`
- [x] T014 Run `composer qa`
- [x] T015 Verify by hand — **partly**. The routes were checked against the running server and an invented size answers 404; the size and caching behaviour could not be checked there because the development catalogue holds records whose bytes are not on disk, which predates this feature. Both are covered by `MediaDeliveryTest` against real generated images
- [ ] T016 `symfony-reviewer` pass — expected to remain open

## What this feature found in earlier ones

- **Two inline event handlers had been dead since feature 008.**
  `onsubmit="return confirm(...)"` on the delete forms and
  `onerror="this.style.display='none'"` on lead images are inline script, which
  that feature's content security policy forbids — so the delete confirmations
  had silently stopped asking, and a missing image had been showing a broken-image
  icon instead of hiding.

  Nothing caught it because neither is something a functional test can see: the
  markup was present and correct, and only a browser would have refused to run
  it. They are now `data-confirm` and `data-hide-on-error`, handled by
  `assets/behaviours.js` — ordinary module code from this origin, which the
  policy allows without an exception.

  Worth stating plainly: this is the second time feature 008 broke something
  quietly. A policy that forbids inline script breaks *every* inline handler on a
  site, and nothing enumerated them at the time.

## Notes

- A derived image is a cache and not a record: reproducible from the original at
  any moment, so nothing about it reaches the database. Deleting the whole
  directory costs processor time and nothing else.
- The route requirement is a literal string because a route attribute cannot call
  a method. `MediaDeliveryTest::testTheRouteAcceptsExactlyTheSizesThatExist`
  compares it against `ImageSize::routePattern()`, so adding a case without
  touching the route fails a test rather than producing a size nobody can reach.
- Format is preserved rather than converted to WebP. That is a real improvement
  with its own compatibility question, and it belongs to its own decision.
