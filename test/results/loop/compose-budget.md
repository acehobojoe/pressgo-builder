# Compose budget log

**LANE B DISABLED by Joe, 2026-07-30 — no card charges for building. Do not run
composes on any API key until Joe explicitly re-enables with a named payment path.**

| date | run | composes | model | tokens in/out | est cost | key |
|------|-----|----------|-------|---------------|----------|-----|
| 2026-07-30 | day-one bootstrap, 15 briefs | 15 | claude-sonnet-4-5 | 87,067 / 99,736 | ~$1.76 | backend ANTHROPIC_API_KEY (Joe's card) |

Finding from the bootstrap: the freeform composition prompt is single-section
scoped. All 15 trees came back as ONE section (the hero) despite full-page
briefs, with strong copy hierarchy inside that section. The renderer rendered
15/15 with zero errors. Full-page corpus needs a prompt-schema change, which is
Lane B work and stays parked until Lane B is re-enabled. Lane A polishes the
renderer against these 15 single-section trees at zero cost.
