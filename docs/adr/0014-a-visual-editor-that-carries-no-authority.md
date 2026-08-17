# 14. A visual editor that carries no authority

- **Status**: Accepted
- **Date**: 2026-08-17
- **Feature**: 018 — writing and finding

## Context

`status.md` has said since feature 004 that a rich-text editor is **not started,
deliberately**, with the reason recorded beside it:

> The body is a text area containing markup, so sanitising does not depend on an
> editor behaving.

That reasoning is sound and it is the reason [ADR 10](0010-sanitise-markup-on-the-way-in.md)
works. `ContentSanitiser` is the only thing standing between what somebody typed
and what a reader's browser executes, and it is trustworthy precisely because it
does not care where the string came from.

It is also true that writing an article means typing `<p>` and `</p>`, and that
the person publishing a page about opening hours does not know HTML. Asked to use
the running site, that is the first thing somebody notices.

The two are usually presented as a trade. They are not, if the editor is built to
have no power.

## Decision

**A hand-written visual editor, attached to the text area that was already
there, which writes into it and nothing else.**

Concretely:

- `assets/editor.js` finds `textarea[data-markup-editor]`, builds a toolbar and a
  `contenteditable` surface beside it, and copies the surface's `innerHTML` into
  the text area on every keystroke and before every submit.
- The request that reaches the server is the same request it has always been: a
  form POST with a string in `article[content]`. `ArticleEditor` sanitises it
  exactly as before. **Nothing on the server knows or can know whether the editor
  was used.**
- A "Markup" control swaps the surface for the text area, so somebody who does
  know HTML can still write it, and anybody can see what is actually being saved.
- With no JavaScript, the field is the text area it has always been.

**The toolbar offers exactly what the allow-list permits.** The first version of
this ADR claimed a test enforced that; a review pointed out that it did not — the
test was a hand-written list of HTML strings with no connection to the toolbar,
so a new button left the suite green. The claim was a convention describing
itself as a check.

`ToolbarMatchesTheAllowListTest` is the check. It reads `assets/editor.js`,
extracts the block element behind every command's `value`, and asserts each one
survives sanitising unchanged; and it pins the *set* of commands by enumeration,
because `bold` and `insertUnorderedList` name no element to extract. Adding any
button at all fails that second assertion until somebody lists it deliberately —
which is the moment to ask what markup it writes.

**The Link button normalises what is typed before applying it.** `createLink`
will happily accept `javascript:…`, and the sanitiser will silently drop the
`href` at save time, which is precisely the "formatting that vanishes" this
design exists to prevent. `normaliseAddress()` refuses a scheme the sanitiser
will not keep and says so, turns a bare domain into `https://`, and passes a
site-relative path through. That last one required a change to the sanitiser: it
named three schemes and an address with no scheme matched none of them, so
`<a href="/about-us">` had always been stripped — a bug nobody met while the body
was hand-written markup and the first internal link anybody makes walks into.

**Hand-written rather than a package.** Trix, TinyMCE, CKEditor and the rest each
bring a build step this project does not have, and most inject a stylesheet at
runtime — which the content security policy set in feature 008 forbids, having
just been tightened to remove `unsafe-inline` for exactly this class of reason.
Beyond that, each has a vocabulary of its own that would have to be reconciled
with what the server will actually store, which is a second allow-list in a
second language, drifting.

**Pasting inserts text, not markup.** A paste from a word processor carries a
document's worth of markup that the allow-list mostly strips. Keeping it would
show an editor formatting that silently vanishes when they save; cleaning it in
the browser would mean writing the allow-list twice. Text is the honest option —
what you see after pasting is what will be stored — and the toolbar is then used
to format it.

## Consequences

**The recorded reasoning still holds, unchanged.** Sanitising still does not
depend on an editor behaving, because the editor is not on the path that matters.
This ADR does not supersede the decision in ADR 10; it is only possible because
of it.

**`document.execCommand` is deprecated.** There is no standard replacement — the
Editing API has been abandoned — and every no-build editor uses it. The
commands used here are the oldest and most widely implemented ones, and
`styleWithCSS` is set to `false` so they produce `<b>` rather than
`<span style>`, which the allow-list would strip back to plain text. If a browser
ever drops it, the toolbar stops working and the markup view still saves
articles: the failure is a lost convenience, not lost work.

**A browser can put anything in that field, and always could.** The editor makes
no difference to that. It is worth stating plainly because the instinct on
reading "visual editor" is to ask what it lets through, and the answer is nothing
it did not let through before there was one. A security audit put eighteen
payloads through the write path — `javascript:` in every encoding it could think
of, `<svg onload>`, the mXSS `<math><mtext><table><mglyph><style>` shape — and
none of them survived.

**What did change is the read path, and this ADR argues only the write one.**
Before, the body field was a `<textarea>`: Twig-escaped, inert, never parsed. It
is now assigned to `innerHTML` on every edit screen. The string comes out of the
database, and [ADR 10](0010-sanitise-markup-on-the-way-in.md) records that
sanitising happens on the way *in* — so content stored under an older allow-list,
or written by some future import that bypasses `ArticleEditor`, is parsed in an
administrator's browser rather than displayed as text.

That is a widened blast radius rather than a hole. `innerHTML` does not execute
`<script>`, and the policy in `SecurityHeadersSubscriber` carries no
`unsafe-inline`, so an event-handler attribute does not run either — two
independent controls, both of which would have to fail. It is recorded here
because a reader of "carries no authority" will reasonably assume nothing flowed
inwards either, and something did. Sanitising the surface in the browser would
mean writing the allow-list twice, which is the thing this decision refused.

**The summary field does not get an editor**, because summaries are stored with
their tags stripped. Offering formatting there would promise something the
storage refuses.

## Alternatives considered

**Markdown instead.** A smaller surface, and genuinely tempting. Rejected because
the body column already holds HTML for every article on the site, so adopting it
means either a migration that converts existing content — lossily, since the
allow-list is wider than Markdown — or two formats in one column distinguished by
nothing. The second is the kind of ambiguity that is discovered years later.

**A package with the stylesheet extracted and served ourselves.** Possible, and
it removes the CSP problem. Rejected on the second objection rather than the
first: the vocabulary mismatch does not go away, and a dependency that has to be
patched to be used is a dependency that will not be updated.

**Leaving it as a text area.** What this project did for seventeen features, and
defensible. Rejected because "the person publishing the opening hours has to know
HTML" is a real limitation of the software rather than a considered constraint,
and because it turned out to cost nothing to fix.
