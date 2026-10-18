import importlib.util
import pathlib
import sqlite3
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('installer', pathlib.Path(__file__).resolve().parents[2] / 'native/desktop/install-local.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)


class LocalInstallationTest(unittest.TestCase):
    def test_process_cleanup_matches_only_our_exact_widget_executables(self):
        bundle = pathlib.Path('/Applications/RecruitFlow Widgets.app')
        listing = '\n'.join([
            '834 /Applications/RecruitFlow Widgets.app/Contents/PlugIns/RecruitFlowWidgetExtension.appex/Contents/MacOS/RecruitFlowWidgetExtension',
            '1091 /Applications/RecruitFlow Widgets.app/Contents/MacOS/RecruitFlow Widgets',
            '222 /Applications/RecruitFlow.app/Contents/MacOS/RecruitFlow',
            '333 /other/project/RecruitFlow Widgets.app/Contents/MacOS/RecruitFlow Widgets',
            '444 /Applications/RecruitFlow Widgets.app/Contents/MacOS/RecruitFlow Widgets Helper',
            '1 /Applications/RecruitFlow Widgets.app/Contents/MacOS/RecruitFlow Widgets',
        ])
        self.assertEqual(installer.widget_process_ids(listing, [bundle]), [834, 1091])

    def test_sqlite_backup_preserves_all_records_and_leaves_original_unchanged(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            source, destination = root / 'source.sqlite', root / 'installed/database.sqlite'
            with sqlite3.connect(source) as db:
                for table in ['offers', 'applications', 'recruitment_events', 'integration_accounts', 'data_sources']:
                    db.execute(f'CREATE TABLE {table} (id TEXT PRIMARY KEY)')
                    db.execute(f'INSERT INTO {table} VALUES (?)', ('stable-fixture-id',))
            original = source.read_bytes()
            installer.migrate_empty_database(source, destination, root / 'backups')
            self.assertEqual(source.read_bytes(), original)
            with sqlite3.connect(destination) as db:
                for table in ['offers', 'applications', 'recruitment_events', 'integration_accounts', 'data_sources']:
                    self.assertEqual(db.execute(f'SELECT id FROM {table}').fetchall(), [('stable-fixture-id',)])

    def test_an_existing_installed_database_is_never_replaced(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            source, destination = root / 'source.sqlite', root / 'installed.sqlite'
            source.touch()
            with sqlite3.connect(destination) as db:
                db.execute('CREATE TABLE preserved_history (id TEXT)')
                db.execute('INSERT INTO preserved_history VALUES (?)', ('keep-this',))
            original = destination.read_bytes()
            self.assertEqual(installer.migrate_empty_database(source, destination, root / 'backups'), 'Existing installed database preserved')
            self.assertEqual(destination.read_bytes(), original)


if __name__ == '__main__':
    unittest.main()
