---
name: wordpress-cli
description: "WP-CLI (wp) reference for managing WordPress sites from the command line — plugins, themes, users, database, content, cache, and more. Use when the user wants to run or write wp commands, install, activate, or update plugins and themes, create or reset users, export, import, or search-replace the database, flush cache or rewrite rules, run cron events, toggle maintenance mode, update core, or troubleshoot a WordPress site from the terminal, even if they never say WP-CLI. For WordPress VIP sites (vip dev-env locally, vip @app.env remotely), use the wp-vip-cli skill instead."
---

# WordPress CLI

A skill for working with WP-CLI to manage WordPress installations from the terminal.

## When to use

Any WordPress install that `wp` can reach directly: local, Docker (`--allow-root`), or over SSH. On WordPress VIP, the same `wp` commands run through the wp-vip-cli skill (`vip dev-env exec -- wp ...` locally, `vip @app.env -- wp ...` remotely).

## Start with `wp help`

Before guessing at flags or subcommands, **use the built-in help system**. This is the single most important habit for working with WP-CLI accurately.

```bash
# Top-level overview of every available command
wp help

# Detailed help for a specific command (arguments, flags, examples)
wp help <command>

# Help for a subcommand
wp help <command> <subcommand>
```

**Examples:**

```bash
wp help plugin            # Shows install, activate, deactivate, update, list, etc.
wp help search-replace    # Shows positional args, --dry-run, --precise, table filters
wp help user create       # Shows exact syntax for creating a user
```

**Rule of thumb:** If you are unsure about the exact flags or syntax for any WP-CLI command, run `wp help <command>` first and read the output before constructing the actual command.

---

## Common Use Cases & Examples

### Plugins & Themes

```bash
wp plugin list
wp plugin install woocommerce --activate
wp plugin deactivate akismet
wp plugin update --all

wp theme list
wp theme activate flavor
wp theme update --all
```

### Users

```bash
wp user list
wp user create jdoe jdoe@example.com --role=editor
wp user update 42 --user_pass=newpassword
wp user delete 42 --reassign=1
```

### Database & Migrations

```bash
wp db export backup.sql
wp db import backup.sql
wp db check

# Search-replace (always dry-run first)
wp search-replace 'http://old-domain.com' 'https://new-domain.com' --dry-run
wp search-replace 'http://old-domain.com' 'https://new-domain.com'
```

### Options & Configuration

```bash
wp option get siteurl
wp option update blogdescription "A better tagline"
wp config get --format=table
wp config set WP_DEBUG true --raw
```

### Posts & Content

```bash
wp post list --post_type=page --format=table
wp post create --post_title="Hello World" --post_status=publish
wp post delete 99 --force
wp export
wp import content.xml --authors=create
```

### Cache, Transients & Cron

```bash
wp cache flush
wp transient delete --all
wp cron event list
wp cron event run --due-now
```

### Maintenance & Rewrite Rules

```bash
wp maintenance-mode activate
wp maintenance-mode deactivate
wp rewrite flush
wp core version
wp core update
```

---

## Useful Global Flags

| Flag | Purpose |
|------|---------|
| `--path=/path/to/wp` | Target a specific WordPress install |
| `--url=example.com` | Target a specific site in a multisite network |
| `--format=json` | Output as JSON (also: `csv`, `table`, `yaml`) |
| `--quiet` | Suppress informational messages |
| `--allow-root` | Run as root (useful inside Docker containers) |

---

## Command Pattern

All WP-CLI commands follow this structure:

```
wp <command> <subcommand> [positional-args] [--named-args] [--flags]
```

When in doubt, run `wp help <command>` to see exactly what's available.
