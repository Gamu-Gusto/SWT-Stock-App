"""Turn test failures / logs into GitHub annotations so they are readable without downloading job logs.

Usage:
  python .github/scripts/annotate.py junit <junit.xml>   # one annotation per failing test (max 10)
  python .github/scripts/annotate.py text <title>        # stdin -> a single multi-line annotation
"""
import sys
import xml.etree.ElementTree as ET


def escape(text: str) -> str:
    return text.replace('%', '%25').replace('\r', '').replace('\n', '%0A')


def emit(title: str, message: str) -> None:
    print(f"::error title={escape(title)[:200]}::{escape(message[-3500:])}")


mode = sys.argv[1]
if mode == 'junit':
    try:
        root = ET.parse(sys.argv[2]).getroot()
    except Exception as exc:  # no report written (e.g. PHPUnit crashed before running tests)
        emit('PHPUnit report missing', str(exc))
        sys.exit(0)
    count = 0
    for case in root.iter('testcase'):
        for kind in ('failure', 'error'):
            node = case.find(kind)
            if node is not None and count < 10:
                name = f"{case.get('class', '').split('.')[-1]}::{case.get('name')}"
                emit(name, (node.get('message') or '') + '\n' + (node.text or ''))
                count += 1
    if count == 0:
        emit('No failing test cases found in JUnit report', 'Check the job log.')
else:
    emit(sys.argv[2], sys.stdin.read())
