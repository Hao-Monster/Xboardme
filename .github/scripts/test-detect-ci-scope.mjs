import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import test from "node:test";

import { analyzeCiScope } from "./detect-ci-scope.mjs";

test("main push or workflow_dispatch always enforces full L3 verification regardless of changed files", () => {
  const mainScope = analyzeCiScope(["README.md"], {
    eventName: "push",
    ref: "refs/heads/main"
  });
  assert.equal(mainScope.tier, "L3");
  assert.equal(mainScope.run_php_core, "true");
  assert.equal(mainScope.run_mysql_compat, "true");
  assert.equal(mainScope.run_php84_compat, "true");
  assert.equal(mainScope.run_browser_regression, "true");
  assert.equal(mainScope.run_heavy_deploy_tests, "true");

  const dispatchScope = analyzeCiScope(["README.md"], {
    eventName: "workflow_dispatch",
    ref: "refs/heads/develop"
  });
  assert.equal(dispatchScope.tier, "L3");
  assert.equal(dispatchScope.run_heavy_deploy_tests, "true");
});

test("develop fast-forward sync to origin/main uses L0 fast path", () => {
  const ffScope = analyzeCiScope(["app/Services/ServerService.php"], {
    eventName: "push",
    ref: "refs/heads/develop",
    isDevelopFfSync: true
  });
  assert.equal(ffScope.tier, "L0");
  assert.equal(ffScope.reason, "develop_fast_forward_sync_to_main");
  assert.equal(ffScope.run_php_core, "false");
  assert.equal(ffScope.run_heavy_deploy_tests, "false");
});

test("pure documentation changes on PR or develop use L0 fast path", () => {
  const docScope = analyzeCiScope(["README.md", "AGENTS.md", "docs/guide.md"], {
    eventName: "pull_request",
    ref: "refs/pull/91/merge"
  });
  assert.equal(docScope.tier, "L0");
  assert.equal(docScope.run_php_core, "false");
  assert.equal(docScope.run_mysql_compat, "false");
  assert.equal(docScope.run_browser_regression, "false");
  assert.equal(docScope.run_heavy_deploy_tests, "false");
  assert.equal(docScope.run_pr_image_build, "false");
});

test("localized PHP service or controller fix uses L1 fast gate (PHP 8.3 + SQLite + PHPStan + JS unit, skips heavy Docker/MySQL/Browser)", () => {
  const l1Scope = analyzeCiScope(
    ["app/Services/ServerService.php", "tests/Feature/ServerSyncTest.php"],
    { eventName: "pull_request", ref: "refs/pull/91/merge" }
  );
  assert.equal(l1Scope.tier, "L1");
  assert.equal(l1Scope.run_php_core, "true");
  assert.equal(l1Scope.run_mysql_compat, "false");
  assert.equal(l1Scope.run_php84_compat, "false");
  assert.equal(l1Scope.run_browser_regression, "false");
  assert.equal(l1Scope.run_heavy_deploy_tests, "false");
  assert.equal(l1Scope.run_pr_image_build, "false");
});

test("UI/theme/asset changes escalate to L2 with Playwright browser regression and PR image build", () => {
  const uiScope = analyzeCiScope(["theme/Xboard/assets/distributor.js"], {
    eventName: "pull_request",
    ref: "refs/pull/91/merge"
  });
  assert.equal(uiScope.tier, "L2");
  assert.equal(uiScope.run_php_core, "true");
  assert.equal(uiScope.run_browser_regression, "true");
  assert.equal(uiScope.run_pr_image_build, "true");
  assert.equal(uiScope.run_mysql_compat, "false");
  assert.equal(uiScope.run_heavy_deploy_tests, "false");
});

test("database migration or model changes escalate to L2 with MySQL 5.7/8.4 and PHP 8.4 compatibility", () => {
  const dbScope = analyzeCiScope(["database/migrations/2026_10_10_000001_test.php"], {
    eventName: "pull_request",
    ref: "refs/pull/91/merge"
  });
  assert.equal(dbScope.tier, "L2");
  assert.equal(dbScope.run_php_core, "true");
  assert.equal(dbScope.run_mysql_compat, "true");
  assert.equal(dbScope.run_php84_compat, "true");
  assert.equal(dbScope.run_heavy_deploy_tests, "false");
});

test("workflow, Dockerfile, or unknown path changes escalate to L3 full regression", () => {
  const ciScope = analyzeCiScope([".github/workflows/docker-publish.yml"], {
    eventName: "pull_request",
    ref: "refs/pull/91/merge"
  });
  assert.equal(ciScope.tier, "L3");
  assert.equal(ciScope.run_heavy_deploy_tests, "true");

  const unknownScope = analyzeCiScope(["unknown-root-binary.bin"], {
    eventName: "pull_request",
    ref: "refs/pull/91/merge"
  });
  assert.equal(unknownScope.tier, "L3");
  assert.equal(unknownScope.unknown_paths, "true");

  const emptyScope = analyzeCiScope([], {
    eventName: "pull_request",
    ref: "refs/pull/91/merge"
  });
  assert.equal(emptyScope.tier, "L3");
  assert.equal(emptyScope.unknown_paths, "true");
});

test("diagnostics deployment files are known infrastructure and enforce the full L3 gate", () => {
  const files = [
    "deploy/diagnostics/configure.py",
    "deploy/diagnostics/guard.py",
    "deploy/diagnostics/diagnostics_test.py",
    "scripts/diagnostics/preflight-storage.sh"
  ];
  for (const context of [
    { eventName: "pull_request", ref: "refs/pull/93/merge" },
    { eventName: "push", ref: "refs/heads/develop" }
  ]) {
    for (const file of files) {
      const scope = analyzeCiScope([file], context);
      assert.equal(scope.tier, "L3", file);
      assert.equal(scope.unknown_paths, "false", file);
      for (const key of ["run_php_core", "run_mysql_compat", "run_php84_compat", "run_browser_regression", "run_heavy_deploy_tests", "run_pr_image_build"]) {
        assert.equal(scope[key], "true", `${file}: ${key}`);
      }
    }
  }
});

test("every tracked file in Xboardme git ls-files is recognized without unknown_paths fallback", () => {
  const tracked = execFileSync("git", ["ls-files"], { encoding: "utf8" })
    .split(/\r?\n/)
    .filter(Boolean);
  assert.ok(tracked.length > 800);
  const unknowns = tracked.filter(
    (f) =>
      analyzeCiScope([f], {
        eventName: "pull_request",
        ref: "refs/pull/1/merge"
      }).unknown_paths === "true"
  );
  assert.deepEqual(unknowns, []);
});
