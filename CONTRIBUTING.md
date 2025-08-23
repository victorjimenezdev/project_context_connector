# Contributing

Thanks for helping improve Project Context Connector.

- Follow PSR‑12 and Drupal coding standards.
- All PHP must include PHPDoc; all strings translatable (`t()`).
- Add unit tests and kernel tests where practical.
- Run the CI locally before submitting a merge request:
  - `composer install`
  - `vendor/bin/phpcs --standard=Drupal,DrupalPractice`
  - `vendor/bin/phpstan analyse -c phpstan.neon.dist`
  - `vendor/bin/phpunit -c phpunit.xml.dist`

## Branching
- `1.x` – Drupal 10/11 compatible branch.
- Feature branches: `issue-####-short-description`.

## Commits
- Reference Drupal.org issue numbers when applicable.
- Keep commits atomic and messages descriptive.

## Code of Conduct
By participating, you agree to abide by the [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md).
