# Nova Cohesion Engine — QA Edge Cases

Test on **wp.pressgo.app** (drafts unless a case says otherwise). For each case capture:
section count before/after, a screenshot, and whether **"undo"** restores. State lives in
`_pressgo_ff_sections` + `_pressgo_cohesion_undo` post meta. "Make it flow" runs a ~30s vision
pass; auto-tidy fires only on **drafts**.

## A. "Make it flow" (full reorganize + vision)
- A1 Tiny page (1, then 2 sections) -> politely refuses, no change, no crash.
- A2 Already-tidy page -> near no-op, vision approves, count unchanged.
- A3 6 sections all dark -> alternates dark/light, CTA->accent last, all legible.
- A4 CTA stranded mid-page -> moves to end, turns accent.
- A5 Two testimonials + two CTAs -> vision merges <=2 duplicates, never hero, keeps >=3, undo restores all.
- A6 Image/gradient hero + image sections -> never recolored (lock_bg), hero untouched.
- A7 Run twice -> 2nd is near no-op, no thrash, no false merges.
- A8 Interrupt mid-flow with another message -> no corruption / partial write.

## B. Auto-tidy (C4)
- B1 Draft, add until 3 same-shade in a row -> "I also tidied..." note, rhythm fixed.
- B2 Add a section that keeps alternation -> NO auto-tidy.
- B3 Publish page, then edit/add -> auto-tidy NEVER fires (ad-lander guard); manual still works.
- B4 Debounce: trigger auto-tidy, send non-build message -> does not re-run.
- B5 3 sections + violation -> needs >=4, should not fire.
- B6 Deliberate order, minor non-violation -> should NOT yank layout around.

## C. Delete a section
- C1 "remove the testimonials section" -> removes, undo restores.
- C2 "remove the last/top section" -> removes last / first-non-hero.
- C3 "remove the hero" -> refuses.
- C4 "remove that thing" -> asks to be specific, deletes nothing.
- C5 "remove the pricing section" when none -> says couldn't find, no deletion.
- C6 "remove the old hero and add a new one" -> add-guard: should NOT wrongly delete.
- C7 Keep removing -> stops at hero-only, no empty page.

## D. Undo / snapshot
- D1 "undo" on fresh page -> "nothing to undo," no crash.
- D2 Undo after reorganize / delete / auto-tidy -> restores exact prior data.
- D3 Double undo -> 2nd says nothing to undo (single-step).
- D4 Undo then keep building -> records stay consistent with _elementor_data.

## E. Contrast / recolor (safe_bg)
- E1 Set brand dark_bg to orange/light, then make it flow -> "dark" falls back to #111418, NO orange blocks.
- E2 Gold heading / gold stars -> vivid hues preserved, only neutral text flips.
- E3 Force shade flip -> every heading/body/button/icon/form stays legible.
- E4 CTA on accent bg -> button + text contrast (no invisible button).

## F. Section storage / legacy
- F1 Legacy page (no _pressgo_ff_sections) -> backfill = reorder-only, no recolor, nothing wrecked.
- F2 Edit in Elementor (move/delete section, strip pg-key class), then make it flow -> no crash, no dup/loss.
- F3 Duplicate the page -> copy doesn't inherit interview/brief; ff_sections sane.
- F4 Hand-authored non-Nova section mixed in -> preserved, not dropped.

## G. Discovery / build flow (feeds the engine)
- G1 Vague vs fully-specified first message -> chips appear; stated goal/vibe skip their step.
- G2 Free-text instead of chips -> parsed into the right stage.
- G3 Lock/recolor/refont, then 2nd page -> collapses to ~2 taps, on-brand.
- G4 "add a testimonials section" vs "section about what people say" -> first=proof; flag if second=unknown.
- G5 JIT drips: reviews->proof, final CTA->offer, each once.

## H. Robustness / performance / weird input
- H1 Vision/screenshot failure -> deterministic page still ships, no error surfaced.
- H2 Exhaust daily usage cap -> graceful message, no broken state.
- H3 10+ section page -> completes, rhythm sane, no timeout/partial write.
- H4 Rapid-fire builds + make-it-flow -> no race dropping sections / corrupting data.
- H5 Emoji / RTL / very long headings / HTML in copy -> no crash, no broken markup.
- H6 Non-English business ("panaderia en Madrid") -> builds + reorganizes, graceful inference.
- H7 Mobile/tablet preview after reorganize -> stacked layout legible, no contrast regression.

## I. Cross-feature interactions
- I1 Delete then make-it-flow -> consistent records, no ghost sections.
- I2 Make-it-flow then add a section -> auto-tidy re-evaluates, no double-tidy loop.
- I3 Clear chat mid-flow -> discovery/brief/auto-signature reset; rendered page untouched.
- I4 Brand toggle off (use_site_brand off) -> sane palette, no crash.
