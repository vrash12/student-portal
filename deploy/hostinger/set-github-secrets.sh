#!/usr/bin/env bash
# Run once on the owner's computer, in Git Bash, with the GitHub CLI signed in:
# stores the deploy key and the server details as secrets of the repository,
# so the "Deploy to Hostinger" workflow can deploy on every push.
#
#   bash deploy/hostinger/set-github-secrets.sh HOST PORT USER
#
# Reads ~/.ssh/octms_github_deploy (private key) and
# ~/.ssh/octms_github_deploy_known_hosts (the server's host key). Nothing is
# printed. Afterwards the private key file may be deleted from this computer.
set -euo pipefail

HOST="${1:?server address}"
PORT="${2:?SSH port}"
USER_NAME="${3:?SSH user}"
KEY_FILE="$HOME/.ssh/octms_github_deploy"
KNOWN_HOSTS_FILE="$HOME/.ssh/octms_github_deploy_known_hosts"
REPO="vrash12/student-portal"

gh secret set HOSTINGER_SSH_KEY --repo "$REPO" < "$KEY_FILE"
gh secret set HOSTINGER_KNOWN_HOSTS --repo "$REPO" < "$KNOWN_HOSTS_FILE"
gh secret set HOSTINGER_HOST --repo "$REPO" --body "$HOST"
gh secret set HOSTINGER_PORT --repo "$REPO" --body "$PORT"
gh secret set HOSTINGER_USER --repo "$REPO" --body "$USER_NAME"
echo "Done. Every push to main now deploys to Hostinger."
