#!/usr/bin/env node

/**
 * Adds the caller's current public IPv4 to a Scaleway Managed Database
 * instance's ACL allow list. Extracted from vvp_app_server's
 * add_ip_to_scaleway_allowlist.sh so any repo/teammate can run it via
 * `npx @volksverpetzer/whitelist-ip` instead of keeping a local copy.
 */

import os from 'node:os';

const IPV4_RE = /^(\d{1,3}\.){3}\d{1,3}$/;

function printHelp() {
  console.log(`whitelist-ip

Adds your current public IPv4 to a Scaleway Managed Database instance's
ACL allow list.

Required environment variables:
  SCALEWAY_API_KEY      Scaleway API secret key (X-Auth-Token)
  SCALEWAY_INSTANCE_ID  Managed Database (RDB) instance ID
  SCALEWAY_REGION       Scaleway region, e.g. fr-par

Usage:
  npx @volksverpetzer/whitelist-ip
  whitelist-ip --help
`);
}

function requireEnv(name) {
  const value = process.env[name];
  if (!value) {
    console.error(`Error: ${name} is required (set it in your shell profile).`);
    process.exit(1);
  }
  return value;
}

async function detectPublicIPv4() {
  const response = await fetch('https://api.ipify.org');
  if (!response.ok) {
    throw new Error(`Failed to detect public IP (HTTP ${response.status}).`);
  }
  const ip = (await response.text()).trim();
  if (!IPV4_RE.test(ip)) {
    throw new Error(`Unable to determine public IPv4 (got: '${ip}').`);
  }
  return ip;
}

async function addToAllowlist({ apiKey, instanceId, region, cidr }) {
  const endpoint = `https://api.scaleway.com/rdb/v1/regions/${region}/instances/${instanceId}/acls`;
  const description = `Added via whitelist-ip (${os.userInfo().username}@${os.hostname()})`;

  const response = await fetch(endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Auth-Token': apiKey
    },
    body: JSON.stringify({ rules: [{ ip: cidr, description }] })
  });

  const body = await response.text();
  if (!response.ok) {
    throw new Error(`Scaleway API error (${response.status}): ${body}`);
  }
}

async function main() {
  if (process.argv.includes('--help') || process.argv.includes('-h')) {
    printHelp();
    return;
  }

  const apiKey = requireEnv('SCALEWAY_API_KEY');
  const instanceId = requireEnv('SCALEWAY_INSTANCE_ID');
  const region = requireEnv('SCALEWAY_REGION');

  console.log('Detecting current public IPv4...');
  const ip = await detectPublicIPv4();
  const cidr = `${ip}/32`;

  console.log(`Adding ${cidr} to the allow list...`);
  await addToAllowlist({ apiKey, instanceId, region, cidr });

  console.log(`Successfully added ${cidr} to the allow list for instance ${instanceId} in ${region}.`);
}

main().catch((error) => {
  console.error(`Error: ${error.message}`);
  process.exit(1);
});
