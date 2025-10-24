# Lightsail Deployment Runbook

This runbook automates the Amazon Bedrock smoke test and AWS Lightsail deployment path for the Playlist Browser app. Follow the steps in order to prepare your workstation, validate Bedrock connectivity, and publish the site with TLS and snapshot coverage.

## 1. Workstation Preparation (WSL Ubuntu)

```bash
cp .env.example .env
$EDITOR .env  # Populate AWS_*, BEDROCK_*, and Lightsail values
scripts/check-deps.sh  # Installs/updates awscli v2, node/npm, php, jq, make, etc.
```

Notes:
- The helper assumes WSL2 + Ubuntu and uses `sudo apt-get` as needed.
- Provide a Lightsail SSH key path that is accessible from WSL (e.g. `/mnt/c/Users/.../LightsailDefaultKey.pem`).

## 2. Bedrock Connectivity Test

```bash
make bedrock-test
```

What happens:
- Loads `.env` via `scripts/load-env.sh`.
- Runs `aws --version`, `aws sts get-caller-identity`, then executes `aws bedrock-agent-runtime retrieve-and-generate` using the prompt configured in `.env`.
- Fails fast if required IDs (model, knowledge base, agent) are missing.

## 3. Lightsail Deployment

```bash
make deploy-lightsail
```

During the run you will be prompted for any missing values (region, AZ, blueprint, bundle, key pair, domain, etc.).

The deployment script performs:
1. `aws lightsail create-instances` (skipped if the instance already exists).
2. Waits for the instance to enter `running` state and discovers its public IP.
3. Installs Apache + PHP 8, rsync, certbot, and related utilities.
4. Synchronizes the local repository to `${LIGHTSAIL_REMOTE_PATH}` (default `/var/www/playlist`).
5. Configures an Apache virtual host that exposes `public/`, forwarding the Bedrock environment variables.
6. Requests a Let's Encrypt certificate via `certbot --apache` when `LIGHTSAIL_DOMAIN` and `LIGHTSAIL_CERT_EMAIL` are provided.
7. Enables Lightsail auto-snapshots (02:00 UTC by default) and offers a manual snapshot helper.

Additional commands:

```bash
scripts/lightsail-deploy.sh provision    # Only create the VM
scripts/lightsail-deploy.sh sync         # Resync files + reload Apache
scripts/lightsail-deploy.sh tls          # Re-run certbot
scripts/lightsail-deploy.sh snapshot my-snapshot-name
scripts/lightsail-deploy.sh autosnapshot # Enable daily snapshots
```

## 4. Local Development + Git Publish

```bash
make dev         # php -S localhost:8000 -t public
make publish     # Pull --rebase, add, commit (prompt), push
```

The publish helper respects `GIT_REMOTE` and `GIT_BRANCH` from `.env`.

## 5. IAM Policy Samples

Reference policies live under `docs/iam-policies/`:
- `bedrock-agent-runtime-minimal.json` – minimum permissions for the Bedrock smoke test.
- `lightsail-deployment-minimal.json` – Lightsail + networking actions used by the deploy script.
- `sts-passrole-basic.json` – Optional STS permissions when assuming a deployment role.

Adjust resource ARNs to match your account before applying them.
