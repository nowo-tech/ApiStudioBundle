#!/usr/bin/env sh
# SearchKit (optional, nowo-tech/search-kit-bundle) for the SearchKit test suites and static analysis.
# SearchKit 1.9 needs PHP >= 8.3 while this bundle supports PHP 8.2, so it is kept out of composer.json and
# composer.lock: CI installs it here, only on PHP 8.3 or later. On PHP 8.2 nothing happens and the SearchKit tests
# are skipped (tests/SearchKit).
#
# Usage: .github/ci/search-kit.sh [--no-update]
#   --no-update  only add the requirement (the job runs its own `composer update` afterwards)
set -eu

if ! php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then
    echo "SearchKit skipped: PHP $(php -r 'echo PHP_VERSION;') < 8.3."
    exit 0
fi

# composer.json pins the lock's platform to PHP 8.2: resolve for the PHP that runs (as the test matrix does).
composer config platform.php "$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . ".99";')"

constraint="^1.9"

if [ "${1:-}" = "--no-update" ]; then
    composer require --dev --no-interaction --no-update "nowo-tech/search-kit-bundle:$constraint"
else
    composer require --dev --no-interaction --no-progress --update-with-dependencies "nowo-tech/search-kit-bundle:$constraint"
fi
