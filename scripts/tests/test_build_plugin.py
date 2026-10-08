"""配布内容と、ビルド失敗時の既存成果物保護を検証する。"""

from pathlib import Path
import shutil
import re
import subprocess
import tempfile
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[2]
SLUG = "od-visual-regression"


class PluginBuildTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        shutil.copytree(ROOT / "scripts", self.root / "scripts", ignore=shutil.ignore_patterns("__pycache__"))
        shutil.copytree(ROOT / "packages" / "schemas" / "src", self.root / "packages" / "schemas" / "src")
        self.plugin = self.root / "wordpress" / SLUG
        shutil.copytree(ROOT / "wordpress" / SLUG, self.plugin)
        self.version = re.search(r"Version:\s*(\S+)", (self.plugin / f"{SLUG}.php").read_text())[1]
        self.output = self.root / "build" / f"{SLUG}.zip"

    def write(self, name, content="fixture"):
        path = self.plugin / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)

    def run_build(self, *args):
        return subprocess.run(
            ["bash", str(self.root / "scripts/build-plugin.sh"), *args],
            cwd="/", capture_output=True, text=True,
        )

    def assert_preserved(self, *args):
        self.output.parent.mkdir(exist_ok=True)
        self.output.write_bytes(b"previous artifact")
        result = self.run_build(*args)
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertEqual(self.output.read_bytes(), b"previous artifact")
        self.assertEqual(list(self.output.parent.iterdir()), [self.output])
        return result

    def test_archive_has_single_root_and_runtime_files_only(self):
        runtime = ["assets/style.css", "assets/icon.svg", "rest/controller.php", "admin/controller.php"]
        excluded = [
            "node_modules/library/index.js", "vendor/library.php", "src/editor.js",
            "includes/tests/test.php", "includes/src/dev.php", "includes/.env.php",
            "assets/credentials.php", "assets/secret.json", "assets/style.css.map",
            "runner/index.php", "dispatcher/index.php", ".env", "private-key.pem",
        ]
        for name in runtime + excluded:
            self.write(name)
        result = self.run_build()
        self.assertEqual(result.returncode, 0, result.stderr)
        with zipfile.ZipFile(self.output) as archive:
            names = set(archive.namelist())
            self.assertTrue(all(name.startswith(f"{SLUG}/") for name in names))
            for name in runtime + [f"{SLUG}.php"]:
                self.assertIn(f"{SLUG}/{name}", names)
            for name in excluded + ["tests/bootstrap.php", "tests/content-api.php", "languages/README.md"]:
                self.assertNotIn(f"{SLUG}/{name}", names)
            for path in (ROOT / "wordpress" / SLUG / "includes").glob("*.php"):
                self.assertIn(f"{SLUG}/includes/{path.name}", names)
            for path in (self.plugin / "languages").iterdir():
                if path.suffix in {".mo", ".po", ".pot"}:
                    self.assertIn(f"{SLUG}/languages/{path.name}", names)
            for path in (ROOT / "packages" / "schemas" / "src").glob("*.schema.json"):
                self.assertEqual(archive.read(f"{SLUG}/schemas/{path.name}"), path.read_bytes())
            self.assertIsNone(archive.testzip())

    def test_missing_schemas_preserves_archive(self):
        shutil.rmtree(self.root / "packages" / "schemas" / "src")
        self.assert_preserved()

    def test_schema_symlink_preserves_archive(self):
        source = self.root / "packages" / "schemas" / "src"
        (source / "linked.schema.json").symlink_to(source / "error.schema.json")
        self.assert_preserved()

    def test_tag_matches_plugin_version(self):
        result = self.run_build("--tag", f"plugin-v{self.version}")
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_invalid_cli_preserves_archive(self):
        self.assert_preserved("--unknown")
        self.assert_preserved("--tag")
        self.assert_preserved("unexpected")

    def test_mismatched_tag_preserves_archive(self):
        self.assert_preserved("--tag", f"plugin-v{self.version}-mismatch")
        self.assert_preserved("--tag", f"runner-v{self.version}")

    def test_missing_or_invalid_entry_preserves_archive(self):
        entry = self.plugin / f"{SLUG}.php"
        entry.unlink()
        self.assert_preserved()
        entry.write_text("<?php // Version なし")
        self.assert_preserved()

    def test_missing_admin_build_preserves_archive(self):
        self.write("admin/src/index.js")
        result = self.assert_preserved()
        self.assertIn("build", result.stderr)
        self.write("admin/build/index.js")
        self.assert_preserved()
        self.write("admin/build/index.asset.php", "")
        self.assert_preserved()

    def test_admin_build_and_assets_are_included(self):
        self.write("admin/package.json", "{}")
        self.write("admin/src/index.js")
        for name in ["index.js", "index.asset.php", "index.css"]:
            self.write(f"admin/build/{name}")
        result = self.run_build()
        self.assertEqual(result.returncode, 0, result.stderr)
        with zipfile.ZipFile(self.output) as archive:
            for name in ["index.js", "index.asset.php", "index.css"]:
                self.assertIn(f"{SLUG}/admin/build/{name}", archive.namelist())
            self.assertNotIn(f"{SLUG}/admin/src/index.js", archive.namelist())
            self.assertNotIn(f"{SLUG}/admin/package.json", archive.namelist())

    def test_runtime_reference_requires_build(self):
        self.write("includes/admin.php", "<?php $path = 'admin/build/index.js';")
        self.assert_preserved()

    def test_symlink_preserves_archive(self):
        (self.plugin / "includes" / "linked.php").symlink_to(self.plugin / f"{SLUG}.php")
        self.assert_preserved()


if __name__ == "__main__":
    unittest.main()
