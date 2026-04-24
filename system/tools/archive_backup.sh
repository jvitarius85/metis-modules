#!/bin/zsh
set -euo pipefail

workspace_root="/Users/jvitarius85/Library/CloudStorage/CloudMounter-TheHRDeptNAS/Web"
project_name="metis"
source_root="${workspace_root}/${project_name}"
backup_root="/Volumes/NAS/backups 2/${project_name}"
archives_root="${backup_root}/archives"
snapshots_root="${backup_root}/snapshots"
state_root="${backup_root}/state"
timestamp="$(date +%Y%m%d-%H%M%S)"
current_link="${snapshots_root}/current"
snapshot_path="${snapshots_root}/${timestamp}"
delta_root="${state_root}/delta-${timestamp}"
archive_path="${archives_root}/${project_name}-code-incremental-${timestamp}.tar.gz"
manifest_path="${archives_root}/${project_name}-code-incremental-${timestamp}.json"

excludes=(
  "--exclude=.git"
  "--exclude=.github"
  "--exclude=.metis-integrity"
  "--exclude=node_modules"
  "--exclude=vendor"
  "--exclude=storage"
)

mkdir -p "${archives_root}" "${snapshots_root}" "${state_root}"
rm -rf "${delta_root}"
mkdir -p "${delta_root}" "${snapshot_path}"

previous_snapshot=""
if [[ -L "${current_link}" ]]; then
  previous_snapshot="$(readlink "${current_link}")"
fi

rsync_base=(
  -a
  --delete
  "--exclude=.git"
  "--exclude=.github"
  "--exclude=.metis-integrity"
  "--exclude=node_modules"
  "--exclude=vendor"
  "--exclude=storage"
)

if [[ -n "${previous_snapshot}" && -d "${previous_snapshot}" ]]; then
  rsync "${rsync_base[@]}" --link-dest="${previous_snapshot}" "${source_root}/" "${snapshot_path}/"
  rsync "${rsync_base[@]}" --compare-dest="${previous_snapshot}" "${source_root}/" "${delta_root}/"
else
  rsync "${rsync_base[@]}" "${source_root}/" "${snapshot_path}/"
  rsync "${rsync_base[@]}" "${source_root}/" "${delta_root}/"
fi

(
  cd "${delta_root}"
  tar -czf "${archive_path}" .
)

file_count="$(find "${delta_root}" -type f | wc -l | tr -d ' ')"
archive_sha="$(shasum -a 256 "${archive_path}" | awk '{print $1}')"

cat > "${manifest_path}" <<EOF
{
  "project": "${project_name}",
  "created_at": "${timestamp}",
  "source_root": "${source_root}",
  "snapshot_path": "${snapshot_path}",
  "previous_snapshot": "${previous_snapshot}",
  "archive_path": "${archive_path}",
  "archive_sha256": "${archive_sha}",
  "delta_file_count": ${file_count}
}
EOF

ln -sfn "${snapshot_path}" "${current_link}"
rm -rf "${delta_root}"

echo "${archive_sha}  ${archive_path}"
echo "${manifest_path}"
echo "${snapshot_path}"
