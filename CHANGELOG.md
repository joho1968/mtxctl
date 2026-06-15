# Changelog

All notable changes to mtxctl will be documented here.

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
