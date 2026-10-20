import { execFileSync } from "node:child_process";
import { appendFileSync, readFileSync } from "node:fs";
import { pathToFileURL } from "node:url";

const docExactFiles = new Set([
  "AGENTS.md",
  "README.md",
  "LICENSE",
  ".editorconfig",
  ".gitignore",
  ".gitattributes",
  ".env.example"
]);

const docPrefixes = [
  "docs/",
  "docs-dev/",
  ".githooks/"
];

const infraExactFiles = new Set([
  "Dockerfile",
  ".dockerignore",
  ".gitmodules",
  "deploy.sh",
  "init.sh",
  "update.sh"
]);

const infraPrefixes = [
  ".github/",
  ".docker/",
  "compose.",
  "deploy/diagnostics/",
  "scripts/diagnostics/"
];

const dbOrPlatformExactFiles = new Set([
  "composer.json",
  "composer.lock",
  "config/database.php",
  "phpunit.xml",
  "phpstan.neon",
  "phpstan-baseline.neon"
]);

const dbOrPlatformPrefixes = [
  "database/",
  "app/Models/"
];

const uiOrAssetExactFiles = new Set([
  "package.json",
  "package-lock.json",
  "config/app.php"
]);

const uiOrAssetPrefixes = [
  "theme/",
  "public/",
  "resources/",
  "tests/Browser/"
];

const coreAppExactFiles = new Set([
  "artisan"
]);

const coreAppPrefixes = [
  "app/",
  "bootstrap/",
  "config/",
  "plugins/",
  "plugins-core/",
  "routes/",
  "storage/",
  "tests/"
];

function matchesPrefix(file, prefixes) {
  return prefixes.some((prefix) => file === prefix || file.startsWith(prefix));
}

export function isDocFile(file) {
  if (docExactFiles.has(file)) return true;
  if (matchesPrefix(file, docPrefixes)) return true;
  if (file.endsWith(".md") && !file.startsWith("resources/") && !file.startsWith("public/") && !file.startsWith("theme/")) {
    return true;
  }
  return false;
}

export function isInfraOrDeployFile(file) {
  return infraExactFiles.has(file) || matchesPrefix(file, infraPrefixes);
}

export function isDbOrPlatformFile(file) {
  return dbOrPlatformExactFiles.has(file) || matchesPrefix(file, dbOrPlatformPrefixes);
}

export function isUiOrAssetFile(file) {
  return uiOrAssetExactFiles.has(file) || matchesPrefix(file, uiOrAssetPrefixes);
}

export function isCoreAppFile(file) {
  return coreAppExactFiles.has(file) || matchesPrefix(file, coreAppPrefixes);
}

export function fullL3Scope(reason = "full_release_and_infra_gate", unknownPaths = false) {
  return {
    tier: "L3",
    reason,
    unknown_paths: String(unknownPaths),
    run_php_core: "true",
    run_mysql_compat: "true",
    run_php84_compat: "true",
    run_browser_regression: "true",
    run_heavy_deploy_tests: "true",
    run_pr_image_build: "true"
  };
}

/**
 * Analyze changed files and event context to determine the L0-L3 smart CI gate scope.
 */
export function analyzeCiScope(rawFiles, context = {}) {
  const eventName = context.eventName ?? "";
  const ref = context.ref ?? "";
  const isDevelopFfSync = Boolean(context.isDevelopFfSync);

  // Rule 1: Production main push or workflow_dispatch ALWAYS runs full L3 verification
  if (ref === "refs/heads/main" || eventName === "workflow_dispatch") {
    return fullL3Scope("production_main_or_dispatch_full_gate", false);
  }

  // Rule 2: Fast-forward sync of develop to origin/main after a PR merge
  if (eventName === "push" && ref === "refs/heads/develop" && isDevelopFfSync) {
    return {
      tier: "L0",
      reason: "develop_fast_forward_sync_to_main",
      unknown_paths: "false",
      run_php_core: "false",
      run_mysql_compat: "false",
      run_php84_compat: "false",
      run_browser_regression: "false",
      run_heavy_deploy_tests: "false",
      run_pr_image_build: "false"
    };
  }

  const files = (rawFiles ?? [])
    .map((f) => f.trim().replace(/\\/g, "/"))
    .filter((f) => f !== "");

  // Rule 3: Empty diff fails closed to L3
  if (files.length === 0) {
    return fullL3Scope("empty_diff_fail_closed", true);
  }

  let hasUnknown = false;
  for (const f of files) {
    const known =
      isDocFile(f) ||
      isInfraOrDeployFile(f) ||
      isDbOrPlatformFile(f) ||
      isUiOrAssetFile(f) ||
      isCoreAppFile(f);
    if (!known) {
      hasUnknown = true;
    }
  }

  if (hasUnknown) {
    return fullL3Scope("unknown_path_fail_closed", true);
  }

  const hasInfra = files.some((f) => isInfraOrDeployFile(f));
  if (hasInfra) {
    return fullL3Scope("infra_or_deployment_files_modified", false);
  }

  const hasDbOrPlatform = files.some((f) => isDbOrPlatformFile(f));
  const hasUiOrAsset = files.some((f) => isUiOrAssetFile(f));
  const hasCoreApp = files.some((f) => isCoreAppFile(f));

  const runPhpCore = hasCoreApp || hasDbOrPlatform || hasUiOrAsset;
  const runMysqlCompat = hasDbOrPlatform;
  const runPhp84Compat = hasDbOrPlatform;
  const runBrowserRegression = hasUiOrAsset;
  const runPrImageBuild = hasUiOrAsset || hasDbOrPlatform;

  let tier = "L0";
  let reason = "docs_or_governance_only";
  if (runMysqlCompat || runPhp84Compat || runBrowserRegression) {
    tier = "L2";
    reason = "cross_boundary_db_or_ui_scope";
  } else if (runPhpCore) {
    tier = "L1";
    reason = "localized_application_code_scope";
  }

  return {
    tier,
    reason,
    unknown_paths: "false",
    run_php_core: String(runPhpCore),
    run_mysql_compat: String(runMysqlCompat),
    run_php84_compat: String(runPhp84Compat),
    run_browser_regression: String(runBrowserRegression),
    run_heavy_deploy_tests: "false",
    run_pr_image_build: String(runPrImageBuild)
  };
}

function readEventPayload(env = process.env) {
  const p = env.GITHUB_EVENT_PATH ?? "";
  if (!p) return null;
  try {
    return JSON.parse(readFileSync(p, "utf8"));
  } catch {
    return null;
  }
}

export function detectDevelopFastForwardSync(env = process.env) {
  if (env.GITHUB_EVENT_NAME !== "push" || env.GITHUB_REF !== "refs/heads/develop") {
    return false;
  }
  try {
    const headSha = execFileSync("git", ["rev-parse", "HEAD"], {
      encoding: "utf8",
      stdio: ["ignore", "pipe", "ignore"]
    }).trim();
    const mainSha = execFileSync("git", ["rev-parse", "origin/main"], {
      encoding: "utf8",
      stdio: ["ignore", "pipe", "ignore"]
    }).trim();
    if (headSha && mainSha && headSha === mainSha) {
      return true;
    }
    execFileSync("git", ["merge-base", "--is-ancestor", "origin/main", "HEAD"], {
      encoding: "utf8",
      stdio: ["ignore", "pipe", "ignore"]
    });
    const treeDiff = execFileSync("git", ["diff", "--name-only", "origin/main", "HEAD"], {
      encoding: "utf8",
      stdio: ["ignore", "pipe", "ignore"]
    })
      .split(/\r?\n/)
      .filter((f) => f.trim() !== "");
    return treeDiff.length === 0;
  } catch {
    return false;
  }
}

export function getChangedFiles(env = process.env) {
  const eventName = env.GITHUB_EVENT_NAME ?? "";
  const ref = env.GITHUB_REF ?? "";
  const event = readEventPayload(env);

  if (eventName === "pull_request") {
    const baseRef = env.GITHUB_BASE_REF || "main";
    try {
      execFileSync("git", ["rev-parse", "--verify", "HEAD^2"], {
        encoding: "utf8",
        stdio: ["ignore", "pipe", "ignore"]
      });
      const mergedOut = execFileSync("git", ["diff", "--name-only", "HEAD^1..HEAD"], {
        encoding: "utf8",
        stdio: ["ignore", "pipe", "inherit"]
      });
      const mergedFiles = mergedOut.split(/\r?\n/).filter((f) => f.trim() !== "");
      if (mergedFiles.length > 0) return mergedFiles;
    } catch {
      // Fall through to origin/${baseRef}...HEAD
    }
    try {
      const out = execFileSync("git", ["diff", "--name-only", `origin/${baseRef}...HEAD`], {
        encoding: "utf8",
        stdio: ["ignore", "pipe", "inherit"]
      });
      return out.split(/\r?\n/).filter((f) => f.trim() !== "");
    } catch {
      return [];
    }
  }

  if (ref === "refs/heads/develop") {
    try {
      const branchOut = execFileSync("git", ["diff", "--name-only", "origin/main...HEAD"], {
        encoding: "utf8",
        stdio: ["ignore", "pipe", "ignore"]
      });
      const branchFiles = branchOut.split(/\r?\n/).filter((f) => f.trim() !== "");
      if (branchFiles.length > 0) {
        return branchFiles;
      }
    } catch {
      // Fall through to before..HEAD fallback
    }
  }

  const before = env.GITHUB_EVENT_BEFORE || event?.before || "";
  if (before && before !== "0000000000000000000000000000000000000000") {
    try {
      const out = execFileSync("git", ["diff", "--name-only", `${before}..HEAD`], {
        encoding: "utf8",
        stdio: ["ignore", "pipe", "inherit"]
      });
      return out.split(/\r?\n/).filter((f) => f.trim() !== "");
    } catch {
      // Fallback below
    }
  }

  try {
    const out = execFileSync("git", ["diff", "--name-only", "HEAD~1..HEAD"], {
      encoding: "utf8",
      stdio: ["ignore", "pipe", "inherit"]
    });
    return out.split(/\r?\n/).filter((f) => f.trim() !== "");
  } catch {
    return [];
  }
}

function parseCliOverrides(argv = process.argv.slice(2)) {
  const overrides = {};
  for (const arg of argv) {
    if (arg.startsWith("--files=")) {
      overrides.files = arg
        .slice("--files=".length)
        .split(",")
        .map((s) => s.trim())
        .filter(Boolean);
    } else if (arg.startsWith("--event-name=")) {
      overrides.eventName = arg.slice("--event-name=".length).trim();
    } else if (arg.startsWith("--ref=")) {
      overrides.ref = arg.slice("--ref=".length).trim();
    }
  }
  return overrides;
}

function main() {
  const cli = parseCliOverrides();
  const eventName = cli.eventName ?? process.env.GITHUB_EVENT_NAME ?? "";
  const ref = cli.ref ?? process.env.GITHUB_REF ?? "";
  const envWithOverrides = {
    ...process.env,
    GITHUB_EVENT_NAME: eventName,
    GITHUB_REF: ref
  };
  const isDevelopFfSync = cli.files ? false : detectDevelopFastForwardSync(envWithOverrides);
  const files = cli.files ?? getChangedFiles(envWithOverrides);
  const scope = analyzeCiScope(files, {
    eventName,
    ref,
    isDevelopFfSync
  });

  process.stdout.write(`Detected changed files (${files.length}):\n`);
  for (const f of files) {
    process.stdout.write(`  - ${f}\n`);
  }
  process.stdout.write(`Computed Smart CI Gate Scope:\n`);
  for (const [k, v] of Object.entries(scope)) {
    process.stdout.write(`  ${k}=${v}\n`);
  }

  if (process.env.GITHUB_OUTPUT) {
    const lines = Object.entries(scope)
      .map(([k, v]) => `${k}=${v}\n`)
      .join("");
    appendFileSync(process.env.GITHUB_OUTPUT, lines, { encoding: "utf8" });
  }

  if (process.env.GITHUB_STEP_SUMMARY) {
    const summary = [
      `### Smart CI Gate Scope (\`${scope.tier}\` — \`${scope.reason}\`)`,
      "",
      "| Check Domain | Scheduled |",
      "| :--- | :---: |",
      `| Syntax, Manifest & Companion JS | \`true\` (Mandatory) |`,
      `| PHP 8.3 Core (Composer Audit + PHPStan + SQLite PHPUnit) | \`${scope.run_php_core}\` |`,
      `| MySQL 5.7 & 8.4 Compatibility Regression | \`${scope.run_mysql_compat}\` |`,
      `| PHP 8.4 Platform & Regression | \`${scope.run_php84_compat}\` |`,
      `| Playwright Browser Regression | \`${scope.run_browser_regression}\` |`,
      `| Heavy Docker/Retention Sandbox Suites | \`${scope.run_heavy_deploy_tests}\` |`,
      `| PR Candidate Image Build | \`${scope.run_pr_image_build}\` |`,
      ""
    ].join("\n");
    appendFileSync(process.env.GITHUB_STEP_SUMMARY, summary, { encoding: "utf8" });
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main();
}
