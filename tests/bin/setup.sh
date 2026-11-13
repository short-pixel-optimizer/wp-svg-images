#!/usr/bin/env bash
# Runs inside the `setup` container (network access, repository mounted at /plugin).
#
# Usage: setup.sh <wp-version|none>
#   <wp-version>  latest | nightly | X.Y (newest X.Y.Z) | X.Y.Z
#   none          only install Composer dependencies
#
# Installs tests/vendor from tests/composer.lock, downloads WordPress core and
# the WordPress PHPUnit library into tests/.cache/wp/<resolved>/ when missing,
# and prints `RESOLVED_WP=<version>` as its last line.
set -euo pipefail

request="${1:?usage: setup.sh <wp-version|none>}"
cache=/plugin/tests/.cache/wp

composer install --working-dir=/plugin/tests --no-interaction --no-progress --quiet

if [ "$request" = "none" ]; then
	echo "RESOLVED_WP=none"
	exit 0
fi

resolve() {
	case "$1" in
		nightly) echo nightly ;;
		*)
			# shellcheck disable=SC2016 # PHP code, expanded by PHP
			curl -fsSL https://api.wordpress.org/core/stable-check/1.0/ | php -r '
				$req = $argv[1];
				$all = json_decode(stream_get_contents(STDIN), true);
				if (!is_array($all)) { fwrite(STDERR, "cannot read the WordPress version list\n"); exit(1); }
				if ($req === "latest") {
					$hit = array_keys($all, "latest", true);
				} else {
					$hit = array_filter(array_keys($all), function ($v) use ($req) {
						return $v === $req || strpos($v, $req . ".") === 0;
					});
				}
				if (!$hit) { fwrite(STDERR, "unknown WordPress version: $req\n"); exit(1); }
				usort($hit, "version_compare");
				echo end($hit), "\n";
			' "$1"
			;;
	esac
}

version="$(resolve "$request")"
target="$cache/$version"

if [ "$version" = nightly ]; then
	rm -rf "$target"
fi

if [ ! -f "$target/.complete" ]; then
	echo "Downloading WordPress $version ..." >&2
	rm -rf "$target"
	mkdir -p "$target"
	if [ "$version" = nightly ]; then
		core_url=https://wordpress.org/nightly-builds/wordpress-latest.zip
		svn_path=trunk
	else
		core_url="https://wordpress.org/wordpress-$version.zip"
		svn_path="tags/$version"
	fi
	curl -fsSL -o "$target/core.zip" "$core_url"
	unzip -q "$target/core.zip" -d "$target"
	rm "$target/core.zip"
	svn export --quiet --ignore-externals \
		"https://develop.svn.wordpress.org/$svn_path/tests/phpunit/includes/" \
		"$target/tests-lib/includes"
	touch "$target/.complete"
fi

echo "RESOLVED_WP=$version"
