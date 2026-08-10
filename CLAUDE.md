# CLAUDE.md

Always think and reply in RUSSIAN.

See `CONTRIBUTING.md` for commit conventions and the project-tracking workflow
(GitHub Project card fields, status rules, issue types, and the exact `gh` commands).

## Package specifics

- `resources/js/chunk-upload.js` is the source, `public/chunk-upload.js` is the
  committed build — run `npm run build` after touching the source, CI checks it.
- The upload disk (`moonshine-chunk-upload.disk`) must be local: chunks are
  concatenated and renamed on the filesystem. The field's own disk can be anything.
- Protocol logic lives in `src/Support/ChunkUploadManager.php`; the controller is
  a thin HTTP translator and the field only claims finalized files. Keep it that way.
- Anything touching chunk sizing, finalize or claiming needs a test in
  `tests/Feature` — that is where the races and the path-injection surface are.
