"""A disabled watcher must exit before module/database/mail/helper initialization."""
from pathlib import Path
import os
import subprocess
import tempfile
root = Path(__file__).resolve().parent.parent
def php_literal(value):
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"
with tempfile.TemporaryDirectory(prefix='ghoti-watcher-test-') as tmp:
    settings = Path(tmp) / 'settings.json'
    settings.write_text('{"enableVhosts":false}')
    script = Path(tmp) / 'check.php'
    script.write_text("<?php\nrequire 'ghoti.php';\nghoti::$settingsFile="
        + php_literal(os.path.relpath(settings, root)) + ";\n"
        + "register_shutdown_function(function(){if(class_exists('mail',false) || class_exists('vhostsdb',false)){exit(9);}});\n"
        + "require 'mod/vhosts/vhosts.certwatch.php';")
    for args in [[], ['--quiet']]:
        run = subprocess.run(['php', str(script), *args], cwd=root, capture_output=True, text=True)
        assert run.returncode == 0, run.stderr
        assert not run.stderr, run.stderr
        assert (run.stdout == '') if args else ('monitoring skipped' in run.stdout)
    print('PASS: disabled certificate watcher exits before DB, mail or helper initialization')
