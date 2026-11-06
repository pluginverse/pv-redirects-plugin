---
title: Testing
description: Run the pinned WordPress test environment and inspect a release archive.
---

Install Docker, Node.js, npm, and Composer, then run `npm ci` and `composer install`. The npm lockfile pins `@wordpress/env` 10.38.0. All Composer environment scripts and tests use this local executable.

```sh
composer env:start
composer test
composer phpstan
npm run lint
npm run build
npm run test:package
composer env:stop
```

`npm run build` updates committed `build/` bundles; check the diff. CI checks that a fresh build matches committed bundles. To inspect the package, run `npm run package`, then `python3 scripts/package.py --verify dist/pv-redirects.zip` and `unzip -l dist/pv-redirects.zip`. Install the ZIP in a disposable WordPress environment, activate it, exercise redirects and migration, and run WordPress Plugin Check against the installed package before release. Do not treat a source checkout test as proof of packaged bootstrap behavior.
