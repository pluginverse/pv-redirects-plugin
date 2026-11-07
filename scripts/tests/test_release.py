import importlib.util
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('release', Path(__file__).resolve().parents[1] / 'release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)

class ReleaseTest(unittest.TestCase):
    def test_notes_extracts_only_requested_release(self):
        text = '## [Unreleased]\n\n## [1.2.4] - 2026-11-07\n\n### Fixed\n\n- Repair redirects.\n\n## [1.2.3] - 2026-11-01\n\n- Old.\n'
        self.assertEqual(release.notes(text, '1.2.4'), '### Fixed\n\n- Repair redirects.')
        with self.assertRaises(ValueError):
            release.notes(text, '1.2.5')

    def test_prepare_synchronizes_metadata_and_release_notes(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            files = {
                'CHANGELOG.md': '## [Unreleased]\n\n### Fixed\n\n- Repair.\n\n## [1.0.0] - 2026-01-01\n\n- Initial.\n',
                'pv-redirects.php': " * Version: 1.0.0\ndefine( 'PV_REDIRECTS_VERSION', '1.0.0' );\n",
                'readme.txt': 'Stable tag: 1.0.0\n== Changelog ==\n\n= 1.0.0 =\n* Initial.\n',
                'package.json': '{"version": "1.0.0"}',
                'package-lock.json': '{"version": "1.0.0", "packages": {"": {"version": "1.0.0"}}}',
            }
            for name, content in files.items():
                (root / name).write_text(content)
            release.prepare(root, '1.0.1', '2026-11-07')
            self.assertEqual(release.notes((root / 'CHANGELOG.md').read_text(), '1.0.1'), '### Fixed\n\n- Repair.')
            self.assertIn('= 1.0.1 =\nFixed:\n\n* Repair.', (root / 'readme.txt').read_text())
            for name in ('pv-redirects.php', 'package.json', 'package-lock.json'):
                self.assertNotIn('1.0.0', (root / name).read_text())
            with self.assertRaises(ValueError):
                release.prepare(root, '1.0.1', '2026-11-07')

    def test_empty_unreleased_is_rejected_without_writes(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / 'CHANGELOG.md').write_text('## [Unreleased]\n\n## [1.0.0] - 2026-01-01\n')
            with self.assertRaises(ValueError):
                release.prepare(root, '1.0.1', '2026-11-07')
            self.assertIn('[Unreleased]', (root / 'CHANGELOG.md').read_text())
