# vvp_tools

Shared internal CLIs and scripts for Volksverpetzer's engineering team —
things every repo/teammate might need (e.g. whitelisting an IP with a cloud
provider), rather than a `scripts/` folder duplicated per repo. Not
design/brand code — see [`vvp_design_system`](https://github.com/Volksverpetzer/vvp_design_system)
for that.

- [`packages/whitelist-ip`](packages/whitelist-ip) — `@volksverpetzer/whitelist-ip`.
  Adds your current public IPv4 to a Scaleway Managed Database instance's ACL
  allow list.

## Consuming

Packages publish to the public npm registry as `@volksverpetzer/*` — no
`.npmrc` registry mapping or auth needed to install them.

```bash
npx @volksverpetzer/whitelist-ip
```

## Releasing

Versioning uses [Changesets](https://github.com/changesets/changesets),
mirroring `vvp_design_system`. Run `pnpm changeset` to describe a change;
merging to `main` opens a "Version Packages" PR, and merging that PR
publishes to npm.

Publishing uses [Trusted Publishing](https://docs.npmjs.com/trusted-publishers/)
(OIDC) — no `NPM_TOKEN` secret. As with `vvp_design_system`:

- **A package's first version can't go out via CI.** npm requires the
  package to already exist before Trusted Publishing can be configured for
  it, so each new package needs one manual `npm publish` (from an account
  with access to the `@volksverpetzer` scope) before this applies.
- **After that**, go to the package's Settings → Trusted Publisher on
  npmjs.com and add: repository `Volksverpetzer/vvp_tools`, workflow file
  `release.yml`, environment left blank.
