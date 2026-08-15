# @volksverpetzer/whitelist-ip

Adds your current public IPv4 to a Scaleway Managed Database instance's ACL
allow list. Extracted from `vvp_app_server`'s
`add_ip_to_scaleway_allowlist.sh` so any repo/teammate can run it without
keeping a local copy.

## Usage

```bash
npx @volksverpetzer/whitelist-ip
```

Or install it once for repeated use:

```bash
pnpm add -g @volksverpetzer/whitelist-ip
whitelist-ip
```

## Configuration

Set these in your shell profile (e.g. `~/.zshrc`) so the command works from
any directory, for whichever database instance you're pointing at:

```bash
export SCALEWAY_API_KEY=...      # Scaleway API secret key
export SCALEWAY_INSTANCE_ID=...  # Managed Database (RDB) instance ID
export SCALEWAY_REGION=...       # e.g. fr-par
```

Find `SCALEWAY_INSTANCE_ID` and `SCALEWAY_REGION` on the instance's page in
the Scaleway console; generate `SCALEWAY_API_KEY` under IAM → API Keys.
