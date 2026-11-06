import importlib.util
import json
import shutil
import subprocess
import sys
import zipfile
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location("package", Path(__file__).resolve().parents[1] / "package.py")
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)


class PackageTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.addCleanup(self.temp.cleanup)
        for name, content in {"pv-redirects.php": " * Version: 1.2.3\n", "uninstall.php": "uninstall", "readme.txt": "Stable tag: 1.2.3\n", "LICENSE": "license", "composer.json": "{}", "package.json": json.dumps({"version": "1.2.3"}), "src/example.php": "php", "build/index.js": "js", "build/index.asset.php": "php", "build/sidebar.js": "js", "build/sidebar.asset.php": "php", "tests/private.php": "private", "vendor/private.php": "private"}.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)

    def test_allowed_files_and_tag(self):
        version, files = package.files_and_version(self.root, "v1.2.3")
        self.assertEqual(version, "1.2.3")
        self.assertIn("pv-redirects/src/example.php", files)
        self.assertFalse(any("tests/" in name or "vendor/" in name for name in files))
        self.assertIn("pv-redirects/uninstall.php", files)
        (self.root / "uninstall.php").unlink()
        with self.assertRaises(ValueError):
            package.files_and_version(self.root)
        (self.root / "uninstall.php").write_text("uninstall")
        with self.assertRaises(ValueError):
            package.files_and_version(self.root, "v1.2.4")
        with self.assertRaises(ValueError):
            package.files_and_version(self.root, "v1.2.3-rc.1")

    def test_hidden_directory_descendants_are_excluded(self):
        hidden = self.root / "src/.private/secret.php"
        hidden.parent.mkdir()
        hidden.write_text("secret")
        _, files = package.files_and_version(self.root)
        self.assertNotIn("pv-redirects/src/.private/secret.php", files)

    def test_cli_rejects_tampering_duplicate_and_traversal(self):
        script = self.root / "scripts/package.py"
        script.parent.mkdir()
        shutil.copy(package.__file__, script)
        archive = self.root / "release.zip"

        def run(*args):
            return subprocess.run([sys.executable, str(script), *map(str, args)], capture_output=True)

        self.assertEqual(run("--tag", "v1.2.3", "--output", archive).returncode, 0)
        self.assertEqual(run("--tag", "v1.2.3", "--verify", archive).returncode, 0)
        with zipfile.ZipFile(archive) as original:
            contents = {name: original.read(name) for name in original.namelist()}
        for kind in ("tampered", "duplicate", "traversal", "extra"):
            with self.subTest(kind=kind):
                with zipfile.ZipFile(archive, "w") as rewritten:
                    for name, data in contents.items():
                        if kind == "tampered" and name.endswith("uninstall.php"):
                            data = b"changed"
                        rewritten.writestr(name, data)
                    if kind == "duplicate":
                        with self.assertWarns(UserWarning):
                            rewritten.writestr("pv-redirects/uninstall.php", b"uninstall")
                    elif kind == "traversal":
                        rewritten.writestr("pv-redirects/../escape.php", b"escape")
                    elif kind == "extra":
                        rewritten.writestr("pv-redirects/extra.php", b"extra")
                self.assertNotEqual(run("--tag", "v1.2.3", "--verify", archive).returncode, 0)

    def test_mismatched_and_prerelease_versions_fail(self):
        for version in ("1.2.4", "1.2.3-rc.1"):
            (self.root / "package.json").write_text(json.dumps({"version": version}))
            with self.assertRaises(ValueError):
                package.files_and_version(self.root)


if __name__ == "__main__":
    unittest.main()
