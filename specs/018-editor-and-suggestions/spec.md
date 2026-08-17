# Feature 018 — writing and finding

Three things somebody using the running site asked for, after opening it and
using it. None of them is a rule the tests could have been missing: each is a
thing the software does not do.

## Why

**Searching offers nothing until you have finished typing and pressed a button.**
Every site a reader has used for a decade suggests as they type. Without it a
search is a guess submitted blind, and the guess that returns nothing gives no
hint what would have worked.

**The body of an article is a text area full of markup.** That was a deliberate
decision and its reasoning still holds — sanitising must not depend on an editor
behaving — but it makes writing an article a job for somebody who knows HTML,
which the person publishing a page about opening hours is not.

**The search box does not look like one control.** An input and a button side by
side with a gap between them, at different heights, with the button a solid black
rectangle. It reads as two things that happen to be adjacent.

## Scope

### US1 — suggestions while typing (P1)

A reader types in any search box on the site and sees, without submitting, a
short list of published articles and pages whose titles or text match. Choosing
one goes straight to it. Choosing nothing and pressing Enter searches as before.

**Acceptance**

1. Typing at least `SearchQuery::MINIMUM_LENGTH` characters shows at most six
   suggestions, each naming what it is (article or page).
2. Suggestions are drawn from published content only. A word that appears solely
   in a draft produces the same empty list as a word that appears nowhere.
3. The keyboard reaches everything: down and up move through the list, Enter
   opens the highlighted one, Escape closes the list and leaves what was typed.
4. With JavaScript unavailable, the form submits and the search page answers
   exactly as it does today. Nothing is lost, only unoffered.
5. A screen reader is told the list exists, how many are in it, and which one is
   current.
6. The suggestion endpoint is rate-limited per client. It is unauthenticated and
   it fires while somebody types, which makes it the cheapest route on the site
   to ask for repeatedly.

### US2 — a visual editor for body markup (P1)

An editor writing an article or a page can format text without typing tags, and
can still see and edit the markup directly when they want to.

**Acceptance**

1. The body field of the article and page screens offers a toolbar covering what
   is actually permitted: headings, bold, italic, lists, quote, code, link,
   and clearing formatting.
2. A control switches between the visual view and the markup view. What is
   stored is identical either way, because both write to the same field.
3. With JavaScript unavailable the field is the text area it is today, and saving
   works. The editor is an enhancement over a working control, never a
   replacement for one.
4. **What reaches the database still goes through `ContentSanitiser` unchanged.**
   The editor is a convenience in a browser and carries no authority. An article
   saved through it is indistinguishable from one typed as markup.
5. The summary field does not get an editor. Summaries are stored as text with
   tags stripped, so offering formatting there would promise something the
   storage refuses.

### US3 — the search box looks like one control (P2)

**Acceptance**

1. The input and the button read as a single control: one outline, one height,
   one corner radius, and a focus ring around the whole of it rather than half.
2. It sits on the header line without pushing it out of alignment, and it is
   usable at the widths the header stacks at.
3. The larger box on the search page and the small one in the header are the same
   component with a size, not two components that have drifted apart.

## Out of scope

- Suggesting corrections or "did you mean". That needs trigram similarity and a
  decision about how wrong a query may be before it is guessed at.
- Highlighting the matched words in a result. Recorded already in `status.md`.
- Images inserted from the media library through the editor. The toolbar covers
  text; a picker is its own feature with its own screen.
- Tables in the visual editor. The sanitiser allows them and the markup view can
  write them; a table-editing interface is a feature, not a button.

## Success criteria

- **SC-001** A reader reaches an article from the header by typing three
  characters and pressing down then Enter, without loading a search page.
- **SC-002** A word that only a draft contains returns an empty suggestion list,
  byte for byte the same as a word nothing contains.
- **SC-003** An article written entirely through the toolbar stores markup that
  `ContentSanitiser` leaves untouched — the editor cannot produce anything the
  allow-list would strip.
- **SC-004** Every screen still works with JavaScript disabled: search submits,
  bodies save.
