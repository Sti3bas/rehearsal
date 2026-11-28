import { readFile, stat } from "node:fs/promises";
import { join } from "node:path";

export const protocol = 2;
export const markerHeader = "X-Rehearsal-Attempt";
export const markerCookie = "rehearsal_attempt";
export const capabilityHeader = "X-Rehearsal-Capability";

export function privateLaunchPath(root: string, port: number) {
  return join(root, ".rehearsal", "launch-" + port + ".json");
}

export async function managedLaunch(root: string, port: number): Promise<{ run: string; capability: string }> {
  const path = privateLaunchPath(root, port);
  const info = await stat(path);
  if ((info.mode & 0o077) || !info.isFile() || info.uid !== process.getuid?.()) {
    throw new Error("Rehearsal launch record must be private and owned by this user.");
  }

  const launch = JSON.parse(await readFile(path, "utf8"));
  if (!/^[a-f0-9]{24}$/.test(launch.run) || !/^[a-f0-9]{64}$/.test(launch.capability)) {
    throw new Error("Invalid Rehearsal launch record.");
  }
  return launch;
}

export function loopback(url: URL) {
  return url.protocol === "http:" && ["127.0.0.1", "localhost", "[::1]"].includes(url.hostname)
    && !url.username && !url.password;
}
