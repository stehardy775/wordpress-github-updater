#!/usr/bin/env python3
"""Convert pip-audit JSON output to SARIF 2.1.0 for GitHub code scanning.

pip-audit has no native SARIF format, so we run it with `-f json` and map each
vulnerability to a SARIF result located on the offending line of the
requirements file.

Usage:
    pip_audit_to_sarif.py <audit_json> <requirements_file> <output_sarif> <artifact_uri>

<artifact_uri> is the repo-root-relative path GitHub should attribute findings
to, e.g. "proxy/requirements.txt".
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path


def _normalise(name: str) -> str:
    return re.sub(r"[-_.]+", "-", name).lower()


def _line_map(requirements: Path) -> dict[str, int]:
    """Maps a normalised package name to its 1-based line in the requirements file."""
    mapping: dict[str, int] = {}
    if not requirements.exists():
        return mapping
    for i, raw in enumerate(requirements.read_text(encoding="utf-8").splitlines(), start=1):
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        # Strip extras/specifiers: "fastapi[all]>=1,<2" -> "fastapi"
        name = re.split(r"[<>=!~;\[ ]", line, maxsplit=1)[0]
        if name:
            mapping.setdefault(_normalise(name), i)
    return mapping


def _dependencies(audit: object) -> list[dict]:
    # Newer pip-audit: {"dependencies": [...]}; older: a bare list.
    if isinstance(audit, dict):
        return audit.get("dependencies", [])
    if isinstance(audit, list):
        return audit
    return []


def main() -> int:
    if len(sys.argv) != 5:
        print(__doc__)
        return 2

    audit_path, req_path, out_path, artifact_uri = sys.argv[1:5]

    # Tolerate a missing/empty input (e.g. pip-audit hard-errored) by emitting a
    # valid, empty SARIF so the upload step still succeeds.
    audit_file = Path(audit_path)
    if audit_file.exists() and audit_file.stat().st_size > 0:
        audit = json.loads(audit_file.read_text(encoding="utf-8"))
    else:
        audit = {"dependencies": []}
    lines = _line_map(Path(req_path))

    rules: dict[str, dict] = {}
    results: list[dict] = []

    for dep in _dependencies(audit):
        name = dep.get("name", "")
        version = dep.get("version", "")
        line = lines.get(_normalise(name), 1)

        for vuln in dep.get("vulns", []) or []:
            vid = vuln.get("id", "UNKNOWN")
            aliases = vuln.get("aliases", []) or []
            fixes = vuln.get("fix_versions", []) or []
            description = (vuln.get("description") or "").strip()

            alias_str = f" ({', '.join(aliases)})" if aliases else ""
            fix_str = f" Fix: upgrade to {', '.join(fixes)}." if fixes else " No fixed version available."
            summary = f"{name} {version}: {vid}{alias_str}.{fix_str}"

            if vid not in rules:
                rules[vid] = {
                    "id": vid,
                    "name": vid,
                    "shortDescription": {"text": f"{vid}: vulnerable dependency"},
                    "fullDescription": {"text": description or summary},
                    "helpUri": f"https://osv.dev/vulnerability/{vid}",
                    "properties": {"tags": ["security", "dependencies"]},
                    "defaultConfiguration": {"level": "error"},
                }

            results.append(
                {
                    "ruleId": vid,
                    "level": "error",
                    "message": {"text": summary + (f"\n\n{description}" if description else "")},
                    "locations": [
                        {
                            "physicalLocation": {
                                "artifactLocation": {"uri": artifact_uri},
                                "region": {"startLine": line},
                            }
                        }
                    ],
                    "partialFingerprints": {"vulnId": f"{_normalise(name)}@{version}:{vid}"},
                }
            )

    sarif = {
        "$schema": "https://json.schemastore.org/sarif-2.1.0.json",
        "version": "2.1.0",
        "runs": [
            {
                "tool": {
                    "driver": {
                        "name": "pip-audit",
                        "informationUri": "https://github.com/pypa/pip-audit",
                        "rules": list(rules.values()),
                    }
                },
                "results": results,
            }
        ],
    }

    Path(out_path).write_text(json.dumps(sarif, indent=2), encoding="utf-8")
    print(f"Wrote {out_path}: {len(results)} result(s), {len(rules)} rule(s).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
