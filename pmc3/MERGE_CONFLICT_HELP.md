# PMC3 Merge Conflict Quick Fix

If `pmc3/index.html` or `pmc3/api-proxy.php` contains conflict markers like:

- `<<<<<<<`
- `=======`
- `>>>>>>>`

use one of the options below.

## Option A (recommended): take the known-good PMC3 files from this branch

```bash
git checkout work -- pmc3/index.html pmc3/api-proxy.php
git add pmc3/index.html pmc3/api-proxy.php
git commit -m "Resolve pmc3 merge conflicts with known-good files"
```

## Option B: keep your branch but verify no conflict markers remain

```bash
rg -n "<<<<<<<|=======|>>>>>>>" pmc3/index.html pmc3/api-proxy.php
```

If any lines are returned, conflicts are not fully resolved.

## Sanity checks

```bash
php -l pmc3/api-proxy.php
php -l pmc3/index.html
```

## What to keep from the resolved files

- `index.html`: keep the **cost panel** block and `updateCostPanel(...)` function.
- `index.html`: keep model picker values as `gpt-5`, `gpt-5-mini`, `gpt-5-nano`, `gpt-4.1`, `gpt-4.1-mini`.
- `api-proxy.php`: keep stream error handling + completed-response text fallback.
- `api-proxy.php`: keep model aliases for both `gpt-5*` and `gpt-5.4*` inputs.
