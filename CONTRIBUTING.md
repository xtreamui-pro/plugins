# Contributing

Bug reports, fixes and new features for the connectors are welcome.

## Reporting a problem

Open an issue with: the connector and its version (the zip name, or the version in its main file), the
version of the host system (WHMCS 8.x, WordPress + WooCommerce …), what you did, what you expected, and
the error — the module log of the host system usually has it. **Remove API keys, passwords, line
credentials and customer data before you paste a log.**

## Pull requests

1. Fork, branch from `main`, keep one change per pull request.
2. Stay inside the folder of the connector you change. Its layout is the layout of the zip.
3. Keep the split most connectors use: a core that does not depend on the host system (API client,
   provisioning rules) and a thin layer that plugs it in. The harnesses in `e2e/` load the core alone.
4. Code supported by the host system's oldest version still in the connector's README (no newer PHP or
   Python syntax than that).
5. A change of behaviour bumps the connector's version **everywhere its README lists it**, and adds a line
   to its `CHANGELOG.md` when it has one.
6. Say in the pull request how you tested it: inside the real host system, with an `e2e/` harness against
   a panel, or syntax check only.
7. Never commit an API key, a password or a `config.php` with real values.

The checks that run on every pull request (`.github/workflows/lint.yml`) can be run locally:

```sh
find . -path ./e2e -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
find . -path ./e2e -prune -o -name '*.py'  -print0 | xargs -0 python3 -m py_compile
```

The maintainers run every connector against a real panel before a release; a merged change ships in the
next `plugin-<id>-v<version>` release and in the next panel version.

## The panel side

The panel's registry of connectors (version shown on the download page, install steps, translations) lives
in the panel's own repository. A new connector or a version bump needs a change there too; the maintainers
make it when they merge.
