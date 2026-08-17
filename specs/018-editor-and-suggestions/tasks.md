# Feature 018 — tasks

## Phase 1: Setup

- [x] T001 Read `SiteSearch`, `SearchQuery` and `ContentSanitiser` before writing
      anything, so the suggestion endpoint and the toolbar are both built from
      what already exists rather than beside it

## Phase 2: Foundational

- [x] T002 Add a `search_suggestions` rate limiter in
      `config/packages/rate_limiter.yaml`, with a test-environment allowance
- [x] T003 Add `SiteSearch::suggest()` in `src/Search/SiteSearch.php`, going
      through the same published-only SQL as `search()`

## Phase 3: US1 — suggestions while typing

- [x] T004 [US1] Add `SearchSuggestionController` in
      `src/Controller/SearchSuggestionController.php`, answering JSON at
      `/search/suggestions`
- [x] T005 [US1] Add `assets/suggestions.js`: debounce, fetch, listbox, keyboard,
      and the ARIA a combobox needs
- [x] T006 [US1] Import it from `assets/app.js`
- [x] T007 [US1] Mark up `templates/components/_search_form.html.twig` as a
      combobox that degrades to a plain form
- [x] T008 [US1] Test in `tests/Functional/SearchSuggestionsTest.php`: shape of
      the JSON, drafts absent, minimum length, the limit, and the rate limit

## Phase 4: US2 — a visual editor for body markup

- [x] T009 [US2] Add `assets/editor.js`: toolbar, `contenteditable` surface,
      synchronising into the text area, and the markup toggle
- [x] T010 [US2] Import it from `assets/app.js`
- [x] T011 [US2] Mark the body field in `src/Form/ArticleType.php` and
      `src/Form/PageType.php` so the editor knows which field to enhance
- [x] T012 [US2] Style the editing surface with the same `.prose` rules the
      published article uses, in `assets/styles/app.css`
- [x] T013 [US2] Test in `tests/Unit/Service/Content/ContentSanitiserTest.php`
      that every element the toolbar can produce survives sanitising untouched —
      the assertion that stops the toolbar and the allow-list drifting apart
- [x] T014 [US2] Test in `tests/Functional/Admin/MarkupEditorTest.php` that the
      field is still a text area in the markup, still submits, and still saves

## Phase 5: US3 — the search box

- [x] T015 [US3] Rewrite `templates/components/_search_form.html.twig` as one
      control with a size option
- [x] T016 [US3] Take the spacing out of the component and give it to the two
      callers, `templates/public/layout.html.twig` and
      `templates/public/search.html.twig`

## Phase 6: Polish

- [x] T017 ADR: a visual editor that carries no authority, in
      `docs/adr/0014-a-visual-editor-that-carries-no-authority.md`
- [x] T018 `composer qa` green
- [ ] T019 Run the `symfony-reviewer` and `security-auditor` passes, and act on
      what they find
- [x] T020 Update `docs/status.md`
