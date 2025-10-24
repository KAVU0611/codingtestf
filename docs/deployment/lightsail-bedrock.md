# Lightsail + Bedrock AgentCore Deployment Guide

This guide walks through deploying the Playlist Browser app onto a hobby-scale AWS Lightsail instance and wiring it to Amazon Bedrock AgentCore. The end result is an always-on PHP site (served by Apache) that can call your Bedrock knowledge base via the AWS CLI.

## 0. Architecture Snapshot

- **Compute**: Lightsail Linux/Unix instance (e.g., $5/mo, 512 MB RAM, 1 vCPU).
- **Web stack**: Apache + PHP 8.2 (from the Lightsail LAMP blueprint).
- **Application**: Git checkout of `codingtest5`, served from `/opt/playlist`.
- **Bedrock access**: AWS CLI (v2) using an IAM user/role with `bedrock-agent-runtime:RetrieveAndGenerate`.
- **TLS**: Lightsail-managed HTTPS certificate (optional but recommended).
- **Backups**: Nightly automatic snapshots via Lightsail console.

## 1. Prerequisites

- AWS account with access to Amazon Bedrock in the target region (e.g., `ap-northeast-1`).
- A Bedrock knowledge base + inference profile ARN ready for use with `retrieve-and-generate`.
- IAM principal (user or role) with at minimum:
  - `bedrock-agent-runtime:RetrieveAndGenerate`
  - `bedrock:ListKnowledgeBases` (optional but helpful for debugging)
  - Access to the underlying knowledge base storage (for example S3 if applicable).
- Local machine with `aws` CLI configured (`aws configure --profile codex`).

## 2. Provision the Lightsail Instance

1. Open the [Lightsail console](https://lightsail.aws.amazon.com/).
2. Click **Create instance**.
3. Choose:
   - **Platform**: Linux/Unix
   - **Blueprint**: LAMP (PHP 8) (includes Apache, PHP, MariaDB)
   - **Instance plan**: Start with the $5/month plan (free tier eligible in many regions).
   - **Instance location**: Match your Bedrock region if possible (e.g., Tokyo `ap-northeast-1`).
4. Name the instance `playlist-bedrock`.
5. Create the instance and wait for it to reach the `Running` state.

## 3. Initial Server Setup

SSH into the instance using either the browser-based console or your preferred SSH client:

```bash
ssh -i /path/to/LightsailDefaultKey.pem ubuntu@<PUBLIC_IP>
```

Update the system and install helper packages:

```bash
sudo apt-get update
sudo apt-get upgrade -y
sudo apt-get install -y git unzip jq
```

Lightsail’s LAMP image already includes Apache, PHP, and the AWS CLI v2. Verify the CLI:

```bash
aws --version
```

If the CLI is missing, install it manually using the [official instructions](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html).

## 4. Deploy the PHP Application

1. Create a directory for the app and ensure Apache can read it:

   ```bash
   sudo mkdir -p /opt/playlist
   sudo chown ubuntu:www-data /opt/playlist
   sudo chmod 775 /opt/playlist
   ```

2. Clone the repository:

   ```bash
   cd /opt/playlist
   git clone https://github.com/KAVU0611/codingtest5.git .
   ```

3. Point Apache’s document root to the `public` folder. On the LAMP image, the default vhost file is `/opt/bitnami/apache/conf/vhosts/playground-php-vhost.conf`. Replace its contents with:

   ```apacheconf
   <VirtualHost *:80>
       ServerName playlist.local
       DocumentRoot "/opt/playlist/public"

       <Directory "/opt/playlist/public">
           AllowOverride None
           Require all granted
       </Directory>

       SetEnv AWS_REGION ap-northeast-1
       SetEnv BEDROCK_KNOWLEDGE_BASE_ID kb-xxxxxxxxxx
       SetEnv BEDROCK_MODEL_ARN arn:aws:bedrock:ap-northeast-1:123456789012:inference-profile/ip-xxxxxxxxxx
       SetEnv BEDROCK_PROFILE codex
   </VirtualHost>
   ```

   Adjust `ServerName`, region, and resource IDs to match your environment.

4. Restart Apache:

   ```bash
   sudo /opt/bitnami/ctlscript.sh restart apache
   ```

5. Test the site by browsing to `http://<PUBLIC_IP>/`. You should see the playlist UI.

## 5. Configure AWS Credentials

Use the profile-specific CLI configuration you set up earlier. You can either:

- Copy the `~/.aws` directory from your local machine to the server (e.g., via `scp`), **or**
- Run `aws configure --profile codex` directly on the server and provide the access key/secret you created for this deployment.

Restrict permissions tightly (least privilege) and consider rotating keys periodically.

## 6. Validate Bedrock Connectivity

1. Export the required environment variables for your SSH session (Apache will already have them thanks to `SetEnv`, but the CLI in your shell needs them too):

   ```bash
   export AWS_PROFILE=codex
   export AWS_REGION=ap-northeast-1
   ```

2. Run a smoke test (replace IDs as needed):

   ```bash
   aws bedrock-agent-runtime retrieve-and-generate \
     --session-id test-session \
     --input '{"text":"Summarize the albums in my playlist"}' \
     --retrieve-and-generate-configuration '{
       "type": "KNOWLEDGE_BASE",
       "knowledgeBaseConfiguration": {
         "knowledgeBaseId": "kb-xxxxxxxxxx",
         "modelArn": "arn:aws:bedrock:ap-northeast-1:123456789012:inference-profile/ip-xxxxxxxxxx"
       }
     }'
   ```

3. If the command succeeds, load the web UI and submit a prompt in the “Ask Amazon Bedrock AgentCore” box. The response should match what the CLI produced.

## 7. Optional Hardening

- **HTTPS**: In the Lightsail console, attach a static IP and request a free SSL/TLS certificate. Update Apache to listen on 443 and redirect HTTP to HTTPS.
- **Systemd service**: If you prefer to serve with PHP’s built-in server instead of Apache, create a systemd unit that runs `php -S 0.0.0.0:8000 -t public`.
- **Backups**: Enable automatic snapshots in Lightsail > Instance > Snapshots.
- **Monitoring**: Configure basic CloudWatch alarms for CPU usage (Lightsail exposes metrics) and log shipping via `awslogs` if desired.

## 8. Cost Guardrails

- Lightsail instance ($5/mo) + static IP ($0 if attached) + optional managed certificate (free).
- Knowledge base storage incurs S3 charges; monitor bucket size and retrieval.
- Bedrock usage is pay-per-request—add service quotas or alerts if you expect bursts.

With this setup, you can experiment with Amazon Bedrock AgentCore while keeping monthly costs within a hobbyist budget. When you outgrow Lightsail, migrate the same codebase to ECS Fargate, Amplify Hosting, or a hardened EC2 stack with minimal changes.
