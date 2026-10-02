#!/usr/bin/env bash
# Installed on the Hostinger account as ~/deploy/octms-receive.sh and set as the
# forced command of the GitHub Actions deploy key in ~/.ssh/authorized_keys, so
# that key can do nothing else. Reads one release (tar.gz) on standard input,
# unpacks it and runs the release's own deploy/hostinger/remote-deploy.sh.
# The commit id arrives as the SSH command. One deploy at a time.
set -euo pipefail

COMMIT="${SSH_ORIGINAL_COMMAND:-}"
if ! [[ "$COMMIT" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Expected the full commit id as the command." >&2
    exit 2
fi

RELEASES="$HOME/releases"
mkdir -p "$RELEASES"
exec 9>"$RELEASES/.deploy.lock"
flock -w 900 9 || { echo "Another deploy is still running." >&2; exit 3; }

DIR="$RELEASES/$COMMIT"
rm -rf "$DIR" "$DIR.tar.gz"
cat > "$DIR.tar.gz"
mkdir -p "$DIR"
tar -xzf "$DIR.tar.gz" -C "$DIR"

status=0
bash "$DIR/deploy/hostinger/remote-deploy.sh" "$DIR" "$COMMIT" || status=$?
rm -rf "$DIR" "$DIR.tar.gz"
exit "$status"
