# Contributing to local_batchanalytics

## Getting Started

1. Clone the repository into your Moodle installation:
   ```bash
   git clone https://github.com/EmertxeInfoTech/moodle-local-batchanalytics.git local/batchanalytics
   ```
2. Visit **Site Administration > Notifications** to install the plugin.
3. Configure Zoho CRM credentials under **Site Administration > Plugins > Local plugins > Batch Analytics**.

## Branch Naming

| Type | Branch name |
|---|---|
| New feature | `feature/<short-description>` |
| Bug fix | `fix/<short-description>` |
| Documentation | `docs/<short-description>` |
| Refactor | `refactor/<short-description>` |

Examples: `feature/export-to-csv`, `fix/crm-token-expiry`, `docs/api-endpoints`

## Workflow

1. **Create a branch** from `main`:
   ```bash
   git checkout main
   git pull origin main
   git checkout -b feature/<name>
   ```

2. **Make your changes** — keep commits small and focused.

3. **Commit** with a clear message:
   ```bash
   git add <files>
   git commit -m "feat: add CSV export for batch grades"
   ```

4. **Push** your branch:
   ```bash
   git push origin feature/<name>
   ```

5. **Open a Pull Request** against `main` on GitHub with a description of what changed and why.

6. **Address review feedback**, then a maintainer will merge.

## Commit Message Format

```
<type>: <short summary>
```

| Type | When to use |
|---|---|
| `feat` | New feature |
| `fix` | Bug fix |
| `docs` | Documentation only |
| `refactor` | Code change with no behaviour change |
| `chore` | Build, config, or dependency updates |

## Rules

- **Never commit directly to `main`** — all changes must go through a Pull Request.
- **One concern per PR** — avoid bundling unrelated changes.
- **No credentials in code** — Zoho CRM secrets must stay in Moodle admin settings only.
- **Test before pushing** — clear Moodle caches (`php admin/cli/purge_caches.php`) and verify your changes in a running Moodle instance.

## Plugin Architecture

See [README.md](README.md) for a full overview of the architecture, API endpoints, and data flow.
