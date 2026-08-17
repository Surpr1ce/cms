# 11. Store uploads outside the web root and serve them through the application

- **Status**: Accepted
- **Date**: 2026-08-17
- **Feature**: [specs/005-media-uploads](../../specs/005-media-uploads/spec.md)

## Context

This is the only place in the CMS where somebody hands the server a file, and
file upload is the classic way a content management system becomes a web shell.
The attack is short: upload `shell.php`, request it, and the web server runs it —
with the application's permissions, on the application's machine.

Anybody who can sign in can reach the upload screen. Authors are the least
trusted people with accounts, and until this feature they could do nothing worse
than write a draft.

The constitution has named the rules since before anything was built:

> Uploaded files MUST be stored under generated filenames, validated by content
> rather than by extension, and served through a controller that applies
> authorisation.

Feature 001 built the first into `Media`. This feature owes the other two, and
has to keep the first true now that real bytes exist.

## Decision

**Files are stored in `var/uploads/`, outside the web root**, and **served by a
controller**, never by the web server.

The controller sends the type recorded at upload — detected from the content —
with `X-Content-Type-Options: nosniff`, images inline and everything else as an
attachment.

Three classes each know one thing and no more, and none of them takes the
supplied filename as a parameter:

- `UploadedFileValidator` reads the bytes and the size. It cannot consult a name
  because it is not given one.
- `StoredFilenameGenerator` (feature 001) turns a detected type into a random
  name plus a known extension.
- `MediaStorage` builds a path from a generated name and nothing else.

## Alternatives considered

**Store in `public/uploads/` and let the web server serve them.** The
conventional answer, and much faster: no PHP process per image, and the server
does what servers are good at. Rejected because it makes safety depend on server
configuration that is not in this repository. `.htaccess` files are ignored under
nginx; an `AddType` inherited from somebody else's template can make `.jpg`
executable; a container image rebuilt from a different base can quietly change
the rules. With the bytes outside the web root there is no configuration that can
serve them, correct or otherwise, so the guarantee is a property of where the
files are rather than of what somebody remembered to write.

**Store outside the web root, but drop a deny-everything rule in the directory
as well.** Belt and braces, and rejected only because the braces are doing
nothing: nothing can reach a directory a server was never pointed at.

**Refuse the upload if the extension is dangerous.** A denylist, and the wrong
shape: it has to anticipate every executable extension on every server anybody
will ever deploy to. The allow-list on *detected type* answers the question
directly — is this a JPEG — instead of guessing at all the things it might be.

**Trust the browser's declared type.** Rejected in one line: a browser reports
what the file was called.

## Consequences

- **A PHP process serves every image.** That is the price, and it is real: a busy
  site would want a caching layer or a signed-URL scheme in front of this. Worth
  measuring before optimising, and out of scope here.
- Nothing in the uploads directory can be reached except as a response this
  application composed. A file that somehow got there could still not be run.
- The `nosniff` header is what makes the recorded type mean anything. Without it
  a browser may decide for itself what the bytes are — and it caught the first
  real mismatch immediately: the development fixtures wrote PNG bytes for records
  catalogued as JPEG, and the images did not render. The fixtures were wrong and
  the header said so.
- **Serving applies no restriction beyond "anybody may read".** A file used in a
  published article has to be public, and the CMS has no notion of a private
  file. The constitution says files are served through a controller that applies
  authorisation; the authorisation this one applies is that one. Restricting a
  file to the content that uses it is a reasonable later feature, and is stated
  here rather than left to be inferred from an absent check.
- Deleting a file removes the row first and the bytes second. If the second step
  fails, an orphaned file remains — untidy, and harmless. The other order would
  leave bytes nobody can find or delete through the application.
