# Managing local environments

Lifecycle, shell access, database sync, and environment variables for `vip dev-env`. Every command takes `--slug=<env-name>`; without it VIP-CLI targets `vip-local`.

## Local Environment Lifecycle

### Core commands

| Command | Description |
|---------|-------------|
| `vip dev-env create` | Create a new local environment |
| `vip dev-env start` | Start a local environment |
| `vip dev-env stop` | Stop a local environment |
| `vip dev-env destroy` | Remove a local environment |
| `vip dev-env list` | List all local environments |
| `vip dev-env update` | Update settings of a local environment |

### Creating and starting

```bash
vip dev-env create --slug=my-site --title="My Local Site"
vip dev-env start --slug=my-site
vip dev-env start --slug=my-site --editor=vscode
```

### Stopping and removing

```bash
vip dev-env stop --slug=my-site
vip dev-env destroy --slug=my-site
```

---

## Shell Access

Open an SSH shell directly into the PHP container:

```bash
vip dev-env shell --slug=my-site --service=php
```

---

## Database Sync

Pull a database from a remote VIP Platform environment into your local environment:

```bash
vip dev-env sync sql --slug=my-site @my-app.production
```

---

## Environment Variables

Manage environment variables for a local environment:

```bash
vip dev-env envvar --slug=my-site
```
