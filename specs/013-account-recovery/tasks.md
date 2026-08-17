---

description: "Task list for feature 013 — account recovery"
---

# Tasks: Account Recovery

**Input**: Design documents from `/specs/013-account-recovery/`

**Written before the implementation.**

## The risk this feature actually carries

A password reset is a way to take over an account without knowing the password.
That is what it is *for*, which is why every detail of it is load-bearing.

Three failures, in the order they are easiest to commit:

**The oracle.** A reset form that says "we have sent you an email" for a real
address and "no account found" for another is a way to test any list of email
addresses against this installation. Feature 003 fought this on the sign-in form
and won; the same fight has to be won here, and it is easier to lose because the
honest-sounding message is the wrong one.

**The stored token.** A reset token in the database *is* a working link. If it is
stored as it appears in the link, then anybody who reads the database — a backup,
a log, an injection — can sign in as anybody. Storing a hash means a stolen
database yields nothing.

**The link that keeps working.** A link that survives its use, or its expiry, or
the next request for a new one, is a credential lying around in an inbox forever.

So: the response is compared byte for byte between a real address and a fictional
one; the test reads the *database* and asserts the token in it does not appear in
the email; and each of used, expired and superseded is a separate test.

---

## Phase 1: Setup

- [x] T001 `src/Entity/PasswordResetRequest.php` — an account, a hashed token, a creation time, a used marker
- [x] T002 Migration, generated with `doctrine:migrations:diff`
- [x] T003 `src/Factory/PasswordResetRequestFactory.php`
- [x] T004 ~~`src/Exception/PasswordResetIsNotUsable.php`~~ — **not written, and the plan was wrong to ask for it.** Nothing throws: an invalid link, an expired one, a used one and a superseded one all answer `null` from `findUsable()` and all get the same page, so there is no rule being *refused* to anybody. A class per refused rule is the convention; a class per "we found nothing" would be a class nobody catches

## Phase 2: US2 — telling a stranger nothing

Written before US1 deliberately: the invisibility rule is the one that is
expensive to retrofit, and building the happy path first invites a message that
has to be walked back.

- [x] T005 [P] [US2] Write `tests/Functional/Security/PasswordResetTest.php` **first** — the identical-response assertion, no message sent for an unknown address, the limit
- [x] T006 [US2] `login_throttling`-style limiting on the request form, per address and per client

## Phase 3: US1 — getting back in

- [x] T007 [P] [US1] Write the reset cases — a link arrives, it works once, an expired one is refused, a superseded one is refused, an altered one is refused
- [x] T008 [US1] `src/Service/Account/PasswordResetService.php` — request, verify, complete; hashing the token on the way in
- [x] T009 [US1] `src/Controller/PasswordResetController.php` — the request form and the set-a-password form
- [x] T010 [US1] The email, as a Twig template, naming what to do and how long the link lasts
- [x] T011 [US1] Templates for both forms, and a link from the sign-in page

## Phase 4: US3 — changing a password on purpose

- [x] T012 [P] [US3] Write the change-password cases — it works, the current password is required, a wrong one stores nothing
- [x] T013 [US3] An account screen with the form, behind the firewall

## Phase 5: Polish

- [x] T014 [P] Update `docs/status.md`, including what this feature deliberately does **not** do — end other sessions
- [x] T015 Run `composer qa`
- [x] T016 Verify by hand on the dev server — a real address and a fictional one produce byte-for-byte identical pages once the development toolbar is stripped out, the stored row holds a hash and not a token, and a made-up link answers 404
- [ ] T017 `symfony-reviewer` and `security-auditor` passes — expected to remain open

## Notes

- The token is hashed with SHA-256 rather than the password hasher. It is a
  128-bit random value with nothing to guess, and a deliberately slow hash on an
  unauthenticated lookup is a denial-of-service surface rather than a defence.
- Registration is still not part of this CMS. Accounts are created by an
  administrator, and a public sign-up form is a way to fill a database with
  strangers.
- A password change does not end other sessions, because there is no session
  registry to end them with. That belongs in `docs/status.md` as a limitation
  rather than being quietly implied — and the account screen says so to the
  person using it, which is where it actually matters.

## What the tests found

- **The altered-link test could pass by accident.** It built its forgeries by
  setting a character to a fixed value, which collides with the real token
  whenever that character was already there — a test that opened the genuine link
  and called it a refusal. The forgeries are now derived so they cannot equal the
  original.
- **The rate limit needed the same treatment as feature 008's.** The counters
  live in memory in the test environment, and a browser rebuilds the kernel
  between requests, so the limit was never reached until the test turned that
  rebuild off. The same comment appears in both test classes, pointing at the
  same explanation in `config/packages/cache.yaml`.
- **`AbstractController::render()` has no `status` parameter.** Passing one by
  name is a fatal error rather than a 404, which is how a refusal became a 500 —
  and why the test asserting the *status* caught it where a test asserting the
  page's words would not have.
