#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODULES_DIR="$ROOT_DIR/modules"
RELEASES_DIR="$ROOT_DIR/module-releases"
TMP_DIR="$ROOT_DIR/.build/module-releases"

usage() {
  cat <<'EOF'
Usage:
  scripts/build-module-release.sh --all
  scripts/build-module-release.sh <module-slug> [<module-slug> ...]
EOF
}

if [[ ! -d "$MODULES_DIR" || ! -d "$RELEASES_DIR" ]]; then
  echo "Expected modules/ and module-releases/ under $ROOT_DIR" >&2
  exit 1
fi

if [[ $# -lt 1 ]]; then
  usage >&2
  exit 1
fi

declare -a MODULE_SLUGS=()
if [[ "$1" == "--all" ]]; then
  while IFS= read -r dir; do
    MODULE_SLUGS+=("$(basename "$dir")")
  done < <(find "$MODULES_DIR" -mindepth 1 -maxdepth 1 -type d | sort)
else
  MODULE_SLUGS=("$@")
fi

mkdir -p "$TMP_DIR"

for slug in "${MODULE_SLUGS[@]}"; do
  MODULE_DIR="$MODULES_DIR/$slug"
  MANIFEST="$MODULE_DIR/module.json"

  if [[ ! -d "$MODULE_DIR" ]]; then
    echo "Missing module directory: $MODULE_DIR" >&2
    exit 1
  fi

  if [[ ! -f "$MANIFEST" ]]; then
    echo "Missing module manifest: $MANIFEST" >&2
    exit 1
  fi

  VERSION="$(
    php -r '
      $manifest = json_decode((string) file_get_contents($argv[1]), true);
      if (!is_array($manifest) || !is_string($manifest["version"] ?? null) || trim($manifest["version"]) === "") {
          fwrite(STDERR, "module.json is missing a version\n");
          exit(1);
      }
      $minimum = trim((string) ($manifest["minimum_metis"] ?? ""));
      if ($minimum === "") {
          fwrite(STDERR, "module.json is missing minimum_metis\n");
          exit(1);
      }
      $maximum = trim((string) ($manifest["maximum_metis"] ?? ""));
      $compatible = $manifest["compatible_core"] ?? [];
      if ($compatible !== [] && !is_array($compatible)) {
          fwrite(STDERR, "module.json compatible_core must be an object\n");
          exit(1);
      }
      $compatibleMinimum = trim((string) ($compatible["minimum"] ?? ""));
      $compatibleMaximum = trim((string) ($compatible["maximum"] ?? ""));
      if ($compatibleMinimum !== "" && $compatibleMinimum !== $minimum) {
          fwrite(STDERR, "module.json minimum_metis must match compatible_core.minimum\n");
          exit(1);
      }
      if ($maximum !== "" && version_compare($maximum, $minimum, "<")) {
          fwrite(STDERR, "module.json maximum_metis must be >= minimum_metis\n");
          exit(1);
      }
      if ($compatibleMaximum !== "" && $maximum !== "" && $compatibleMaximum !== $maximum) {
          fwrite(STDERR, "module.json maximum_metis must match compatible_core.maximum\n");
          exit(1);
      }
      echo trim($manifest["version"]);
    ' "$MANIFEST"
  )"

  ARCHIVE_PATH="$RELEASES_DIR/$slug.$VERSION.tar.gz"
  STAGING_DIR="$TMP_DIR/$slug"

  rm -rf "$STAGING_DIR"
  mkdir -p "$STAGING_DIR"
  rsync -a \
    --delete \
    --exclude '.DS_Store' \
    --exclude '._*' \
    --exclude '__MACOSX' \
    --exclude '.git' \
    "$MODULE_DIR/" "$STAGING_DIR/"

  rm -f "$ARCHIVE_PATH"
  COPYFILE_DISABLE=1 tar \
    --exclude '.DS_Store' \
    --exclude '._*' \
    --exclude '__MACOSX' \
    -czf "$ARCHIVE_PATH" \
    -C "$TMP_DIR" \
    "$slug"

  echo "Built $ARCHIVE_PATH"
done

php "$ROOT_DIR/scripts/refresh-module-registry.php" "${MODULE_SLUGS[@]}"
