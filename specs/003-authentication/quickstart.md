# Phase 1 Quickstart: Authentication and Authorisation

**Feature**: `003-authentication` | **Date**: 2026-08-17

## Give yourself access

On a fresh installation there is no interface that can create an account, so
there is a console command:

```bash
php bin/console app:create-administrator you@example.com "a-long-enough-password" "Your Name"
```

It creates the account, or promotes and re-passwords an existing one — which is
what you want when you have forgotten the password. The password must be at
least 12 characters. It is never echoed, not even back to you.

With the development fixtures loaded there are four accounts already, all using
the password written openly in `UserFactory::DEVELOPMENT_PASSWORD`:

| Account | Role |
| --- | --- |
| `admin@example.com` | administrator |
| `editor@example.com` | editor |
| two generated addresses | author |

That the password is in the repository is deliberate: an account whose password
anybody can read is an account nobody can mistake for a real one.

## Try it

```bash
symfony serve        # or: php -S 127.0.0.1:8000 -t public
```

1. `/admin` while signed out → redirected to `/login`.
2. Sign in as `admin@example.com` → landed on `/admin`.
3. Sign out → `/admin` sends you back to the form.
4. Sign in with a wrong password → refused, and the message is the same one an
   unknown address gets. Compare them; if they ever differ, the form has become
   a way to discover which addresses hold accounts.
5. The public site is unchanged throughout.

## The permission matrix

The part of this feature that matters is not the form — it is what a signed-in
person may do to a particular thing. It is not reachable from a screen yet;
feature 004 builds the interfaces that ask these questions.

| | own draft | own published | another's draft | another's published |
| --- | --- | --- | --- | --- |
| **author** edit | yes | no | no | no |
| **author** delete | yes | no | no | no |
| **author** publish | no | no | no | no |
| **editor** edit | yes | yes | yes | yes |
| **editor** publish | yes | yes | yes | yes |
| **admin** edit | yes | yes | yes | yes |

Pages have no author, so an author has no claim on any of them at all. Managing
accounts is an administrator's alone — an editor is refused — and an
administrator may not delete their own account.

Every cell above has a test in `tests/Unit/Security/`. The refusals are the ones
that matter: a voter granting everything passes every happy-path test ever
written.

## Run the checks

```bash
composer qa                                     # 492 tests, 973 assertions
vendor/bin/phpunit --testsuite unit             # the permission matrix, no database
vendor/bin/phpunit --filter LoginTest
vendor/bin/phpunit --filter AdministrationIsClosedTest
```

## What this feature does not give you

- **No screens.** `/admin` is a placeholder saying so.
- **No rate limiting on the sign-in form.** The door locks; nothing counts how
  many times somebody tries the handle. Recorded in `docs/status.md` rather than
  left to be assumed from "the door is locked".
- No registration, password reset, password change or email of any kind.
- No "remember me", no two-factor, no session expiry policy.
- No audit log of who did what.
