# Contributing

## Commits

- Conventional Commits: `type(scope): summary` (`feat(field): ...`, `fix(controller): ...`, `refactor(...)`, `chore(...)`), summary in English, imperative mood.
- New commit instead of `--amend`, unless amending was explicitly requested.
- No `Co-Authored-By` trailer unless explicitly requested.
- `git push` only when explicitly requested — not automatically after committing.

## Before you push

```bash
composer check          # rector + pint + phpstan + pest
npm run build           # only if resources/js changed — the bundle is committed
```

CI runs the same four checks plus a build job that fails when
`public/chunk-upload.js` is out of date with `resources/js/chunk-upload.js`.

Anything touching the upload protocol (chunk sizing, finalize, claiming) needs a
test in `tests/Feature` — that layer is where the race conditions and the
path-injection surface live.

Version bumps go in `composer.json` (`version`): pushing to `main` with a new
value tags the commit and cuts a GitHub release automatically.

## Project tracking

Work is tracked on the **"Age of Astir"** GitHub Project (org-level, `AgeOfAstir`,
project number 4, private: `https://github.com/orgs/AgeOfAstir/projects/4`).
There's a second, unused board "Age of Astir Project" (number 1) — don't confuse
the two.

**This is mandatory, not optional.** Every commit (or small group of commits for
one piece of work) gets a card, created right after committing — same turn, not
deferred, not skipped because the change "seemed small." If it's genuinely unclear
whether a given commit warrants its own card (a trivial follow-up to a card already
open, or a WIP commit that's part of a larger not-yet-finished task), don't guess
and don't silently skip it — ask which it is.

Every finished piece of work gets an issue in this repo, added to that board:

- Title and description in **Russian** (team's working language for tracking, even
  though code/commits are in English).
- Issue type: `Feature` for most refactors/improvements, `Bug` only for actually-broken
  behavior, `Task` for routine maintenance with no user-facing value.
- Body states: type, complexity (XS–XL), what was done, affected commits.
- Card status defaults to **`In review`**, not `Done` — the person doing the work
  doesn't mark their own card `Done`.
- Set `Size` (XS–XL), `Effort` (Low/Medium/High), and `Estimate` (numeric effort
  points, optional) if you have a sense of scope.

### Mechanics: creating and updating a card

```
gh issue create --repo w33bvGL/moonshine-chunk-upload \
  --title "<brief, in Russian>" \
  --body-file <file with description> \
  --label enhancement|bug \
  --type Feature|Bug|Task \
  --assignee @me \
  --project "Age of Astir"
```

Then set board fields (`Status` → `In review`, `Size`, `Effort`, `Estimate`):

```
gh project item-list 4 --owner AgeOfAstir --format json   # find the item id for the new issue
gh project field-list 4 --owner AgeOfAstir                 # field ids + single-select option ids

# single-select fields (Status, Size, Effort, Priority):
gh project item-edit --id <item-id> --project-id PVT_kwDOCt3tWM4BbLWx \
  --field-id <field-id> --single-select-option-id <option-id>

# numeric field (Estimate, field id PVTF_lADOCt3tWM4BbLWxzhV9w-c):
gh project item-edit --id <item-id> --project-id PVT_kwDOCt3tWM4BbLWx \
  --field-id PVTF_lADOCt3tWM4BbLWxzhV9w-c --number <effort-points>
```

**Effort field** (id `PVTSSF_lADOCt3tWM4BbLWxzhYSSJQ`) options:

| Option | id         |
|--------|------------|
| Low    | `451e4209` |
| Medium | `bb076b49` |
| High   | `f1a2ae33` |

Setting `Status` to `Done` auto-closes the linked repo issue (project automation)
— no separate `gh issue close` needed.

**Access:** `gh project` commands need the `read:project`/`project` OAuth scopes,
which the default token may lack. If a command fails on a missing scope, run
`gh auth refresh -s read:project -s project`.
