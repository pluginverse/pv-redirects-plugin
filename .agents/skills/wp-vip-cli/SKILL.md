---
name: wp-vip-cli
description: "VIP-CLI (vip) reference for managing WordPress VIP local development environments and running commands against remote VIP Platform environments. Use when the user mentions WordPress VIP, vip dev-env, vip wp, or an @app.environment target, wants to create, start, stop, or destroy a local VIP environment, run WP-CLI inside one (vip dev-env exec) or against a hosted VIP environment, sync a VIP database to local, open a shell into the container, or set its env vars, even if they just say 'run wp on the VIP site'. For WP-CLI on a site that is not on VIP, use the wordpress-cli skill instead."
---

# WordPress VIP CLI

A skill for working with the VIP-CLI toolchain — managing local development environments with `vip dev-env` and running WP-CLI against remote VIP Platform environments with `vip wp`.

## When to use

Any WordPress site hosted on WordPress VIP, locally (`vip dev-env`) or remotely (`vip @app.env`). For a WordPress site that is not on VIP, or for the `wp` subcommands themselves, use the wordpress-cli skill; this skill only wraps them.

## Installation

VIP-CLI is installed globally via npm:

```bash
npm install -g @automattic/vip
```

---

## The Key Command: `vip dev-env exec`

This is how you run WP-CLI commands inside a VIP local environment. Every `wp` command goes after the `--` separator:

```bash
vip dev-env exec --slug=<env-name> -- wp <command>
```

The `--` separator is **required** — it prevents argument conflicts between `vip` flags and `wp` flags.

**Examples:**

```bash
vip dev-env exec --slug=my-site -- wp option get home
vip dev-env exec --slug=my-site -- wp plugin list --format=json
vip dev-env exec --slug=my-site -- wp user list
vip dev-env exec --slug=my-site -- wp search-replace 'http://old.com' 'https://new.com' --dry-run
vip dev-env exec --slug=my-site -- wp cache flush
```

**Using help inside the environment:**

```bash
vip dev-env exec --slug=my-site -- wp help
vip dev-env exec --slug=my-site -- wp help plugin
vip dev-env exec --slug=my-site -- wp help search-replace
```

**Rule of thumb:** Any WP-CLI command you would normally run as `wp ...` becomes `vip dev-env exec --slug=<name> -- wp ...` inside a VIP local environment.

---

## Managing local environments

Read `references/dev-env.md` when creating, starting, stopping, or destroying a local environment, opening a shell into a container, pulling a database down from VIP Platform, or setting environment variables; the Quick Reference below has the one-liners.

---

## VIP Platform (Remote) — `vip wp`

Run WP-CLI directly against a hosted VIP environment. Use the `@app.environment` syntax to target the right environment. The `--` separator is required.

```bash
vip @my-app.production -- wp option get home
vip @my-app.staging -- wp post list --posts_per_page=50
vip @my-app.develop -- wp user list --format=json
```

---

## Global Flags

Available on all `vip dev-env` commands:

| Flag | Purpose |
|------|---------|
| `-s` / `--slug` | Target a specific named environment (default: `vip-local`) |
| `-d` / `--debug` | Verbose output for debugging |
| `-h` / `--help` | Show help for any subcommand |
| `-v` / `--version` | Show VIP-CLI version |

---

## Quick Reference

| I want to... | Command |
|--------------|---------|
| Run a WP-CLI command locally | `vip dev-env exec --slug=my-site -- wp <command>` |
| Run a WP-CLI command on production | `vip @my-app.production -- wp <command>` |
| Get help on a WP-CLI command locally | `vip dev-env exec --slug=my-site -- wp help <command>` |
| Open a shell in the local container | `vip dev-env shell --slug=my-site --service=php` |
| Pull the production database locally | `vip dev-env sync sql --slug=my-site @my-app.production` |
| List all local environments | `vip dev-env list` |
| Create a local environment | `vip dev-env create --slug=my-site --title="My Local Site"` |
| Start or stop a local environment | `vip dev-env start --slug=my-site` / `vip dev-env stop --slug=my-site` |
| Remove a local environment | `vip dev-env destroy --slug=my-site` |
