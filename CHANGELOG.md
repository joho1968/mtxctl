# Changelog

All notable changes to mtxctl will be documented here.

## [0.95.0] — 2026-06-15

### Added

- **`room kick`** — kick a user from a room (`--user`, optional `--reason`,
  requires `--confirm`).
- **`room power`** — show a user's current power level, or set it with `--level N`
  (0 = default member, 50 = moderator, 100 = admin).
- **`room list --search`** — filter the room list by name, canonical alias, or room ID.
- **`room list --retention`** — show the `m.room.retention` policy (expressed in days)
  alongside each room; uses the Synapse admin state endpoint as fallback when the
  admin token user is not a room member.
- **`room show`** — now includes the room's retention policy (if set).
- **`room retention`** — manage per-room message retention:
  - Show current policy for a single room.
  - Set with `--days=N` or remove with `--clear` (both require `--confirm`).
  - Bulk show/set/clear via `--search=term` without a room argument.
  - Bulk set auto-promotes the admin user to room admin (using the Synapse admin
    API to invite + set PL 100, then accepting the invite via the client API),
    applies the state event, reverts the power level, and leaves the room — no
    permanent trace is left in rooms the admin was not previously a member of.
  - Bulk operations retry automatically on HTTP 429, sleeping for the server's
    `retry_after_ms` before retrying (up to 4 attempts per room).
- `--days=0` is now rejected with an error; use `--clear` to remove a policy.

### Changed

- `room make-admin`, `room delete`, `room tombstone`, `room kick`, `room power`,
  and `room retention` now show the room name alongside the room ID in all
  user-facing messages (`!abc:server (My Channel)`).

---

## [0.90.0] — 2026-06-15 — Initial release

### Features

- **`user`** — list, show, whois (active sessions and last-seen IPs), deactivate,
  password reset, grant/revoke server admin (`admin`/`admin --revoke`), and
  shadow-ban (`shadow-ban`/`shadow-ban --revoke`).
- **`room`** — list, show, list members, promote a local user to room admin
  (`make-admin`), mark a room as superseded (`tombstone`), and delete with
  optional event purge.
- **`media`** — purge cached remote media older than N days (`purge --days=N`).
  Only affects copies fetched from other homeservers; locally uploaded content
  is never touched.
- **`token`** — list, show, create (with optional uses limit and expiry), and
  delete registration tokens.
- **`federation`** — list known federation destinations and show details for a
  single remote server.
- **`server`** — show Synapse/Python version and aggregated user/room counts.
- **`version`** — show mtxctl version, license, and copyright.
- Room identifiers accept both room IDs (`!abc:server`) and aliases
  (`#alias:server`) wherever a room argument is required.
- All destructive actions show a dry-run preview by default; add `--confirm`
  to execute.
- All commands accept `--json` for raw JSON output.
