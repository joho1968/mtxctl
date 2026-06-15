# mtxctl

Command-line admin tool for Matrix/Synapse homeservers. Wraps the Synapse Admin API
for day-to-day user, room, media, and federation management.

Built on [matrix-php](https://codeberg.org/joho1968/matrix-php) and [symfony/console](https://symfony.com/doc/current/console.html).

## Requirements

- PHP 8.4+
- Synapse homeserver with the Admin API accessible
- An admin user's access token (not an Application Service token)

## Installation

```bash
git clone <repo> && cd mtxctl
php8.4 /path/to/composer install --no-dev
cp config/config.example.php config/config.php
# edit config/config.php
```

Or deploy to a target directory or tarball:

```bash
php8.4 deploy/deploy.php --target=/opt/mtxctl
php8.4 deploy/deploy.php --tar=/tmp/mtxctl.tar.gz
```

A shell alias keeps invocations short:

```bash
alias mtxctl='php8.4 /opt/mtxctl/bin/mtxctl.php'
```

## Configuration

`config/config.php` returns a plain PHP array:

```php
return [
    'homeserver_url' => 'https://matrix.example.com',
    'admin_token'    => 'syt_...',
];
```

To obtain an admin token:

```bash
curl -s -XPOST 'https://matrix.example.com/_matrix/client/v3/login' \
  -H 'Content-Type: application/json' \
  -d '{"type":"m.login.password","user":"admin","password":"..."}' \
  | jq -r .access_token
```

## Commands

```
php8.4 bin/mtxctl.php <command> <action> [options]
```

All commands accept `--json` for raw JSON output. Destructive actions require `--confirm`;
running without it shows a dry-run preview.

### user

```bash
user list [--limit=N] [--from=N] [--deactivated] [--guests]
user show <@user:server>
user whois <@user:server>              # active sessions, IPs, devices
user deactivate <@user:server> [--erase] [--confirm]
user password <@user:server> <newpass>
user admin <@user:server>             # grant admin
user admin --revoke <@user:server>    # revoke admin
user shadow-ban <@user:server>        # hide messages from everyone else
user shadow-ban --revoke <@user:server>
```

### room

Room identifiers accept a room ID (`!abc:server`) or an alias (`#alias:server`).
Both must be quoted in most shells — `#` is a comment and `!` triggers history
expansion, so the shell strips them before the argument reaches mtxctl.

```bash
room list [--limit=N] [--from=N] [--search=term] [--retention]
room show <room>
room members <room>
room make-admin <room> --user <@user:server>     # works even if user is not a member
room kick <room> --user <@user:server> [--reason="..."] [--confirm]
room power <room> --user <@user:server>          # show current power level
room power <room> --user <@user:server> --level N  # set power level (0=member, 50=mod, 100=admin)
room retention <room>                            # show retention for one room
room retention <room> --days=N [--confirm]       # set retention on one room
room retention <room> --clear [--confirm]        # clear retention on one room
room retention --search=term                     # show retention for all matching rooms
room retention --search=term --days=N [--confirm]  # bulk set (auto-promotes if needed)
room retention --search=term --clear [--confirm]   # bulk clear
room tombstone <old-room> <new-room> [--body="..."] [--confirm]
room delete <room> [--no-purge] [--confirm]
```

### media

```bash
media purge [--days=90] [--confirm]   # purge cached remote media older than N days
```

Only cached copies of media fetched from other homeservers are removed.
Locally uploaded files and protected media are never touched.

### token

```bash
token list
token show <token>
token create [--token=<str>] [--uses=N] [--expires-days=N]
token delete <token> [--confirm]
```

### federation

```bash
federation list [--limit=N] [--from=N]
federation show <server>
```

### server

```bash
server version   # Synapse and Python version
server stats     # user count, room count, version
```

### version

```bash
version          # mtxctl version, license, and copyright
```

## License

GNU Affero General Public License v3.0 or later. See `LICENSE`.

See [matrix-php](../matrix-php/) for the underlying library.
 
## Copyright

Written by Joaquim Homrighausen while converting caffeine into code.
Copyright 2026 Joaquim Homrighausen; all rights reserved.

Sponsored by WebbPlatsen i Sverige AB, Sweden.

If you need a GDPR-safe place to host your Matrix, Mattermost, and/or
RocketChat instance, get in touch with support@webbplatsen.se
