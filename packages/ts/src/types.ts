import { execFile } from "node:child_process";
import { promisify } from "node:util";

export async function generateRehearsalTypes({ root = process.cwd(), path, environment, database }: {
  root?: string;
  path: string;
  environment?: string;
  database?: string;
}): Promise<void> {
  await promisify(execFile)("php", [
    "artisan", "rehearsal:types", "--no-interaction",
    ...(environment ? ["--env=" + environment] : []),
    ...(database ? ["--database=" + database] : []),
    "--", path,
  ], { cwd: root });
}
