# WordPress content cleanup

Removes old snippets (ads, shortcodes of removed plugins, pasted widgets, …) from
`post_content` on volksverpetzer.de, via `wp eval-file` on the server.

The engine (`cleanup.php`) is generic. Each cleanup is a small **rule** in `rules/`
that only says *what* to remove. The engine handles the details that went wrong
when we did this by hand:

- **Wrappers and blocks:** empty `<p>`/`<em>`/… wrappers and Gutenberg blocks that
  only held the snippet are removed too, so nothing empty is left behind.
- **Mid-word snippets:** words split by a snippet are rejoined (`A<ins…></ins>uch` → `Auch`).
- **Divi wrapper:** the `<!-- wp:divi/placeholder -->` wrapper is never touched (see below).
- **No side effects:** writes go directly through `$wpdb`, so there are no revisions,
  no `post_modified` bump, and no `save_post` hooks or webhooks.
- **Skips instead of damaging:** a post is skipped (never written) if a regex fails,
  if the open/close tag balance would change, if the post would end up empty, or if
  it matches the rule's `skip_post_if` pattern. Skipped posts are always listed.
- **Never overwrites edits:** `apply` only writes a post that is still byte-for-byte
  what was read, with the same `post_modified_gmt`. A post saved in the meantime is
  listed as skipped; run the tool again to pick it up.

Rules so far:

| Rule | Removes | Applied |
|---|---|---|
| `adsense` | old Google AdSense in-article ads | 2026-10-12, 1,493 posts |
| `vc-donation` | old WPBakery `[vc_message]` donation boxes (editorial boxes are kept) | 2026-10-12, 2,357 boxes |

## Workflow for a new snippet

1. **Write a rule.** Copy `rules/_template.php` to `rules/<name>.php` and fill in
   `like`, `needle` and `pattern`. Put a few real examples into `tests`.
2. **Test locally.** No WordPress needed, only PHP 8.1+. If you have Local
   installed, its bundled PHP works. On the server, `wp eval-file cleanup.php <name> test`
   does the same and exits with an error if a test fails:
   ```bash
   php cleanup.php <name> test
   ```
3. **Copy the folder to the server:**
   ```bash
   scp -r wp/content-cleanup root@scaleway-vvp:~/
   ```
4. **Survey** (read-only). It shows what would be removed and what is still left,
   grouped by form. Extend the rule until the "still containing" list is empty, or
   contains only things you want to keep.
   ```bash
   cd ~/content-cleanup && wp eval-file cleanup.php <name> survey --path=/var/www/html --allow-root
   ```
5. **Dry run and diffs** (read-only). Check one post per form.
   ```bash
   wp eval-file cleanup.php <name> --path=/var/www/html --allow-root
   ```
   ```bash
   wp eval-file cleanup.php <name> show <id> <id> … --path=/var/www/html --allow-root
   ```
6. **Back up, then apply:**
   ```bash
   wp db export ~/pre-<name>.sql --tables=fdhmx_posts --path=/var/www/html --allow-root
   ```
   ```bash
   wp eval-file cleanup.php <name> apply --path=/var/www/html --allow-root
   ```
   Run the dry run again afterwards. It should report 0 snippets to remove.
7. **Purge the whole Bunny zone.** Direct DB writes don't trigger the automatic purge.
8. **Spot-check** a few posts live, and old ones in the Divi builder.

Roll back with:
```bash
wp db import ~/pre-<name>.sql --path=/var/www/html --allow-root
```

## Things to know

- **Old posts (about 2014–2018) are wrapped in `<!-- wp:divi/placeholder -->`.**
  Saving one in the classic or Gutenberg editor (or "In Blöcke umwandeln") drops
  the wrapper, and the Divi builder then shows the post as empty. Edit those posts
  only in Divi, and do bulk changes with this tool.
- **Not every match should go.** `[vc_message]` was also used for editorial
  content (notes, fact-check verdicts, updates). Check the survey before you write
  a rule that removes everything, and use `remove` to keep what should stay.
- **PCRE JIT stack:** `preg error 6` on long posts means a pattern backtracks too
  much. Use possessive quantifiers (`*+`) and unrolled loops; see the
  `vc-donation` rule for an example.
- **Table prefix** on production is `fdhmx_`, not `wp_`.
