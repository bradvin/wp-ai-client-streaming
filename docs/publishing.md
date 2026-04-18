# Publishing

Date: 2026-04-18

## Release Version

Use Git tags for package versions. Do not add a `version` field to `composer.json`.

The current release line is:

- `v0.1.x`

## Preflight Checks

Run these from the package repo:

```bash
composer validate --strict
```

```bash
git status --short
```

Make sure:

- `composer.json` is committed at the repository root
- the package name is `bradvin/wp-ai-client-streaming`
- the repo is public on GitHub
- the branch you want to release from is pushed

## Tag The Release

Create an annotated tag:

```bash
git tag -a v0.1.1 -m "Release v0.1.1"
```

Push the branch and tag:

```bash
git push origin main
git push origin v0.1.1
```

## Submit To Packagist

Submit the GitHub repository URL:

- `https://github.com/bradvin/wp-ai-client-streaming`

Then enable automatic updates from GitHub so future tags are indexed quickly.

## After Packagist Submission

Consumers can install the package with:

```bash
composer require bradvin/wp-ai-client-streaming:^0.1
```

Wrapper plugins or other projects that currently use a local path repository can then switch to the Packagist package and remove their custom repository override.

## Local Development Note

If a consumer is still using Composer `type: path` for this repo, Composer will usually resolve it as `dev-main` instead of the Git tag. Switch that consumer to the GitHub VCS repository or Packagist once the release tag is pushed.
