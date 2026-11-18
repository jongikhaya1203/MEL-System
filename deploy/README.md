# Server sync from GitHub (USAWINAPP04, USAWINPRS01)

Each server **pulls** the MEL System from
<https://github.com/jongikhaya1203/MEL-System> into `C:\xampp\htdocs\mel_system`
every 5 minutes. Pushing to the tracked branch (`main`) is all it takes to deploy:
both servers pick it up within 5 minutes.

```
 developer PC ── git push ──▶ GitHub (main) ◀── pull every 5 min ── USAWINAPP04
                                            ◀── pull every 5 min ── USAWINPRS01
```

Pull, not push: the servers only need outbound HTTPS to github.com. Nothing connects
*into* them, and no password is stored anywhere — the task runs as the built-in SYSTEM account.

## One-time setup on each server

Sign in to the server (RDP) as an administrator, then in **PowerShell → Run as administrator**:

1. **Install Git for Windows** if it isn't there: <https://git-scm.com/download/win>
   (or `winget install --id Git.Git -e`).
2. **Get the installer** and run it:
   ```powershell
   $u = 'https://raw.githubusercontent.com/jongikhaya1203/MEL-System/main/deploy/install-server.ps1'
   Invoke-WebRequest $u -OutFile $env:TEMP\install-server.ps1 -UseBasicParsing
   powershell -ExecutionPolicy Bypass -File $env:TEMP\install-server.ps1
   ```
   - Empty or missing `C:\xampp\htdocs\mel_system` → it clones the repository.
   - Existing copy → it backs it up to `mel_system.backup_<date>`, then attaches it to GitHub in place
     (uploads and settings are kept).
3. **Set the server's database settings** in `C:\xampp\htdocs\mel_system\config\config.local.php`
   (created from `config.local.example.php`): `DB_PORT`, `DB_USER`, `DB_PASS`, and `DB_NAME`.
4. **Database (first time only):** import the export from the source system
   (`export\IMPORT.md` explains how), or for an empty system run
   `C:\xampp\php\php.exe C:\xampp\htdocs\mel_system\deploy\apply_schema.php`.
5. **Uploaded files and encryption key (first time only):** unzip `mel_files_*.zip` from the export
   into `C:\xampp\htdocs\mel_system`.

## What each pull does (`deploy/pull.ps1`)

| | |
|---|---|
| Updates | Every tracked file, to exactly match GitHub's `main`. |
| Database | If `database/schema.sql` changed, applies it (idempotent: adds missing tables/columns, never drops data or resets passwords). |
| Never touches | `storage\` uploads, `config\config.local.php`, `config\app_key.php` (all git-ignored). |
| Edits made directly on the server | Saved as a `.patch` in `C:\ProgramData\MEL-Sync\` and then replaced by the GitHub version — **make changes in GitHub, not on the servers.** |
| Log | `C:\ProgramData\MEL-Sync\pull.log` (only writes when something changes or fails). |

## Day-to-day

- **Is a server in sync?** `powershell -File C:\xampp\htdocs\mel_system\deploy\status.ps1`
- **Pull right now:** `Start-ScheduledTask 'MEL System - Pull from GitHub'`
- **Pause syncing:** `Disable-ScheduledTask 'MEL System - Pull from GitHub'` (`Enable-…` to resume)
- **Track another branch** (e.g. a UAT server on `develop`): re-run `install-server.ps1 -Branch develop`.
- **Roll back:** revert the commit on GitHub (`git revert <sha>; git push`); servers follow within 5 minutes.

## If the repository is made private

The pull uses anonymous HTTPS, which only works while the repository is public. For a private
repository, add a read-only **deploy key** (GitHub → repo → Settings → Deploy keys) on each server for
the SYSTEM account and change `origin` to `git@github.com:jongikhaya1203/MEL-System.git`.
