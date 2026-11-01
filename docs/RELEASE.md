# Release preparation

This file prepares a release. No release, tag or announcement has been made.

1. Confirm the CI matrix passes on the release commit.
2. Check `core_version_requirement` against the matrix in `README.md`. Keep
   `^12` only if Drupal 12 tests pass on the release commit. Remove
   `scripts/apply-test-patch.sh` and its patch when core no longer needs them.
3. Move the `Unreleased` section of `CHANGELOG.md` to the new version.
4. Create the `2.x` branch and the tag on drupal.org.
5. Backport security fixes to 1.x.

## Release note draft

Project Context Connector 2.0.0 fixes signed route access, stops OPTIONS from
returning a snapshot, and keeps snapshot responses out of page caches. Fresh
installs now expose less data. Existing sites keep their settings. Read
`UPGRADE.md` first.

## Future community checkpoint

A person decides after release whether to ask for feedback and how to measure
demand. No adoption has been measured and no outreach has been done.
