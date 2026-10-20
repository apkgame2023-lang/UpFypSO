#!/usr/bin/env python3
"""Y4APK FOSS mirror.

Copies legitimately redistributable open-source APKs from an F-Droid-format
repository (default: https://f-droid.org/repo) into a Cloudflare R2 bucket.
Every file is checked against the sha256/size published in the index and
virus-scanned before upload. APK contents are never modified; only the
object name changes.

Commands: stats, list, run, catalog.  Run "python foss_mirror.py -h".
"""
from __future__ import annotations

import argparse
import configparser
import csv
import hashlib
import json
import logging
import os
import re
import shutil
import subprocess
import sys
import threading
import time
import unicodedata
from collections import Counter
from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, wait
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, List, Optional
from urllib.parse import quote, urlparse

import requests

USER_AGENT = "Y4APK-foss-mirror/1.0"
SCRIPT_DIR = Path(__file__).resolve().parent
APK_MIME = "application/vnd.android.package-archive"
MB = 1024 * 1024
BACKOFF_BASE = 1.0  # seconds; tests set this to 0
DISK_RESERVE = 200 * MB
VT_API = "https://www.virustotal.com/api/v3"

log = logging.getLogger("foss_mirror")

# --------------------------------------------------------------------------
# Configuration
# --------------------------------------------------------------------------

DEFAULTS = {
    "source": {
        "repo": "https://f-droid.org/repo",
        "mirrors": "",
        "use_index_mirrors": "true",
        "timeout": "60",
        "retries": "4",
    },
    "filter": {
        "exclude_antifeatures": "KnownVuln, NoSourceSince, NonFreeAssets, Tracking, Ads, NSFW",
        "allow_any_license": "false",
        "extra_licenses": "",
        "preferred_abi": "arm64-v8a",
        "max_size_mb": "1024",
    },
    "naming": {
        "filename_template": "{name}-v{version}-Y4APK.COM.apk",
        "key_template": "{folder}/{filename}",
        "nonalpha_folder": "A",
    },
    "scan": {
        "scan": "clamav",
        "clamscan_path": "clamscan",
        "clamscan_args": "--max-filesize=1024M --max-scansize=1024M --max-scantime=600000",
        "clamscan_timeout": "900",
        "keep_quarantine": "false",
    },
    "virustotal": {
        "api_key": "",
        "upload_unknown": "true",
        "max_malicious": "2",
        "requests_per_minute": "4",
        "poll_interval": "30",
        "poll_timeout": "1200",
    },
    "dest": {
        "backend": "r2",
        "local_dir": "",
        "catalog_key": "catalog/foss-apps.json",
        "mirror_source": "true",
    },
    "r2": {
        "account_id": "",
        "access_key_id": "",
        "secret_access_key": "",
        "bucket": "files",
        "public_base_url": "https://files.y4apk.net",
    },
    "run": {
        "workers": "2",
        "work_dir": "work",
        "keep_files": "false",
    },
}

ENV_OVERRIDES = {
    ("r2", "account_id"): "R2_ACCOUNT_ID",
    ("r2", "access_key_id"): "R2_ACCESS_KEY_ID",
    ("r2", "secret_access_key"): "R2_SECRET_ACCESS_KEY",
    ("r2", "bucket"): "R2_BUCKET",
    ("r2", "public_base_url"): "R2_PUBLIC_BASE_URL",
    ("virustotal", "api_key"): "VT_API_KEY",
}
SECRET_OPTIONS = {
    ("r2", "account_id"),
    ("r2", "access_key_id"),
    ("r2", "secret_access_key"),
    ("virustotal", "api_key"),
}


class ConfigError(Exception):
    pass


class Config:
    """config.ini + environment variables + command-line overrides."""

    def __init__(self, path: Optional[Path] = None):
        self.cp = configparser.ConfigParser(interpolation=None, inline_comment_prefixes=(";", "#"))
        self.cp.read_dict(DEFAULTS)
        self.path = path
        if path and Path(path).is_file():
            try:
                with open(path, encoding="utf-8-sig") as fh:
                    self.cp.read_file(fh)
            except UnicodeDecodeError as e:
                raise ConfigError(f"{path} is not saved as UTF-8 ({e})\n"
                                  "ไฟล์ config.ini ต้องบันทึกเป็น UTF-8 (Notepad: Save as > Encoding: UTF-8)") from e
            except configparser.Error as e:
                raise ConfigError(f"{path} is malformed: {e}\n"
                                  "ไฟล์ config.ini เขียนผิดรูปแบบ (เช่น มีค่าเดียวกันซ้ำสองบรรทัด) แก้บรรทัดที่ระบุ") from e
        for (section, option), env in ENV_OVERRIDES.items():
            value = os.environ.get(env, "").strip()
            if value:
                self.cp.set(section, option, value)

    def get(self, section, option) -> str:
        return self.cp.get(section, option, fallback="").strip()

    def getint(self, section, option) -> int:
        return int(self.getfloat(section, option))

    def getfloat(self, section, option) -> float:
        value = self.get(section, option)
        try:
            return float(value or 0)
        except ValueError:
            raise ConfigError(f"[{section}] {option} must be a number, got {value!r}"
                              f" / ค่า {option} ในหมวด [{section}] ต้องเป็นตัวเลข") from None

    def getbool(self, section, option) -> bool:
        return self.get(section, option).lower() in ("1", "true", "yes", "on")

    def getlist(self, section, option) -> List[str]:
        return [x.strip() for x in re.split(r"[,\n]", self.get(section, option)) if x.strip()]

    def set(self, section, option, value):
        self.cp.set(section, option, str(value))

    def secrets(self) -> List[str]:
        return [self.get(s, o) for s, o in SECRET_OPTIONS if self.get(s, o)]

    def dump_masked(self) -> str:
        lines = []
        for section in self.cp.sections():
            lines.append(f"[{section}]")
            for option, value in self.cp.items(section):
                if (section, option) in SECRET_OPTIONS and value:
                    value = "***"
                lines.append(f"  {option} = {value}")
        return "\n".join(lines)


class SecretMaskFilter(logging.Filter):
    """Replace secret values in log records with ***."""

    def __init__(self, secrets: List[str]):
        super().__init__()
        self.secrets = [s for s in secrets if len(s) >= 4]

    def filter(self, record):
        if self.secrets:
            msg = record.getMessage()
            for s in self.secrets:
                msg = msg.replace(s, "***")
            record.msg, record.args = msg, ()
        return True


def setup_logging(work_dir: Path, verbose: bool, secrets: List[str]):
    close_logging()
    log.setLevel(logging.DEBUG)
    log.propagate = False
    mask = SecretMaskFilter(secrets)
    console = logging.StreamHandler(sys.stderr)  # stdout stays clean for --json / tables
    console.setLevel(logging.DEBUG if verbose else logging.INFO)
    console.setFormatter(logging.Formatter("%(message)s"))
    console.addFilter(mask)
    log.addHandler(console)
    try:
        (work_dir / "logs").mkdir(parents=True, exist_ok=True)
        fh = logging.FileHandler(work_dir / "logs" / "foss-mirror.log", encoding="utf-8")
        fh.setLevel(logging.DEBUG)
        fh.setFormatter(logging.Formatter("%(asctime)s %(levelname)s [%(threadName)s] %(message)s"))
        fh.addFilter(mask)
        log.addHandler(fh)
    except OSError as e:
        log.warning("Cannot write log file: %s", e)


# --------------------------------------------------------------------------
# HTTP helpers
# --------------------------------------------------------------------------

RETRY_STATUS = {429, 500, 502, 503, 504}
_thread_local = threading.local()


def get_session() -> requests.Session:
    """One requests.Session per thread."""
    s = getattr(_thread_local, "session", None)
    if s is None:
        s = requests.Session()
        s.headers["User-Agent"] = USER_AGENT
        _thread_local.session = s
    return s


class _RetryableStatus(Exception):
    pass


def http_request(method, url, retries=4, timeout=60, session=None, **kwargs) -> requests.Response:
    """HTTP request with retries and exponential backoff on network errors / 5xx / 429."""
    session = session or get_session()
    for attempt in range(retries + 1):
        try:
            resp = session.request(method, url, timeout=timeout, **kwargs)
            if resp.status_code in RETRY_STATUS and attempt < retries:
                resp.close()
                raise _RetryableStatus(f"HTTP {resp.status_code}")
            return resp
        except (requests.ConnectionError, requests.Timeout, _RetryableStatus) as e:
            if attempt >= retries:
                raise
            delay = min(60.0, BACKOFF_BASE * (2 ** attempt))
            log.debug("Retry %d/%d for %s in %.0fs (%s)", attempt + 1, retries, url, delay, e)
            time.sleep(delay)
    raise RuntimeError("unreachable")


# --------------------------------------------------------------------------
# Index
# --------------------------------------------------------------------------

def fetch_index(repo: str, cache_dir: Path, timeout=60, retries=4) -> dict:
    """Download {repo}/index-v2.json with ETag/If-Modified-Since caching."""
    url = repo.rstrip("/") + "/index-v2.json"
    cache_dir.mkdir(parents=True, exist_ok=True)
    tag = hashlib.sha1(url.encode("utf-8")).hexdigest()[:12]
    data_path = cache_dir / f"index-v2-{tag}.json"
    meta_path = cache_dir / f"index-v2-{tag}.meta.json"
    headers = {}
    meta = {}
    if data_path.is_file() and meta_path.is_file():
        try:
            meta = json.loads(meta_path.read_text("utf-8"))
        except ValueError:
            meta = {}
        if meta.get("etag"):
            headers["If-None-Match"] = meta["etag"]
        if meta.get("last_modified"):
            headers["If-Modified-Since"] = meta["last_modified"]
    try:
        resp = http_request("GET", url, retries=retries, timeout=timeout, headers=headers, stream=True)
        with resp:
            if resp.status_code == 304:
                log.info("Index not changed (304), using cache / ดัชนีไม่เปลี่ยน ใช้ไฟล์แคช")
            else:
                resp.raise_for_status()
                part = data_path.with_suffix(".part")
                try:
                    with open(part, "wb") as fh:
                        for chunk in resp.iter_content(MB):
                            fh.write(chunk)
                    json.loads(part.read_text("utf-8"))  # never cache an error page
                except BaseException as e:
                    if part.exists():
                        part.unlink()
                    if isinstance(e, ValueError):
                        raise requests.RequestException(f"response is not valid index JSON ({e})") from e
                    raise
                os.replace(part, data_path)
                meta = {
                    "url": url,
                    "etag": resp.headers.get("ETag", ""),
                    "last_modified": resp.headers.get("Last-Modified", ""),
                    "fetched_at": utc_now(),
                }
                meta_path.write_text(json.dumps(meta, indent=1), "utf-8")
                log.info("Downloaded index %s (%.1f MB)", url, data_path.stat().st_size / MB)
    except (requests.RequestException, OSError) as e:
        if not data_path.is_file():
            raise ConfigError(f"Cannot download index {url}: {e}\nโหลดดัชนีไม่ได้ ตรวจสอบอินเทอร์เน็ต/URL ของ repo") from e
        log.warning("Index download failed (%s); using cached copy / ใช้ดัชนีที่แคชไว้", e)
    try:
        return json.loads(data_path.read_text("utf-8"))
    except ValueError as e:
        for p in (data_path, meta_path):
            if p.exists():
                p.unlink()
        raise ConfigError(f"Cached index {data_path} is broken ({e}); deleted, run again"
                          "\nไฟล์ดัชนีที่แคชไว้เสีย ลบแล้ว ให้รันใหม่อีกครั้ง") from e


def download_mirrors(index: dict, primary: str, configured: List[str], use_index: bool) -> List[str]:
    """HTTPS, non-.onion mirrors (excluding the primary)."""
    urls = list(configured)
    if use_index:
        for m in (index.get("repo") or {}).get("mirrors") or []:
            u = m.get("url") if isinstance(m, dict) else m
            if u:
                urls.append(u)
    out, seen = [], {primary.rstrip("/")}
    for u in urls:
        u = u.strip().rstrip("/")
        p = urlparse(u)
        if p.scheme != "https" or not p.hostname or p.hostname.endswith(".onion") or u in seen:
            continue
        seen.add(u)
        out.append(u)
    return out


def check_repo_url(repo: str) -> str:
    """The primary repo must be https (plain http only for local tests)."""
    p = urlparse(repo or "")
    if p.scheme == "https" and p.hostname:
        return repo
    if p.scheme == "http" and p.hostname in ("127.0.0.1", "localhost"):
        return repo
    raise ConfigError(f"Repo URL must start with https:// : {repo!r}\n"
                      "ที่อยู่ repo ต้องขึ้นต้นด้วย https:// (http ธรรมดาถูกดักแก้ไฟล์ระหว่างทางได้)")


def _repo_host(repo: str) -> str:
    return (urlparse(repo or "").hostname or "").lower()


def store_url(repo: str, package: str) -> Optional[str]:
    """Public app page in the repo the file came from (None if unknown)."""
    host = _repo_host(repo)
    if host in ("", "f-droid.org"):
        return f"https://f-droid.org/packages/{package}/"
    if host == "apt.izzysoft.de":
        return f"https://apt.izzysoft.de/fdroid/index/apk/{package}"
    return None


def source_label(repo: str) -> str:
    """Value for the 'source' object metadata."""
    host = _repo_host(repo)
    return "f-droid" if host in ("", "f-droid.org") else host


# --------------------------------------------------------------------------
# Selection
# --------------------------------------------------------------------------

LICENSE_ALLOWLIST = {
    "Apache-1.0", "Apache-1.1", "Apache-2.0", "MIT", "MIT-0", "X11", "ISC", "0BSD", "BSD-1-Clause",
    "BSD-2-Clause", "BSD-3-Clause", "BSD-2-Clause-FreeBSD", "BSD-2-Clause-Patent", "BSD-3-Clause-Clear",
    "BSD-4-Clause", "MPL-1.1", "MPL-2.0", "MPL-2.0-no-copyleft-exception", "Unlicense", "CC0-1.0",
    "EUPL-1.1", "EUPL-1.2", "WTFPL", "Zlib", "Artistic-1.0", "Artistic-2.0", "BSL-1.0", "EPL-1.0", "EPL-2.0",
    "CDDL-1.0", "NCSA", "PostgreSQL", "Python-2.0", "OFL-1.1", "CC-BY-4.0", "CC-BY-SA-4.0", "CECILL-2.1",
    "CECILL-B", "CECILL-C", "MS-PL", "MS-RL", "Unicode-DFS-2016", "IJG", "libpng-2.0", "curl", "Beerware",
    "Vim", "OpenSSL", "AFL-2.1", "AFL-3.0", "OSL-3.0", "ECL-2.0", "UPL-1.0", "Ruby", "W3C", "ZPL-2.1",
    "PublicDomain",  # F-Droid's own tag for public-domain apps
}
for _base in ("GPL-2.0", "GPL-3.0", "LGPL-2.0", "LGPL-2.1", "LGPL-3.0", "AGPL-3.0", "GFDL-1.3"):
    LICENSE_ALLOWLIST.update({_base, _base + "-only", _base + "-or-later"})


def _spdx_tokens(expression: str) -> List[str]:
    return re.findall(r"\(|\)|[A-Za-z0-9.+:\-]+", expression or "")


def spdx_ids(expression: str) -> List[str]:
    """License ids in an SPDX expression (operators, brackets and WITH-exceptions removed)."""
    ids, skip_next = [], False
    for t in _spdx_tokens(expression):
        if skip_next:
            skip_next = False
            continue
        if t.upper() == "WITH":
            skip_next = True
            continue
        if t in ("(", ")") or t.upper() in ("AND", "OR"):
            continue
        ids.append(t[:-1] if t.endswith("+") else t)
    return ids


def spdx_allowed(expression: str, is_ok) -> bool:
    """Evaluate an SPDX expression: OR = any branch allowed, AND = all; malformed = False."""
    tokens = _spdx_tokens(expression)
    pos = 0

    def peek():
        return tokens[pos].upper() if pos < len(tokens) else None

    def factor():
        nonlocal pos
        if peek() == "(":
            pos += 1
            value = disjunction()
            if peek() != ")":
                raise ValueError("missing )")
            pos += 1
            return value
        if peek() in (None, ")", "AND", "OR", "WITH"):
            raise ValueError("licence id expected")
        lic = tokens[pos]
        pos += 1
        if peek() == "WITH":  # licence exceptions only add permissions
            pos += 2
            if pos > len(tokens):
                raise ValueError("exception id expected")
        return is_ok(lic[:-1] if lic.endswith("+") else lic)

    def conjunction():
        nonlocal pos
        values = [factor()]
        while peek() == "AND":
            pos += 1
            values.append(factor())
        return all(values)

    def disjunction():
        nonlocal pos
        values = [conjunction()]
        while peek() == "OR":
            pos += 1
            values.append(conjunction())
        return any(values)

    try:
        value = disjunction()
    except ValueError:
        return False
    return value and pos == len(tokens)


def license_allowed(expression: str, extra=(), allow_any=False) -> bool:
    """True if the SPDX expression can be satisfied with free licences from the allowlist."""
    if not spdx_ids(expression):
        return False
    if allow_any:
        return True
    allowed = {x.lower() for x in LICENSE_ALLOWLIST} | {x.lower() for x in extra}
    return spdx_allowed(expression, lambda lic: lic.lower() in allowed)


def localized(value, default="") -> str:
    """Pick en-US, then en, then any locale from a localised dict (non-strings ignored)."""
    if isinstance(value, str):
        return value
    if not isinstance(value, dict) or not value:
        return default
    texts = {str(k): v for k, v in value.items() if isinstance(v, str) and v}
    for loc in ("en-US", "en", "en-GB"):
        if texts.get(loc):
            return texts[loc]
    for loc in sorted(texts):
        if loc.lower().startswith("en"):
            return texts[loc]
    for loc in sorted(texts):
        return texts[loc]
    return default


def _names(x) -> set:
    if isinstance(x, dict):
        return {str(k) for k in x}
    if isinstance(x, (list, tuple, set)):
        return {str(k) for k in x}
    return set()


def anti_features(metadata: dict, version: dict) -> List[str]:
    """Union of package-level and version-level anti-features."""
    return sorted(_names(metadata.get("antiFeatures")) | _names(version.get("antiFeatures")))


def _vc(v: dict) -> int:
    try:
        return int((v.get("manifest") or {}).get("versionCode") or 0)
    except (TypeError, ValueError):
        return 0


def _apk_versions(versions: dict) -> List[dict]:
    return [v for v in (versions or {}).values() if isinstance(v, dict)
            and str((v.get("file") or {}).get("name", "")).lower().endswith(".apk")]


def _native(v: dict) -> List[str]:
    return list((v.get("manifest") or {}).get("nativecode") or [])


# ABIs a device with the preferred ABI can also run (arm64 phones run 32-bit ARM code).
ABI_FALLBACK = {"arm64-v8a": ["armeabi-v7a", "armeabi"], "armeabi-v7a": ["armeabi"], "x86_64": ["x86"]}


def pick_version(metadata: dict, versions: dict, preferred_abi="arm64-v8a") -> Optional[dict]:
    """Choose the APK to mirror: stable, <= suggestedVersionCode, runs on preferred_abi, highest versionCode.

    Returns None when no stable APK at or below suggestedVersionCode exists (never falls back to Beta).
    """
    cands = [v for v in _apk_versions(versions) if "Beta" not in (v.get("releaseChannels") or [])]
    try:
        suggested = int(str(metadata.get("suggestedVersionCode") or "0"))
    except ValueError:
        suggested = 0
    if suggested:
        cands = [v for v in cands if _vc(v) <= suggested]
    if not cands:
        return None
    # ABI splits often have different versionCodes (x86_64 highest), so filter by ABI first.
    for abi in [preferred_abi] + ABI_FALLBACK.get(preferred_abi, []):
        fitting = [v for v in cands if not _native(v) or abi in _native(v)]
        if fitting:
            cands = fitting
            break
    top = max(_vc(v) for v in cands)
    same = [v for v in cands if _vc(v) == top]
    signer = str(metadata.get("preferredSigner") or "").lower()

    def rank(v):
        signers = [str(x).lower() for x in ((v.get("manifest") or {}).get("signer") or {}).get("sha256") or []]
        native = _native(v)
        return (0 if signer and signer in signers else 1,
                0 if not native else (1 if preferred_abi in native else 2),
                str((v.get("file") or {}).get("name", "")))

    return min(same, key=rank)


@dataclass
class App:
    package: str
    name: str
    summary: str
    license: str
    categories: List[str]
    anti_features: List[str]
    version_name: str
    version_code: int
    sha256: str
    size: int
    file_name: str
    min_sdk: Optional[int] = None
    source_code: str = ""
    web_site: str = ""
    author_name: str = ""
    author_web_site: str = ""
    author_email: str = ""
    issue_tracker: str = ""
    src_file: str = ""
    src_sha256: str = ""
    src_size: int = 0
    needs_obb: bool = False
    repo: str = ""
    filename: str = ""
    key: str = ""


@dataclass
class Selection:
    total: int = 0
    eligible: List[App] = field(default_factory=list)
    selected: List[App] = field(default_factory=list)
    excluded: Counter = field(default_factory=Counter)
    reasons: Dict[str, List[str]] = field(default_factory=dict)  # excluded package -> reasons
    rejected_licenses: Counter = field(default_factory=Counter)


def _text(value) -> str:
    return value.strip() if isinstance(value, str) else ""


def build_app(package: str, entry: dict, version: dict, repo: str = "") -> App:
    meta = entry.get("metadata") or {}
    man = version.get("manifest") or {}
    f = version.get("file") or {}
    src = version.get("src") or {}
    cats = meta.get("categories")
    return App(
        package=package,
        name=localized(meta.get("name")).strip() or package,
        summary=localized(meta.get("summary")).strip(),
        license=_text(meta.get("license")),
        categories=[str(c) for c in cats] if isinstance(cats, list) else [],
        anti_features=anti_features(meta, version),
        version_name=str(man.get("versionName") or ""),
        version_code=_vc(version),
        sha256=str(f.get("sha256") or "").lower(),
        size=int(f.get("size") or 0),
        file_name=str(f.get("name") or ""),
        min_sdk=(man.get("usesSdk") or {}).get("minSdkVersion"),
        source_code=_text(meta.get("sourceCode")),
        web_site=_text(meta.get("webSite")),
        author_name=_text(meta.get("authorName")),
        author_web_site=_text(meta.get("authorWebSite")),
        author_email=_text(meta.get("authorEmail")),
        issue_tracker=_text(meta.get("issueTracker")),
        src_file=str(src.get("name") or ""),
        src_sha256=str(src.get("sha256") or "").lower(),
        src_size=int(src.get("size") or 0),
        needs_obb=bool(version.get("obbMainFile") or version.get("obbPatchFile")),
        repo=repo,
    )


def exclusion_reasons(app: Optional[App], cfg: Config) -> List[str]:
    """Why a package cannot be mirrored (empty list = eligible)."""
    if app is None:
        return ["no_versions"]
    if not license_allowed(app.license, cfg.getlist("filter", "extra_licenses"),
                           cfg.getbool("filter", "allow_any_license")):
        return ["license"]
    blocked = {x.lower() for x in cfg.getlist("filter", "exclude_antifeatures")}
    bad = [af for af in app.anti_features if af.lower() in blocked]
    if bad:
        return ["antifeature:" + af for af in bad]
    if not re.fullmatch(r"[0-9a-f]{64}", app.sha256) or app.size <= 0:
        return ["bad_index_entry"]
    if app.needs_obb:
        return ["needs_obb"]  # the APK alone would not work without its .obb expansion files
    if app.size > cfg.getfloat("filter", "max_size_mb") * MB:
        return ["too_big"]
    return []


def package_reasons(pkg: str, entry, cfg: Config, abi: str, repo: str = ""):
    """(App or None, exclusion reasons) for one index entry; malformed entries never raise."""
    try:
        entry = entry if isinstance(entry, dict) else {}
        meta = entry.get("metadata") or {}
        versions = entry.get("versions") or {}
        version = pick_version(meta, versions, abi)
        if version is None:
            return None, ["no_stable_version" if _apk_versions(versions) else "no_versions"]
        app = build_app(pkg, entry, version, repo)
        return app, exclusion_reasons(app, cfg)
    except (TypeError, ValueError, AttributeError) as e:
        log.debug("%s: malformed index entry: %s", pkg, e)
        return None, ["bad_index_entry"]


def select_apps(index: dict, cfg: Config, args=None, apply_limit=True) -> Selection:
    """Evaluate every package, assign keys, then apply user filters, sort and (optionally) limit."""
    sel = Selection()
    packages = index.get("packages") or {}
    sel.total = len(packages)
    abi = cfg.get("filter", "preferred_abi") or "arm64-v8a"
    repo = cfg.get("source", "repo")
    for pkg in sorted(packages):
        app, reasons = package_reasons(pkg, packages[pkg], cfg, abi, repo)
        if reasons:
            sel.excluded.update(reasons)
            sel.reasons[pkg] = reasons
            if reasons == ["license"]:
                sel.rejected_licenses[app.license or "(none)"] += 1
        else:
            sel.eligible.append(app)
    assign_keys(sel.eligible, cfg)
    sel.selected = apply_user_filters(sel.eligible, args, apply_limit)
    return sel


def apply_user_filters(apps: List[App], args, apply_limit=True) -> List[App]:
    out = list(apps)
    if args is None:
        return out
    wanted = requested_packages(args)
    if wanted:
        out = [a for a in out if a.package in wanted]
    cats = [c.lower() for c in (getattr(args, "category", None) or [])]
    if cats:
        out = [a for a in out if any(c.lower() in cats for c in a.categories)]
    if getattr(args, "games", False):
        out = [a for a in out if is_game(a)]
    if getattr(args, "search", None):
        q = args.search.lower()
        out = [a for a in out if q in a.name.lower() or q in a.package.lower() or q in a.summary.lower()]
    out.sort(key=lambda a: (a.name.lower(), a.package))
    if apply_limit and getattr(args, "limit", None):
        out = out[:args.limit]
    return out


def requested_packages(args) -> List[str]:
    text = getattr(args, "packages", None) or ""
    return [p.strip() for p in text.split(",") if p.strip()]


def report_unselected_packages(sel: Selection, args, index: dict):
    """Explain why packages named with --packages were not selected."""
    chosen = {a.package for a in sel.eligible}
    for pkg in requested_packages(args):
        if pkg in chosen:
            continue
        if pkg in sel.reasons:
            log.warning("%s: not selected - excluded: %s / ถูกคัดออก", pkg, ", ".join(sel.reasons[pkg]))
        elif pkg not in (index.get("packages") or {}):
            log.warning("%s: not in index / ไม่พบในดัชนี (พิมพ์ชื่อ package ผิด?)", pkg)


def is_game(app: App) -> bool:
    return any("game" in c.lower() for c in app.categories)


# --------------------------------------------------------------------------
# Naming
# --------------------------------------------------------------------------

def sanitize(text: str) -> str:
    """ASCII-only, [A-Za-z0-9.-], spaces/underscores -> '-', no repeats, trimmed."""
    t = unicodedata.normalize("NFKD", str(text or "")).encode("ascii", "ignore").decode("ascii")
    t = re.sub(r"[\s_+()/\[\]]+", "-", t)  # keep "1.0+12" / "3.2(45)" readable: 1.0-12, 3.2-45
    t = re.sub(r"[^A-Za-z0-9.\-]", "", t)
    t = re.sub(r"-{2,}", "-", t)
    t = re.sub(r"\.{2,}", ".", t)
    t = re.sub(r"(-\.|\.-)+", "-", t)
    return t.strip("-.")


def validate_key(key: str) -> str:
    """Reject object keys that could escape a folder or confuse tools."""
    if not key or key.startswith("/") or "\\" in key or ".." in key:
        raise ValueError(f"unsafe object key: {key!r}")
    if any(ord(c) < 32 or ord(c) == 127 for c in key):
        raise ValueError(f"control character in object key: {key!r}")
    if any(part in ("", ".") for part in key.split("/")):
        raise ValueError(f"empty path segment in object key: {key!r}")
    return key


def render_names(app: App, cfg: Config, suffix: str = ""):
    """Return (filename, key) for an app using the configured templates."""
    name = sanitize(app.name) or sanitize(app.package) or "app"
    version = re.sub(r"^[vV](?=\d)", "", sanitize(app.version_name)) or str(app.version_code)
    first = name[0].upper()
    folder = first if "A" <= first <= "Z" else (cfg.get("naming", "nonalpha_folder") or "A")
    values = {"name": name, "version": version, "package": sanitize(app.package),
              "versionCode": app.version_code, "folder": folder}
    filename = cfg.get("naming", "filename_template").format_map(values)
    if suffix:
        filename = filename[:-4] + suffix + ".apk" if filename.lower().endswith(".apk") else filename + suffix
    if "/" in filename or "\\" in filename:
        raise ValueError(f"file name must not contain a folder separator: {filename!r}")
    values["filename"] = filename
    key = validate_key(cfg.get("naming", "key_template").format_map(values))
    return filename, key


def assign_keys(apps: List[App], cfg: Config):
    """Set filename/key on each app; later package ids get '-{package}' on collision."""
    used = set()
    for app in sorted(apps, key=lambda a: a.package):
        filename, key = render_names(app, cfg)
        if key.lower() in used:
            filename, key = render_names(app, cfg, "-" + sanitize(app.package))
        used.add(key.lower())
        app.filename, app.key = filename, key


# --------------------------------------------------------------------------
# Download + verification
# --------------------------------------------------------------------------

class DownloadError(Exception):
    pass


class IntegrityError(DownloadError):
    pass


class Cancelled(Exception):
    pass


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(MB), b""):
            h.update(chunk)
    return h.hexdigest()


class Downloader:
    def __init__(self, primary: str, mirrors: List[str], download_dir: Path,
                 timeout=60, retries=4, stop_event: Optional[threading.Event] = None):
        self.primary = primary.rstrip("/")
        self.mirrors = mirrors
        self.dir = download_dir
        self.timeout, self.retries = timeout, retries
        self.stop = stop_event or threading.Event()
        self._counter = 0
        self._lock = threading.Lock()

    def _bases(self) -> List[str]:
        if not self.mirrors:
            return [self.primary]
        with self._lock:
            mirror = self.mirrors[self._counter % len(self.mirrors)]
            self._counter += 1
        return [mirror, self.primary]

    def download(self, app: App) -> Path:
        """Download and verify one APK; returns the verified local path."""
        return self.download_file(app.package, app.file_name, app.sha256, app.size, ".apk")

    def download_file(self, label: str, file_name: str, sha256: str, size: int, ext: str) -> Path:
        """Download {base}{file_name}, verify sha256 + size, try mirrors then the primary."""
        if not re.fullmatch(r"[0-9a-f]{64}", sha256 or ""):
            raise DownloadError("invalid sha256 in index")
        if ".." in file_name or not file_name.startswith("/"):
            raise DownloadError(f"unsafe file name in index: {file_name!r}")
        self.dir.mkdir(parents=True, exist_ok=True)
        final = self.dir / f"{sha256}{ext}"
        if final.is_file() and final.stat().st_size == size and sha256_file(final) == sha256:
            return final
        free = shutil.disk_usage(self.dir).free
        if free < size + DISK_RESERVE:
            raise DownloadError(
                f"Not enough disk space: need {(size + DISK_RESERVE) / MB:.0f} MB, free {free / MB:.0f} MB"
                " / พื้นที่ดิสก์ไม่พอ")
        path_part = quote(file_name, safe="/")
        bases = self._bases()
        last_error = None
        for i, base in enumerate(bases):
            try:
                self._fetch(base + path_part, sha256, size, final)
                return final
            except Cancelled:
                raise
            except IntegrityError as e:
                if i == len(bases) - 1:  # the primary served a bad file too
                    raise
                last_error = e
                log.warning("%s: bad file from mirror %s (%s); trying next", label, base, e)
            except (requests.RequestException, OSError) as e:
                last_error = e
                log.warning("%s: download from %s failed: %s", label, base, e)
        raise DownloadError(f"download failed: {last_error}")

    def _fetch(self, url: str, sha256: str, expected_size: int, final: Path):
        part = final.with_name(final.name + ".part")
        h, size = hashlib.sha256(), 0
        try:
            resp = http_request("GET", url, retries=self.retries, timeout=self.timeout, stream=True)
            with resp:
                resp.raise_for_status()
                with open(part, "wb") as fh:
                    for chunk in resp.iter_content(MB):
                        if self.stop.is_set():
                            raise Cancelled("cancelled")
                        size += len(chunk)
                        if size > expected_size:
                            raise IntegrityError(f"size mismatch: more than {expected_size} bytes received")
                        h.update(chunk)
                        fh.write(chunk)
            if size != expected_size:
                raise IntegrityError(f"size mismatch: expected {expected_size}, got {size}")
            if h.hexdigest() != sha256:
                raise IntegrityError(f"sha256 mismatch: expected {sha256}, got {h.hexdigest()}")
            os.replace(part, final)
        finally:
            if part.exists():
                part.unlink()


# --------------------------------------------------------------------------
# Virus scanning
# --------------------------------------------------------------------------

class ScanError(Exception):
    pass


@dataclass
class ScanResult:
    engines: List[str] = field(default_factory=list)
    malicious_count: int = 0
    infected: bool = False
    detail: str = ""

    def as_dict(self):
        # null (not 0) when nothing scanned, so an unscanned file never looks clean
        return {"engines": self.engines, "malicious_count": self.malicious_count if self.engines else None}


class RateLimiter:
    """Thread-safe: at most N calls per minute (evenly spaced)."""

    def __init__(self, per_minute: float):
        self.interval = 60.0 / per_minute if per_minute and per_minute > 0 else 0.0
        self.lock = threading.Lock()
        self.next_at = 0.0

    def acquire(self, stop: Optional[threading.Event] = None):
        with self.lock:
            now = time.monotonic()
            wait_for = max(0.0, self.next_at - now)
            self.next_at = max(now, self.next_at) + self.interval
        if wait_for:
            if stop is not None:
                if stop.wait(wait_for):
                    raise Cancelled("cancelled")
            else:
                time.sleep(wait_for)


def clamscan_command(path_setting: str) -> List[str]:
    """The clamscan executable as a list; a .py path is run with the current Python (tests)."""
    if path_setting.lower().endswith(".py"):
        return [sys.executable, path_setting]
    return [path_setting]


class Scanner:
    def __init__(self, cfg: Config, session=None):
        mode = (cfg.get("scan", "scan") or "clamav").lower().replace(" ", "")
        self.engines = [] if mode == "none" else [m for m in mode.split("+") if m]
        unknown = set(self.engines) - {"clamav", "virustotal"}
        if unknown:
            raise ConfigError(f"Unknown scan mode: {mode} (use clamav | virustotal | clamav+virustotal | none)")
        self.clam_cmd = clamscan_command(cfg.get("scan", "clamscan_path") or "clamscan")
        self.clam_args = cfg.get("scan", "clamscan_args").split()
        self.clam_timeout = cfg.getint("scan", "clamscan_timeout") or 900
        self.vt_key = cfg.get("virustotal", "api_key")
        self.vt_upload = cfg.getbool("virustotal", "upload_unknown")
        self.vt_max = cfg.getint("virustotal", "max_malicious")
        self.vt_poll_interval = cfg.getfloat("virustotal", "poll_interval")
        self.vt_poll_timeout = cfg.getfloat("virustotal", "poll_timeout")
        self.vt_limiter = RateLimiter(cfg.getfloat("virustotal", "requests_per_minute"))
        self.session = session
        self.stop = threading.Event()  # Runner replaces this with its own stop event

    def check_available(self):
        """Fail fast (before downloading anything) if a configured scanner is unusable."""
        if "clamav" in self.engines:
            exe = self.clam_cmd[-1] if len(self.clam_cmd) > 1 else self.clam_cmd[0]
            if not (Path(exe).is_file() or shutil.which(exe)):
                raise ScanError(
                    f"ClamAV (clamscan) not found: {exe}\n"
                    "ไม่พบโปรแกรมสแกนไวรัส ClamAV - ติดตั้ง ClamAV แล้วตั้งค่า clamscan_path ใน config.ini "
                    "(เช่น C:\\Program Files\\ClamAV\\clamscan.exe)  "
                    "(--scan none ใช้ได้เฉพาะทดสอบกับ --dest local เท่านั้น)")
        if "virustotal" in self.engines and not self.vt_key:
            raise ScanError("VirusTotal selected but no API key (VT_API_KEY) / ยังไม่ได้ใส่ API key ของ VirusTotal")

    def scan(self, path: Path, sha256: str) -> ScanResult:
        result = ScanResult()
        if "clamav" in self.engines:
            infected, detail = self.clamav(path)
            result.engines.append("clamav")
            if infected:
                result.infected, result.malicious_count, result.detail = True, 1, detail
                return result
        if "virustotal" in self.engines:
            count = self.virustotal(path, sha256)
            if count is not None:
                result.engines.append("virustotal")
                result.malicious_count += count
                if count > self.vt_max:
                    result.infected = True
                    result.detail = f"VirusTotal: {count} engines flagged this file (max {self.vt_max})"
            elif self.engines == ["virustotal"]:
                raise ScanError("file unknown to VirusTotal and upload_unknown=false; not scanned")
        return result

    def clamav(self, path: Path):
        """Run clamscan. Returns (infected, output). Exit 0 clean, 1 infected, other = error.

        --alert-exceeds-max makes clamscan report files it could only partly scan (size, time,
        file-count limits) instead of silently calling them OK; we treat that as a scan error.
        """
        extra = [] if any(a.startswith("--alert-exceeds-max") for a in self.clam_args) else ["--alert-exceeds-max=yes"]
        cmd = self.clam_cmd + self.clam_args + extra + ["--no-summary", str(path)]
        try:
            proc = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8",
                                  errors="replace", timeout=self.clam_timeout)
        except FileNotFoundError as e:
            raise ScanError(f"clamscan not found ({e}) / ไม่พบ clamscan") from e
        except subprocess.TimeoutExpired as e:
            raise ScanError("clamscan timed out / สแกนนานเกินกำหนด") from e
        out = (proc.stdout or "").strip() + (" " + proc.stderr.strip() if proc.stderr else "")
        if proc.returncode == 0:
            return False, out
        if proc.returncode == 1:
            if "Heuristics.Limits.Exceeded" in out:
                raise ScanError("clamscan could not scan the whole file (limit exceeded; raise --max-filesize/"
                                "--max-scansize/--max-scantime/--max-files in clamscan_args): " + out[:300] +
                                " / สแกนไม่ครบทั้งไฟล์ ให้เพิ่มค่าใน clamscan_args")
            return True, out or "infected"
        raise ScanError(f"clamscan error (exit {proc.returncode}): {out[:500]}")

    # -- VirusTotal API v3 --------------------------------------------------

    def _vt(self, method, url, retries=2, **kwargs) -> requests.Response:
        self.vt_limiter.acquire(self.stop)
        headers = dict(kwargs.pop("headers", {}) or {})
        headers["x-apikey"] = self.vt_key
        session = self.session or get_session()
        resp = http_request(method, url, retries=retries, timeout=300, session=session, headers=headers, **kwargs)
        if resp.status_code == 401:
            raise ScanError("VirusTotal rejected the API key (401)")
        return resp

    def virustotal(self, path: Path, sha256: str) -> Optional[int]:
        """Malicious-engine count from VirusTotal, or None if unknown and not uploaded."""
        resp = self._vt("GET", f"{VT_API}/files/{sha256}")
        if resp.status_code == 200:
            stats = (((resp.json().get("data") or {}).get("attributes") or {}).get("last_analysis_stats") or {})
            if not stats:
                raise ScanError("VirusTotal returned no analysis results for this file")
            return int(stats.get("malicious") or 0)
        if resp.status_code != 404:
            raise ScanError(f"VirusTotal lookup failed: HTTP {resp.status_code}")
        if not self.vt_upload:
            return None
        return self._vt_upload_and_wait(path, sha256)

    def _vt_post_file(self, url: str, path: Path, attempts=3) -> requests.Response:
        """POST the file, reopening it on every attempt (a retried, consumed handle would send 0 bytes)."""
        for attempt in range(attempts):
            try:
                with open(path, "rb") as fh:
                    r = self._vt("POST", url, retries=0, files={"file": (path.name, fh)})
            except (requests.ConnectionError, requests.Timeout) as e:
                if attempt == attempts - 1:
                    raise ScanError(f"VirusTotal upload failed: {e}") from e
                log.debug("VirusTotal upload retry %d: %s", attempt + 1, e)
            else:
                if r.status_code not in RETRY_STATUS or attempt == attempts - 1:
                    return r
                log.debug("VirusTotal upload retry %d: HTTP %d", attempt + 1, r.status_code)
                r.close()
            if self.stop.wait(min(60.0, BACKOFF_BASE * (2 ** attempt))):
                raise Cancelled("cancelled")
        raise RuntimeError("unreachable")

    def _vt_upload_and_wait(self, path: Path, sha256: str) -> int:
        size = path.stat().st_size
        if size > 650 * MB:
            raise ScanError("file larger than 650 MB, VirusTotal cannot scan it")
        upload_url = f"{VT_API}/files"
        if size > 32 * MB:
            r = self._vt("GET", f"{VT_API}/files/upload_url")
            if r.status_code != 200:
                raise ScanError(f"VirusTotal upload_url failed: HTTP {r.status_code}")
            upload_url = r.json().get("data")
        r = self._vt_post_file(upload_url, path)
        if r.status_code not in (200, 201):
            raise ScanError(f"VirusTotal upload failed: HTTP {r.status_code}")
        analysis_id = (r.json().get("data") or {}).get("id")
        if not analysis_id:
            raise ScanError("VirusTotal upload returned no analysis id")
        deadline = time.monotonic() + self.vt_poll_timeout
        while True:
            r = self._vt("GET", f"{VT_API}/analyses/{analysis_id}")
            if r.status_code != 200:
                raise ScanError(f"VirusTotal analysis failed: HTTP {r.status_code}")
            body = r.json()
            attrs = (body.get("data") or {}).get("attributes") or {}
            if attrs.get("status") == "completed":
                scanned = str(((body.get("meta") or {}).get("file_info") or {}).get("sha256") or "").lower()
                if scanned != sha256:
                    raise ScanError(f"VirusTotal analysed a different file (sha256 {scanned or 'missing'})")
                stats = attrs.get("stats") or {}
                if not any(int(v or 0) for v in stats.values()):
                    raise ScanError("VirusTotal analysis has no engine results")
                return int(stats.get("malicious") or 0)
            if time.monotonic() >= deadline:
                raise ScanError("VirusTotal analysis did not finish in time")
            if self.stop.wait(self.vt_poll_interval):
                raise Cancelled("cancelled")


# --------------------------------------------------------------------------
# Destinations
# --------------------------------------------------------------------------

def _is_not_found(exc) -> bool:
    """True for a botocore ClientError meaning 404 / NoSuchKey."""
    resp = getattr(exc, "response", None)
    if not isinstance(resp, dict):
        return False
    code = str((resp.get("Error") or {}).get("Code", ""))
    status = (resp.get("ResponseMetadata") or {}).get("HTTPStatusCode")
    return code in ("404", "NoSuchKey", "NotFound") or status == 404


class LocalDest:
    """Copies files into a local folder (preview / tests)."""

    name = "local"

    def __init__(self, root: Path):
        self.root = Path(root)

    def _path(self, key: str) -> Path:
        return self.root.joinpath(*validate_key(key).split("/"))

    def head(self, key: str) -> Optional[dict]:
        p = self._path(key)
        return {"sha256": sha256_file(p)} if p.is_file() else None

    def put_file(self, path: Path, key: str, filename: str, metadata: dict, content_type=APK_MIME):
        target = self._path(key)
        target.parent.mkdir(parents=True, exist_ok=True)
        tmp = target.with_name(target.name + ".tmp")
        shutil.copyfile(path, tmp)
        os.replace(tmp, target)

    def get_text(self, key: str) -> Optional[str]:
        p = self._path(key)
        return p.read_text("utf-8") if p.is_file() else None

    def put_text(self, key: str, text: str, content_type="application/json"):
        target = self._path(key)
        target.parent.mkdir(parents=True, exist_ok=True)
        tmp = target.with_name(target.name + ".tmp")
        tmp.write_text(text, "utf-8")
        os.replace(tmp, target)


def make_r2_client(cfg: Config):
    import boto3
    from botocore.config import Config as BotoConfig

    missing = [env for (s, o), env in ENV_OVERRIDES.items()
               if s == "r2" and o in ("account_id", "access_key_id", "secret_access_key", "bucket")
               and not cfg.get(s, o)]
    if missing:
        raise ConfigError("Missing R2 settings: " + ", ".join(missing) +
                          "\nยังไม่ได้ตั้งค่า R2 ใน config.ini หรือ environment variables")
    opts = dict(retries={"max_attempts": 5, "mode": "standard"}, signature_version="s3v4")
    try:
        boto_cfg = BotoConfig(request_checksum_calculation="when_required",
                              response_checksum_validation="when_required", **opts)
    except TypeError:  # botocore < 1.36 has neither option nor the problem
        boto_cfg = BotoConfig(**opts)
    return boto3.client(
        "s3",
        endpoint_url=f"https://{cfg.get('r2', 'account_id')}.r2.cloudflarestorage.com",
        aws_access_key_id=cfg.get("r2", "access_key_id"),
        aws_secret_access_key=cfg.get("r2", "secret_access_key"),
        region_name="auto",
        config=boto_cfg,
    )


class R2Dest:
    """Cloudflare R2 through the S3 API (boto3)."""

    name = "r2"

    def __init__(self, cfg: Config, client=None):
        self.bucket = cfg.get("r2", "bucket")
        self.client = client or make_r2_client(cfg)

    def head(self, key: str) -> Optional[dict]:
        try:
            resp = self.client.head_object(Bucket=self.bucket, Key=key)
        except Exception as e:  # botocore ClientError
            if _is_not_found(e):
                return None
            raise
        return {str(k).lower(): v for k, v in (resp.get("Metadata") or {}).items()}

    def put_file(self, path: Path, key: str, filename: str, metadata: dict, content_type=APK_MIME):
        from boto3.s3.transfer import TransferConfig

        tc = TransferConfig(multipart_threshold=64 * MB, multipart_chunksize=64 * MB, max_concurrency=4)
        extra = {
            "ContentType": content_type,
            "ContentDisposition": f'attachment; filename="{filename}"',
            "Metadata": metadata,
        }
        self.client.upload_file(str(path), self.bucket, key, ExtraArgs=extra, Config=tc)

    def get_text(self, key: str) -> Optional[str]:
        try:
            resp = self.client.get_object(Bucket=self.bucket, Key=key)
        except Exception as e:
            if _is_not_found(e):
                return None
            raise
        return resp["Body"].read().decode("utf-8")

    def put_text(self, key: str, text: str, content_type="application/json"):
        self.client.put_object(Bucket=self.bucket, Key=key, Body=text.encode("utf-8"),
                               ContentType=content_type)


def upload_object(dest, path: Path, key: str, filename: str, sha256: str, metadata: dict,
                  content_type=APK_MIME) -> str:
    """Upload unless the key exists. Returns 'uploaded', 'exists' (same sha256) or 'conflict'."""
    existing = dest.head(key)
    if existing is not None:
        return "exists" if str(existing.get("sha256") or "").lower() == sha256 else "conflict"
    dest.put_file(path, key, filename, metadata, content_type)
    return "uploaded"


def object_metadata(app: App, sha256: str) -> dict:
    return {
        "sha256": sha256,
        "package": app.package,
        "versioncode": str(app.version_code),
        "license": app.license.encode("ascii", "ignore").decode("ascii"),
        "source": source_label(app.repo),
    }


def upload_guarded(dest, path: Path, app: App) -> str:
    """Upload the APK unless its key exists (never overwrites)."""
    return upload_object(dest, path, app.key, app.filename, app.sha256, object_metadata(app, app.sha256))


def source_key(app: App) -> str:
    """Object key for the mirrored source tarball (GPL 'corresponding source')."""
    return validate_key(f"src/{sanitize(app.package)}_{app.version_code}_src.tar.gz")


# --------------------------------------------------------------------------
# Catalog (= state)
# --------------------------------------------------------------------------

CSV_FIELDS = ["package", "name", "summary", "version", "versionCode", "license", "sourceCode", "webSite",
              "authorName", "authorWebSite", "authorEmail", "issueTracker", "categories", "antiFeatures",
              "sha256", "size", "minSdk", "r2_key", "public_url", "store_url", "fdroid_url", "source_repo",
              "source_tarball_url", "source_mirror_url", "scan_engines", "scan_malicious_count", "status",
              "withdrawn_reason", "error", "updated_at"]
IN_BUCKET = ("uploaded", "unscanned")  # statuses whose APK is in the bucket
SAVE_RETRY_DELAY = 1.0


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def public_url(base: str, key: str) -> Optional[str]:
    return base.rstrip("/") + "/" + quote(key, safe="/") if base else None


def csv_safe(value):
    """Stop Excel from running text that looks like a formula (=, +, -, @ ...)."""
    if isinstance(value, str) and value[:1] in ("=", "+", "-", "@", "\t", "\r"):
        return "'" + value
    return value


def write_csv_atomic(path: Path, header: List[str], rows: List[list]):
    """Write a UTF-8-BOM CSV (for Excel) via a temp file; the temp file is removed on failure."""
    path = Path(path)
    tmp = Path(str(path) + ".tmp")
    try:
        with open(tmp, "w", newline="", encoding="utf-8-sig") as fh:
            w = csv.writer(fh)
            w.writerow(header)
            for row in rows:
                w.writerow([csv_safe(x) for x in row])
        os.replace(tmp, path)
    finally:
        if tmp.exists():
            tmp.unlink()


VERSION_HISTORY_FIELDS = ("version", "versionCode", "sha256", "r2_key", "public_url", "source_tarball_url",
                          "source_mirror_url", "license", "updated_at")


class Catalog:
    def __init__(self, path: Path):
        self.path = Path(path)
        self.lock = threading.RLock()
        self.packages: Dict[str, dict] = {}
        self.source = ""

    def load(self):
        if self.path.is_file():
            self.merge_json(self.path.read_text("utf-8"))

    def merge_json(self, text: str):
        """Merge another catalog; the newer record (updated_at) wins per package."""
        data = json.loads(text)
        with self.lock:
            for pkg, rec in (data.get("packages") or {}).items():
                mine = self.packages.get(pkg)
                if mine is None or str(rec.get("updated_at", "")) > str(mine.get("updated_at", "")):
                    self.packages[pkg] = rec

    def get(self, package: str) -> Optional[dict]:
        with self.lock:
            return self.packages.get(package)

    def is_done(self, app: App) -> bool:
        rec = self.get(app.package)
        return bool(rec and rec.get("status") == "uploaded" and rec.get("versionCode") == app.version_code
                    and rec.get("sha256") == app.sha256)

    def skip_reason(self, app: App, retry=False) -> Optional[str]:
        """Why this package needs no work this run (None = process it)."""
        if self.is_done(app):
            return "already uploaded"
        if retry:
            return None
        rec = self.get(app.package) or {}
        for r in (rec, rec.get("last_attempt") or {}):
            if (r.get("status") in ("infected", "conflict") and r.get("versionCode") == app.version_code
                    and r.get("sha256") == app.sha256):
                return f"{r['status']} before (use --retry to try again)"
        return None

    def failed_before(self, app: App) -> bool:
        """True if this exact version already failed (it is retried after never-tried packages)."""
        rec = self.get(app.package) or {}
        return any(r.get("status") == "failed" and r.get("versionCode") == app.version_code
                   and r.get("sha256") == app.sha256 for r in (rec, rec.get("last_attempt") or {}))

    def record(self, rec: dict):
        """Store a result; a failed new version keeps the last uploaded record (with last_attempt)."""
        with self.lock:
            prev = self.packages.get(rec["package"])
            if rec["status"] not in IN_BUCKET and prev and prev.get("status") in IN_BUCKET:
                prev = dict(prev)
                prev["last_attempt"] = {k: rec.get(k) for k in ("version", "versionCode", "sha256", "status",
                                                                "error", "updated_at")}
                self.packages[rec["package"]] = prev
                return
            if (rec["status"] in IN_BUCKET and prev and prev.get("status") in IN_BUCKET
                    and prev.get("versionCode") != rec.get("versionCode")):
                # old objects stay in the bucket, so keep their links (and source links) too
                old = {k: prev.get(k) for k in VERSION_HISTORY_FIELDS}
                rec["previous_versions"] = [old] + list(prev.get("previous_versions") or [])
            elif prev and prev.get("previous_versions"):
                rec["previous_versions"] = prev["previous_versions"]
            self.packages[rec["package"]] = rec

    def mark_withdrawn(self, sel: Selection, index: dict, repo: str) -> List[str]:
        """Flag uploaded apps that are now excluded (licence, anti-feature) or gone from the repo."""
        present = index.get("packages") or {}
        flagged = []
        with self.lock:
            for pkg, rec in list(self.packages.items()):
                if rec.get("status") not in IN_BUCKET:
                    continue
                if rec.get("source_repo") and rec["source_repo"].rstrip("/") != repo.rstrip("/"):
                    continue  # recorded from another repo
                if pkg not in present:
                    reason = "removed_from_repo"
                else:
                    bad = [r for r in sel.reasons.get(pkg, []) if r == "license" or r.startswith("antifeature:")]
                    reason = ", ".join(bad) or None
                if (rec.get("withdrawn_reason") or None) != reason:
                    rec = dict(rec, withdrawn_reason=reason, updated_at=utc_now())
                    self.packages[pkg] = rec
                if reason:
                    flagged.append(pkg)
                    log.warning("%s: uploaded earlier but now %s - remove/flag its post / "
                                "แอปนี้ไม่ผ่านเกณฑ์แล้ว ควรถอดหรือแจ้งเตือนในโพสต์", pkg, reason)
        return flagged

    def to_json(self) -> str:
        with self.lock:
            data = {"generated_at": utc_now(), "source_repo": self.source,
                    "count": len(self.packages), "packages": dict(sorted(self.packages.items()))}
            return json.dumps(data, ensure_ascii=False, indent=1)

    def save(self):
        text = self.to_json()
        with self.lock:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            tmp = self.path.with_name(self.path.name + ".tmp")
            tmp.write_text(text, "utf-8")
            os.replace(tmp, self.path)
        return text

    def export_csv(self, path: Path):
        with self.lock:
            recs = [self.packages[k] for k in sorted(self.packages)]
        rows = []
        for rec in recs:
            row = dict(rec)
            row["categories"] = "; ".join(rec.get("categories") or [])
            row["antiFeatures"] = "; ".join(rec.get("antiFeatures") or [])
            scan = rec.get("scan") or {}
            row["scan_engines"] = "; ".join(scan.get("engines") or [])
            row["scan_malicious_count"] = scan.get("malicious_count")
            rows.append(["" if row.get(f) is None else row.get(f) for f in CSV_FIELDS])
        write_csv_atomic(path, CSV_FIELDS, rows)


def base_record(app: App, base_url: str, repo: str = "") -> dict:
    repo = repo or app.repo
    store = store_url(repo, app.package)
    return {
        "package": app.package, "name": app.name, "summary": app.summary,
        "version": app.version_name, "versionCode": app.version_code, "license": app.license,
        "sourceCode": app.source_code, "webSite": app.web_site, "authorName": app.author_name,
        "authorWebSite": app.author_web_site, "authorEmail": app.author_email, "issueTracker": app.issue_tracker,
        "categories": app.categories, "antiFeatures": app.anti_features, "sha256": app.sha256,
        "size": app.size, "minSdk": app.min_sdk, "r2_key": app.key, "public_url": public_url(base_url, app.key),
        "store_url": store,
        "fdroid_url": store if store and store.startswith("https://f-droid.org/") else None,
        "source_repo": repo or None,
        "source_tarball_url": repo.rstrip("/") + quote(app.src_file, safe="/") if repo and app.src_file else None,
        "source_mirror_key": None, "source_mirror_url": None,
        "scan": {"engines": [], "malicious_count": None}, "status": "failed", "withdrawn_reason": None,
        "error": None, "updated_at": utc_now(),
    }


# --------------------------------------------------------------------------
# Pipeline
# --------------------------------------------------------------------------

class Runner:
    def __init__(self, cfg: Config, work_dir: Path, downloader: Downloader, scanner: Scanner, dest,
                 catalog: Catalog, keep_files=False, stop_event=None):
        self.cfg, self.work_dir = cfg, work_dir
        self.downloader, self.scanner, self.dest, self.catalog = downloader, scanner, dest, catalog
        self.keep_files = keep_files
        self.keep_quarantine = cfg.getbool("scan", "keep_quarantine")
        self.mirror_source = cfg.getbool("dest", "mirror_source")
        self.base_url = cfg.get("r2", "public_base_url") if dest.name == "r2" else ""
        self.catalog_key = cfg.get("dest", "catalog_key")
        self.stop = stop_event or downloader.stop
        self.scanner.stop = self.stop
        self.bytes_uploaded = 0
        self._lock = threading.Lock()

    def _check_stop(self):
        if self.stop.is_set():
            raise Cancelled("cancelled")

    def process(self, app: App) -> str:
        """Download, verify, scan and upload one package. Never raises."""
        if self.stop.is_set():
            return "cancelled"
        if self.catalog.is_done(app):
            log.debug("%s: already uploaded, skip", app.package)
            return "skipped"
        rec = base_record(app, self.base_url, self.downloader.primary)
        path = None
        try:
            existing = self.dest.head(app.key)
            if existing is not None and str(existing.get("sha256") or "").lower() != app.sha256:
                # checked before downloading so a conflict costs F-Droid no bandwidth
                rec["status"] = "conflict"
                rec["error"] = "object already exists with different content; not overwritten"
                log.warning("%s: %s exists with other content - NOT overwritten", app.package, app.key)
            else:
                log.info("%s: downloading %s (%.1f MB)", app.package, app.version_name, app.size / MB)
                path = self.downloader.download(app)
                self._check_stop()
                result = self.scanner.scan(path, app.sha256)
                rec["scan"] = result.as_dict()
                if result.infected:
                    rec["status"], rec["error"] = "infected", result.detail
                    log.warning("%s: INFECTED - not uploaded / พบไวรัส ไม่อัปโหลด: %s", app.package, result.detail)
                    if existing is not None:  # put there by an earlier --scan none run
                        log.error("%s: %s is ALREADY in the bucket (uploaded unscanned) - delete it now / "
                                  "ไฟล์นี้อยู่ใน bucket แล้ว ให้ลบออกทันที", app.package, app.key)
                    self._dispose(path)
                    path = None
                else:
                    self._check_stop()
                    if self.mirror_source and app.src_file:
                        self._mirror_source(app, rec)
                    outcome = upload_guarded(self.dest, path, app)
                    if outcome == "conflict":
                        rec["status"] = "conflict"
                        rec["error"] = "object already exists with different content; not overwritten"
                        log.warning("%s: %s exists with other content - NOT overwritten", app.package, app.key)
                    else:
                        rec["status"] = "uploaded" if result.engines else "unscanned"
                        if outcome == "uploaded":
                            with self._lock:
                                self.bytes_uploaded += app.size
                        log.info("%s: %s -> %s", app.package, outcome, app.key)
        except Cancelled:
            return "cancelled"
        except ScanError as e:
            rec["error"] = f"scan error: {e}"
            log.error("%s: %s", app.package, rec["error"])
            if path:
                self._dispose(path)
                path = None
        except Exception as e:  # one package must never stop the others
            rec["error"] = f"{type(e).__name__}: {e}"
            log.error("%s: failed: %s", app.package, rec["error"])
        finally:
            if path and path.exists() and not self.keep_files:
                path.unlink()
        rec["updated_at"] = utc_now()
        self.catalog.record(rec)
        return rec["status"]

    def _mirror_source(self, app: App, rec: dict):
        """Copy the version's source tarball next to the APK (it disappears from F-Droid's repo later)."""
        key = source_key(app)
        existing = self.dest.head(key)
        if existing is None:
            spath = self.downloader.download_file(app.package + " (source)", app.src_file, app.src_sha256,
                                                  app.src_size, ".src.tar.gz")
            try:
                outcome = upload_object(self.dest, spath, key, key.rsplit("/", 1)[-1], app.src_sha256,
                                        object_metadata(app, app.src_sha256), "application/gzip")
            finally:
                if spath.exists() and not self.keep_files:
                    spath.unlink()
            if outcome == "uploaded":
                with self._lock:
                    self.bytes_uploaded += app.src_size
        else:
            outcome = "exists" if str(existing.get("sha256") or "").lower() == app.src_sha256 else "conflict"
        if outcome == "conflict":
            raise DownloadError(f"source object {key} exists with other content; not overwritten")
        rec["source_mirror_key"] = key
        rec["source_mirror_url"] = public_url(self.base_url, key)

    def _dispose(self, path: Path):
        if self.keep_quarantine:
            qdir = self.work_dir / "quarantine"
            qdir.mkdir(parents=True, exist_ok=True)
            os.replace(path, qdir / path.name)
        elif path.exists():
            path.unlink()

    def save_catalog(self):
        """Save catalog.json (+ CSV, + bucket copy). Never raises: a failed save must not stop the run."""
        text = None
        for attempt in range(3):
            try:
                text = self.catalog.save()
                break
            except OSError as e:  # Windows: file briefly locked by OneDrive / antivirus / indexer
                log.warning("Cannot save %s (attempt %d): %s", self.catalog.path, attempt + 1, e)
                time.sleep(SAVE_RETRY_DELAY)
        if text is None:
            log.error("Catalog NOT saved to %s / บันทึกแคตตาล็อกไม่สำเร็จ", self.catalog.path)
            text = self.catalog.to_json()
        try:
            self.catalog.export_csv(self.catalog.path.with_suffix(".csv"))
        except OSError as e:  # e.g. CSV open in Excel on Windows
            log.warning("Cannot write catalog.csv (open in Excel?): %s / ปิดไฟล์ใน Excel แล้วรัน catalog ใหม่", e)
        try:
            self.dest.put_text(self.catalog_key, text)
        except Exception as e:
            log.error("Cannot upload catalog to %s: %s", self.catalog_key, e)

    def run_all(self, apps: List[App], workers: int) -> Counter:
        counts = Counter()
        done = 0
        pool = ThreadPoolExecutor(max_workers=max(1, workers), thread_name_prefix="w")
        futures = {pool.submit(self.process, a): a for a in apps}
        pending = set(futures)
        try:
            while pending:
                finished, pending = wait(pending, timeout=1.0, return_when=FIRST_COMPLETED)
                for f in finished:
                    counts[f.result()] += 1
                    done += 1
                    if done % 10 == 0:
                        self.save_catalog()
        except KeyboardInterrupt:
            log.warning("Ctrl+C - stopping after current files, saving catalog / กำลังหยุดและบันทึกแคตตาล็อก")
            self.stop.set()
            for f in pending:
                f.cancel()
            self.save_catalog()  # save first, in case the user presses Ctrl+C again
            try:
                pool.shutdown(wait=True)
            except KeyboardInterrupt:
                log.warning("Not waiting for running files / ไม่รอไฟล์ที่ค้างอยู่")
            for f in pending:
                if f.done() and not f.cancelled():
                    counts[f.result()] += 1
                else:
                    counts["cancelled"] += 1
        finally:
            try:
                pool.shutdown(wait=not self.stop.is_set())
            except KeyboardInterrupt:
                pass
            self.save_catalog()
        return counts


# --------------------------------------------------------------------------
# Commands
# --------------------------------------------------------------------------

def resolve_work_dir(cfg: Config) -> Path:
    p = Path(cfg.get("run", "work_dir") or "work")
    return p if p.is_absolute() else SCRIPT_DIR / p


def load_index(cfg: Config, work_dir: Path) -> dict:
    return fetch_index(check_repo_url(cfg.get("source", "repo")), work_dir / "cache",
                       timeout=cfg.getint("source", "timeout") or 60,
                       retries=cfg.getint("source", "retries"))


def make_dest(cfg: Config):
    backend = (cfg.get("dest", "backend") or "r2").lower()
    if backend == "local":
        d = cfg.get("dest", "local_dir")
        if not d:
            raise ConfigError("--dest local needs --dest-dir PATH (or [dest] local_dir)")
        p = Path(d)
        return LocalDest(p if p.is_absolute() else SCRIPT_DIR / p)
    if backend == "r2":
        return R2Dest(cfg)
    raise ConfigError(f"Unknown destination backend: {backend}")


def open_dest(cfg: Config):
    """(destination, remote catalog text or None); bucket/credential problems become a clear ConfigError."""
    try:
        dest = make_dest(cfg)
        return dest, dest.get_text(cfg.get("dest", "catalog_key"))
    except ConfigError:
        raise
    except Exception as e:  # botocore ClientError / EndpointConnectionError / missing boto3 ...
        raise ConfigError(
            f"Cannot access the destination bucket: {type(e).__name__}: {e}\n"
            "เข้าถึง R2 bucket ไม่ได้ ตรวจสอบ R2_ACCOUNT_ID, Access Key ID, Secret Access Key, ชื่อ bucket "
            "และสิทธิ์ของ API token (Object Read & Write ใน bucket files)") from None


def gb(n: int) -> float:
    return n / (1024 ** 3)


def cmd_stats(cfg, args, index) -> int:
    args.limit = None
    sel = select_apps(index, cfg, args)
    report_unselected_packages(sel, args, index)
    lic = Counter(a.license or "?" for a in sel.selected)
    cat = Counter(c for a in sel.selected for c in a.categories)
    data = {
        "repo": cfg.get("source", "repo"),
        "packages_in_index": sel.total,
        "eligible": len(sel.eligible),
        "selected": len(sel.selected),
        "excluded": dict(sorted(sel.excluded.items(), key=lambda kv: -kv[1])),
        "excluded_packages": sel.total - len(sel.eligible),
        "games": sum(1 for a in sel.selected if is_game(a)),
        "selected_size_gb": round(gb(sum(a.size for a in sel.selected)), 2),
        "licenses": dict(lic.most_common(20)),
        "categories": dict(cat.most_common(20)),
        "rejected_licenses": dict(sel.rejected_licenses.most_common(10)),
    }
    if args.json:
        print(json.dumps(data, ensure_ascii=False, indent=1))
        return 0
    print(f"Repo: {data['repo']}")
    print(f"แอปทั้งหมดในดัชนี / packages in index : {data['packages_in_index']}")
    print(f"ผ่านเกณฑ์ / eligible                 : {data['eligible']}")
    print(f"ถูกคัดออก / excluded                 : {data['excluded_packages']}")
    print(f"เลือกตามตัวกรอง / selected          : {data['selected']}")
    print(f"เกม / games                         : {data['games']}")
    print(f"ขนาดรวม / total size               : {data['selected_size_gb']:.2f} GB")
    for title, key in (("เหตุผลที่คัดออก / excluded by reason:", "excluded"),
                       ("สัญญาอนุญาต / licences (top 20):", "licenses"),
                       ("หมวดหมู่ / categories (top 20):", "categories"),
                       ("สัญญาที่ไม่ผ่าน / rejected licences (top 10):", "rejected_licenses")):
        print("\n" + title)
        for k, v in data[key].items():
            print(f"  {k:<32} {v}")
    return 0


def _short(s: str, n: int) -> str:
    return s if len(s) <= n else s[: n - 1] + "~"


def cmd_list(cfg, args, index) -> int:
    screen_limit = args.limit or 50
    if args.csv:
        sel = select_apps(index, cfg, args)
        rows = [[a.package, a.name, a.version_name, a.version_code, a.license, round(a.size / MB, 2),
                 "; ".join(a.categories), "; ".join(a.anti_features), a.key] for a in sel.selected]
        write_csv_atomic(Path(args.csv), ["package", "name", "version", "versionCode", "license", "size_mb",
                                          "categories", "antiFeatures", "key"], rows)
        print(f"Wrote {len(sel.selected)} rows to {args.csv}")
    args.limit = screen_limit
    sel = select_apps(index, cfg, args)
    report_unselected_packages(sel, args, index)
    print(f"{'package':<34} {'name':<24} {'version':<12} {'licence':<16} {'MB':>7}  key")
    for a in sel.selected:
        print(f"{_short(a.package, 34):<34} {_short(a.name, 24):<24} {_short(a.version_name, 12):<12} "
              f"{_short(a.license, 16):<16} {a.size / MB:7.1f}  {a.key}")
    print(f"({len(sel.selected)} shown)")
    return 0


def check_scan_policy(cfg: Config, args):
    """Unscanned files may only go to a local preview folder unless explicitly allowed."""
    scan = (cfg.get("scan", "scan") or "clamav").lower().replace(" ", "")
    backend = (cfg.get("dest", "backend") or "r2").lower()
    if scan == "none" and backend != "local" and not getattr(args, "allow_unscanned", False):
        raise ConfigError(
            "--scan none is refused for R2: files would be published WITHOUT a virus scan.\n"
            "ไม่อนุญาตให้อัปโหลดขึ้น R2 โดยไม่สแกนไวรัส -- ติดตั้ง ClamAV หรือใช้ --scan none คู่กับ --dest local "
            "เพื่อทดสอบเท่านั้น (ถ้ายืนยันจริง ๆ ใช้ --allow-unscanned)")


def cmd_run(cfg, args, index, work_dir: Path) -> int:
    started = time.monotonic()
    check_scan_policy(cfg, args)
    sel = select_apps(index, cfg, args, apply_limit=False)  # limit applies to the work left, below
    report_unselected_packages(sel, args, index)
    catalog = Catalog(work_dir / "catalog.json")
    catalog.source = cfg.get("source", "repo")
    catalog.load()
    backend = (cfg.get("dest", "backend") or "r2").lower()
    dest = scanner = None
    if args.dry_run:
        if backend == "r2" and all(cfg.get("r2", o) for o in ("account_id", "access_key_id", "secret_access_key")):
            try:
                remote = open_dest(cfg)[1]
                if remote:
                    catalog.merge_json(remote)
            except ConfigError as e:
                log.warning("Could not read remote catalog: %s", e)
    else:
        scanner = Scanner(cfg)
        scanner.check_available()
        dest, remote = open_dest(cfg)
        if remote:
            catalog.merge_json(remote)

    todo, skipped = [], 0
    for a in sel.selected:
        why = catalog.skip_reason(a, getattr(args, "retry", False))
        if why:
            skipped += 1
            log.debug("%s: skip (%s)", a.package, why)
        else:
            todo.append(a)
    todo.sort(key=catalog.failed_before)  # stable: new packages first, so --limit runs make progress
    if args.limit:
        todo = todo[:args.limit]

    if args.dry_run:
        for a in todo:
            print(f"[dry-run] {a.package} {a.version_name} ({a.size / MB:.1f} MB) -> {a.key} : "
                  "download + scan + upload")
        print(f"[dry-run] {len(todo)} to upload, {skipped} already done/skipped, "
              f"{gb(sum(a.size for a in todo)):.2f} GB")
        return 0

    catalog.mark_withdrawn(sel, index, cfg.get("source", "repo"))
    primary = cfg.get("source", "repo")
    mirrors = download_mirrors(index, primary, cfg.getlist("source", "mirrors"),
                               cfg.getbool("source", "use_index_mirrors"))
    downloader = Downloader(primary, mirrors, work_dir / "downloads",
                            timeout=cfg.getint("source", "timeout") or 60, retries=cfg.getint("source", "retries"))
    runner = Runner(cfg, work_dir, downloader, scanner, dest, catalog,
                    keep_files=args.keep_files or cfg.getbool("run", "keep_files"))
    log.info("Processing %d packages (%d already done) with %d worker(s), %d mirror(s) / เริ่มประมวลผล",
             len(todo), skipped, cfg.getint("run", "workers"), len(mirrors))
    counts = runner.run_all(todo, cfg.getint("run", "workers"))
    counts["skipped"] += skipped
    elapsed = time.monotonic() - started
    print("\n=== สรุป / Summary ===")
    for status in ("uploaded", "unscanned", "skipped", "conflict", "infected", "failed", "cancelled"):
        if counts.get(status):
            print(f"  {status:<10} {counts[status]}")
    print(f"  data       {runner.bytes_uploaded / MB:.1f} MB uploaded")
    print(f"  elapsed    {elapsed:.0f} s")
    print(f"  catalog    {catalog.path}")
    return 1 if (counts.get("failed") or counts.get("infected") or counts.get("cancelled")) else 0


def cmd_catalog(cfg, args, work_dir: Path) -> int:
    catalog = Catalog(work_dir / "catalog.json")
    if not catalog.path.is_file():
        print(f"No catalog yet: {catalog.path} / ยังไม่มีแคตตาล็อก")
        return 1
    catalog.load()
    out = work_dir / "catalog.csv"
    catalog.export_csv(out)
    print(f"Wrote {len(catalog.packages)} rows to {out}")
    return 0


# --------------------------------------------------------------------------
# CLI
# --------------------------------------------------------------------------

def positive_int(text: str) -> int:
    try:
        value = int(text)
    except ValueError:
        value = 0
    if value < 1:
        raise argparse.ArgumentTypeError(f"must be a whole number of 1 or more, got {text!r}")
    return value


def add_global_options(p: argparse.ArgumentParser, sub: bool):
    d = {"default": argparse.SUPPRESS} if sub else {}
    p.add_argument("--config", help="config file (default: config.ini next to this script)", **d)
    p.add_argument("--repo", help="F-Droid repo URL (default https://f-droid.org/repo)", **d)
    p.add_argument("--work-dir", help="working folder for cache, downloads, logs, catalog", **d)
    p.add_argument("-v", "--verbose", action="store_true", **d)


def add_filter_options(p: argparse.ArgumentParser):
    p.add_argument("--limit", type=positive_int, help="process at most N packages (N >= 1)")
    p.add_argument("--packages", help="comma separated package ids")
    p.add_argument("--category", action="append", help="category name (repeatable, case-insensitive)")
    p.add_argument("--games", action="store_true", help="only categories containing 'Game'")
    p.add_argument("--search", help="text in name / package / summary")
    p.add_argument("--max-size-mb", type=float, help="skip APKs larger than this (default 1024)")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="foss_mirror.py",
                                     description="Mirror open-source APKs from F-Droid to Cloudflare R2.")
    add_global_options(parser, sub=False)
    subs = parser.add_subparsers(dest="command", required=True)
    for name, help_text in (("stats", "count apps (no download)"), ("list", "preview apps and target names"),
                            ("run", "download, verify, scan and upload"), ("catalog", "re-export catalog.csv")):
        sp = subs.add_parser(name, help=help_text)
        add_global_options(sp, sub=True)
        if name != "catalog":
            add_filter_options(sp)
        if name == "stats":
            sp.add_argument("--json", action="store_true", help="machine readable output")
        if name == "list":
            sp.add_argument("--csv", help="also write the full selection to this CSV file")
        if name == "run":
            sp.add_argument("--dry-run", action="store_true", help="only show what would happen")
            sp.add_argument("--dest", choices=["r2", "local"], help="destination backend (default r2)")
            sp.add_argument("--dest-dir", help="folder for --dest local")
            sp.add_argument("--scan", help="clamav | virustotal | clamav+virustotal | none")
            sp.add_argument("--allow-unscanned", action="store_true",
                            help="DANGEROUS: allow --scan none with R2 (files published without virus scan)")
            sp.add_argument("--retry", action="store_true",
                            help="also retry versions recorded as infected or conflict")
            sp.add_argument("--workers", type=positive_int, help="parallel downloads (default 2; keep it low)")
            sp.add_argument("--keep-files", action="store_true", help="keep downloaded APKs after upload")
    return parser


def apply_cli_overrides(cfg: Config, args):
    pairs = [("repo", "source", "repo"), ("work_dir", "run", "work_dir"),
             ("max_size_mb", "filter", "max_size_mb"), ("dest", "dest", "backend"),
             ("dest_dir", "dest", "local_dir"), ("scan", "scan", "scan"), ("workers", "run", "workers")]
    for attr, section, option in pairs:
        value = getattr(args, attr, None)
        if value is not None:
            if attr in ("work_dir", "dest_dir"):
                value = Path(value).resolve()  # command-line paths are relative to the current folder
            cfg.set(section, option, value)


def _utf8_console():
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            try:
                stream.reconfigure(encoding="utf-8", errors="replace")
            except (ValueError, OSError):
                pass


def close_logging():
    for h in list(log.handlers):
        log.removeHandler(h)
        h.close()


def main(argv=None) -> int:
    try:
        return _main(argv)
    finally:
        close_logging()


def _report_config_file(config_path: Path):
    if config_path.is_file():
        log.info("Using config: %s", config_path)
        return
    log.info("config.ini not found at %s - using defaults / ไม่พบ config.ini ใช้ค่าเริ่มต้น", config_path)
    for alt in (config_path.with_name(config_path.name + ".ini"), config_path.with_name(config_path.name + ".txt")):
        if alt.is_file():
            log.warning("Found %s - rename it to %s (Windows hides file extensions) / "
                        "พบไฟล์ %s ให้เปลี่ยนชื่อเป็น %s (เปิด View > File name extensions ใน Explorer)",
                        alt.name, config_path.name, alt.name, config_path.name)


def _main(argv=None) -> int:
    _utf8_console()
    args = build_parser().parse_args(argv)
    config_path = Path(getattr(args, "config", None) or SCRIPT_DIR / "config.ini")
    if getattr(args, "config", None) and not config_path.is_file():
        print(f"Config file not found: {config_path} / ไม่พบไฟล์ config", file=sys.stderr)
        return 2
    try:
        cfg = Config(config_path)
        apply_cli_overrides(cfg, args)
        work_dir = resolve_work_dir(cfg)
        work_dir.mkdir(parents=True, exist_ok=True)
    except (ConfigError, OSError) as e:
        print(f"ERROR: {e}", file=sys.stderr)
        return 2
    setup_logging(work_dir, getattr(args, "verbose", False), cfg.secrets())
    _report_config_file(config_path)
    log.debug("Effective config:\n%s", cfg.dump_masked())
    try:
        if args.command == "catalog":
            return cmd_catalog(cfg, args, work_dir)
        index = load_index(cfg, work_dir)
        if args.command == "stats":
            return cmd_stats(cfg, args, index)
        if args.command == "list":
            return cmd_list(cfg, args, index)
        return cmd_run(cfg, args, index, work_dir)
    except (ConfigError, ScanError) as e:
        log.error("ERROR: %s", e)
        return 2
    except (KeyError, ValueError) as e:  # bad template placeholder / unsafe key template
        log.error("ERROR in naming templates or config: %s / ตรวจสอบ [naming] ใน config.ini", e)
        return 2
    except OSError as e:  # e.g. a CSV that is open in Excel
        log.error("File error: %s / เขียนไฟล์ไม่ได้ - ถ้าเปิดไฟล์อยู่ใน Excel ให้ปิดก่อนแล้วลองใหม่", e)
        return 2
    except KeyboardInterrupt:
        log.warning("Interrupted / ยกเลิกแล้ว")
        return 130


if __name__ == "__main__":
    sys.exit(main())
