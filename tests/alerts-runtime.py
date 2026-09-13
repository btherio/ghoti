"""Unhandled errors stay private and reach the critical log; sends no mail."""
from pathlib import Path
import subprocess
import tempfile
root = Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='ghoti-runtime-test-') as tmp:
    for label, code, expected in [
        ('exception', "throw new RuntimeException('private-test-detail');", 'runtime:unhandled'),
        ('fatal', "trigger_error('private-test-detail', E_USER_ERROR);", 'runtime:fatal'),
    ]:
        log = Path(tmp) / (label + '.log')
        script = Path(tmp) / (label + '.php')
        script.write_text("<?php\nrequire 'ghoti.php';\nghoti::$enableCriticalAlerts=false;\n"
            + "ghoti::$ghotiLog='" + str(log) + "';\nghoti_install_error_handlers();\n" + code)
        result = subprocess.run(['php', str(script)], cwd=root, capture_output=True, text=True)
        assert result.returncode != 0, label + ' lost failure exit code'
        assert 'private-test-detail' not in result.stdout, label + ' disclosed raw error to visitor'
        assert expected in log.read_text(), label + ' was not logged'
        print('PASS runtime', label)
