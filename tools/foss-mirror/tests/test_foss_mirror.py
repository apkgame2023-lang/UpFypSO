"""Offline tests for foss_mirror.py (run: python -m pytest -q tests  or  python -m unittest)."""
import contextlib
import functools
import hashlib
import io
import json
import os
import shutil
import sys
import tempfile
import threading
import time
import unittest
from collections import namedtuple
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

import foss_mirror as fm  # noqa: E402

FIXTURE_REPO = HERE / "fixtures" / "repo"
FAKE_CLAMSCAN = str(HERE / "fixtures" / "fake_clamscan.py")
MB = 1024 * 1024


def setUpModule():
    fm.BACKOFF_BASE = 0
    fm.SAVE_RETRY_DELAY = 0
    for var in ("NO_PROXY", "no_proxy"):
        os.environ[var] = "127.0.0.1,localhost"


# ---------------------------------------------------------------- helpers

class RepoHandler(SimpleHTTPRequestHandler):
    """Serves the fixture repo; index-v2.json gets an ETag and honours If-None-Match."""

    def log_message(self, *args):
        pass

    def do_GET(self):
        if self.path.startswith("/badmirror/"):  # a half-synced mirror: 200 with the wrong bytes
            self.server.statuses.append("badmirror")
            body = b"truncated"
            self.send_response(200)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        if self.path.endswith("/index-v2.json") and getattr(self.server, "html_index", False):
            body = b"<html>captive portal</html>"  # error page with status 200
            self.send_response(200)
            self.send_header("Last-Modified", "Wed, 01 Jan 2025 00:00:00 GMT")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        if self.path.endswith("/index-v2.json"):
            body = (FIXTURE_REPO / "index-v2.json").read_bytes()
            etag = '"%s"' % hashlib.sha256(body).hexdigest()[:16]
            if self.headers.get("If-None-Match") == etag:
                self.server.statuses.append(304)
                self.send_response(304)
                self.send_header("ETag", etag)
                self.end_headers()
                return
            self.server.statuses.append(200)
            self.send_response(200)
            self.send_header("ETag", etag)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        if self.path.startswith("/fdroid/repo/"):
            self.path = self.path[len("/fdroid/repo"):]
        super().do_GET()


class RepoServer:
    def __enter__(self):
        handler = functools.partial(RepoHandler, directory=str(FIXTURE_REPO))
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), handler)
        self.httpd.statuses = []
        self.thread = threading.Thread(target=self.httpd.serve_forever, daemon=True)
        self.thread.start()
        self.url = "http://127.0.0.1:%d/fdroid/repo" % self.httpd.server_address[1]
        return self

    def __exit__(self, *exc):
        self.httpd.shutdown()
        self.httpd.server_close()


def make_cfg(**options):
    """Config with overrides given as section__option=value."""
    cfg = fm.Config(None)
    for k, v in options.items():
        section, option = k.split("__")
        cfg.set(section, option, v)
    return cfg


def load_fixture_index():
    return json.loads((FIXTURE_REPO / "index-v2.json").read_text("utf-8"))


def make_app(package="org.x.app", name="App", version="1.0", vc=1, data=b"hello", **kw):
    return fm.App(package=package, name=name, summary=kw.get("summary", ""), license=kw.get("license", "MIT"),
                  categories=kw.get("categories", []), anti_features=[], version_name=version, version_code=vc,
                  sha256=hashlib.sha256(data).hexdigest(), size=len(data), file_name=kw.get("file_name", "/x.apk"))


def v(vc, name=None, native=None, beta=False, file_name=None):
    man = {"versionCode": vc, "versionName": str(vc)}
    if native:
        man["nativecode"] = native
    d = {"file": {"name": file_name or "/%s_%d.apk" % (name or "a", vc)}, "manifest": man}
    if beta:
        d["releaseChannels"] = ["Beta"]
    return d


class TempDirTest(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp(prefix="fossmirror-"))

    def tearDown(self):
        fm.close_logging()
        shutil.rmtree(self.tmp, ignore_errors=True)


# ---------------------------------------------------------------- selection

class VersionSelectionTest(unittest.TestCase):
    def test_highest_version_code(self):
        versions = {"a": v(5), "b": v(7), "c": v(6)}
        self.assertEqual(fm.pick_version({}, versions)["manifest"]["versionCode"], 7)

    def test_suggested_version_code_caps_choice(self):
        versions = {"a": v(5), "b": v(7), "c": v(6)}
        chosen = fm.pick_version({"suggestedVersionCode": "6"}, versions)
        self.assertEqual(chosen["manifest"]["versionCode"], 6)

    def test_beta_release_channel_skipped(self):
        versions = {"a": v(3), "b": v(4, beta=True)}
        self.assertEqual(fm.pick_version({}, versions)["manifest"]["versionCode"], 3)

    def test_prefers_universal_apk(self):
        versions = {"a": v(10, "arm", ["arm64-v8a"]), "b": v(10, "uni"), "c": v(10, "v7", ["armeabi-v7a"])}
        self.assertFalse(fm.pick_version({}, versions)["manifest"].get("nativecode"))

    def test_prefers_configured_abi_without_universal(self):
        versions = {"a": v(10, "v7", ["armeabi-v7a"]), "b": v(10, "arm", ["arm64-v8a"]), "c": v(9, "uni")}
        self.assertEqual(fm.pick_version({}, versions)["manifest"]["nativecode"], ["arm64-v8a"])
        chosen = fm.pick_version({}, versions, preferred_abi="armeabi-v7a")
        self.assertEqual(chosen["manifest"]["nativecode"], ["armeabi-v7a"])

    def test_any_abi_as_last_resort(self):
        versions = {"a": v(10, "x86", ["x86"]), "b": v(10, "x86_64", ["x86_64"])}
        self.assertIsNotNone(fm.pick_version({}, versions))

    def test_non_apk_only_returns_none(self):
        self.assertIsNone(fm.pick_version({}, {"a": v(1, file_name="/ota.zip")}))
        self.assertIsNone(fm.pick_version({}, {}))


class LocalisationTest(unittest.TestCase):
    def test_fallback_order(self):
        self.assertEqual(fm.localized({"de": "D", "en": "E", "en-US": "US"}), "US")
        self.assertEqual(fm.localized({"de": "D", "en": "E"}), "E")
        self.assertEqual(fm.localized({"th": "ไทย"}), "ไทย")
        self.assertEqual(fm.localized("plain"), "plain")
        self.assertEqual(fm.localized(None, "x"), "x")


class LicenseTest(unittest.TestCase):
    def test_expressions(self):
        self.assertTrue(fm.license_allowed("GPL-3.0-only OR Apache-2.0"))
        self.assertTrue(fm.license_allowed("(MIT AND BSD-3-Clause)"))
        self.assertTrue(fm.license_allowed("GPL-2.0+"))
        self.assertTrue(fm.license_allowed("Apache-2.0 WITH LLVM-exception"))
        self.assertTrue(fm.license_allowed("agpl-3.0-or-later"))
        self.assertFalse(fm.license_allowed("MIT AND Proprietary"))
        self.assertFalse(fm.license_allowed("LicenseRef-custom"))
        self.assertFalse(fm.license_allowed(""))
        self.assertFalse(fm.license_allowed(None))

    def test_extra_and_allow_any(self):
        self.assertTrue(fm.license_allowed("Foo-1.0", extra=["foo-1.0"]))
        self.assertTrue(fm.license_allowed("Proprietary", allow_any=True))
        self.assertFalse(fm.license_allowed("", allow_any=True))


class FixtureSelectionTest(unittest.TestCase):
    def setUp(self):
        self.index = load_fixture_index()

    def test_exclusions_by_reason(self):
        sel = fm.select_apps(self.index, make_cfg())
        self.assertEqual(sel.total, 12)
        self.assertEqual(sel.excluded["license"], 2)
        self.assertEqual(sel.excluded["antifeature:Tracking"], 1)
        self.assertEqual(sel.excluded["no_versions"], 1)
        eligible = {a.package for a in sel.eligible}
        self.assertNotIn("org.example.tracker", eligible)
        self.assertIn("org.example.nonet", eligible)  # NonFreeNet allowed but recorded
        nonet = next(a for a in sel.eligible if a.package == "org.example.nonet")
        self.assertEqual(nonet.anti_features, ["NonFreeNet"])

    def test_antifeature_list_is_configurable(self):
        sel = fm.select_apps(self.index, make_cfg(filter__exclude_antifeatures=""))
        self.assertIn("org.example.tracker", {a.package for a in sel.eligible})

    def test_package_level_antifeatures_are_merged(self):
        self.assertEqual(fm.anti_features({"antiFeatures": ["Ads"]}, {"antiFeatures": {"Tracking": {}}}),
                         ["Ads", "Tracking"])

    def test_too_big(self):
        sel = fm.select_apps(self.index, make_cfg(filter__max_size_mb="0.00001"))
        self.assertGreater(sel.excluded["too_big"], 0)

    def test_user_filters_and_sort(self):
        cfg = make_cfg()
        Args = namedtuple("Args", "packages category games search limit")
        games = fm.select_apps(self.index, cfg, Args(None, None, True, None, None)).selected
        self.assertEqual([a.package for a in games], ["org.example.digits", "org.example.game"])
        cat = fm.select_apps(self.index, cfg, Args(None, ["writing"], False, None, None)).selected
        self.assertEqual({a.package for a in cat}, {"org.example.notes", "org.example.notes2"})
        found = fm.select_apps(self.index, cfg, Args(None, None, False, "super", None)).selected
        self.assertEqual([a.package for a in found], ["org.example.game"])
        some = fm.select_apps(self.index, cfg, Args("org.example.thai,org.example.nonet", None, False, None, 1))
        self.assertEqual([a.package for a in some.selected], ["org.example.nonet"])

    def test_chosen_versions(self):
        sel = fm.select_apps(self.index, make_cfg())
        by = {a.package: a for a in sel.eligible}
        self.assertEqual(by["org.example.notes"].version_code, 3)  # beta 4 skipped
        self.assertEqual(by["org.example.game"].file_name, "/org.example.game_10.apk")  # universal
        self.assertEqual(by["org.example.game"].name, "Super Game")


# ---------------------------------------------------------------- naming

class NamingTest(unittest.TestCase):
    def test_sanitize(self):
        self.assertEqual(fm.sanitize("Ünïcödé App_Name!!"), "Unicode-App-Name")
        self.assertEqual(fm.sanitize("  Hello   World  "), "Hello-World")
        self.assertEqual(fm.sanitize("แอปไทย"), "")
        self.assertEqual(fm.sanitize("../../etc/passwd"), "etc-passwd")
        self.assertEqual(fm.sanitize("1.0.0+12"), "1.0.0-12")
        self.assertEqual(fm.sanitize("3.2(45)"), "3.2-45")
        self.assertEqual(fm.sanitize("a..b"), "a.b")
        self.assertEqual(fm.sanitize("5.0 (ทดสอบ)"), "5.0")
        self.assertEqual(fm.sanitize("C:\\Windows\\x"), "CWindowsx")

    def test_validate_key_rejects_unsafe(self):
        for bad in ["../x.apk", "A/../x.apk", "A\\x.apk", "/A/x.apk", "A/x\x00.apk", "A/x\n.apk", "A//x.apk", ""]:
            with self.assertRaises(ValueError, msg=bad):
                fm.validate_key(bad)
        self.assertEqual(fm.validate_key("A/x.apk"), "A/x.apk")

    def test_render_default_names(self):
        cfg = make_cfg()
        self.assertEqual(fm.render_names(make_app(name="Angry Birds", version="3.25.0"), cfg),
                         ("Angry-Birds-v3.25.0-Y4APK.COM.apk", "A/Angry-Birds-v3.25.0-Y4APK.COM.apk"))
        self.assertEqual(fm.render_names(make_app(name="2048 Puzzle", version="7.0"), cfg)[1],
                         "A/2048-Puzzle-v7.0-Y4APK.COM.apk")
        thai = make_app(package="org.example.thai", name="แอปไทย", version="", vc=5)
        self.assertEqual(fm.render_names(thai, cfg)[1], "O/org.example.thai-v5-Y4APK.COM.apk")
        evil = make_app(name="../../secret", version="../1")
        self.assertEqual(fm.render_names(evil, cfg)[1], "S/secret-v1-Y4APK.COM.apk")
        self.assertEqual(fm.render_names(make_app(name="Tusky", version="v22.0"), cfg)[0],
                         "Tusky-v22.0-Y4APK.COM.apk")  # no "vv22.0"
        self.assertEqual(fm.render_names(make_app(name="Vim Touch", version="version 2"), cfg)[0],
                         "Vim-Touch-vversion-2-Y4APK.COM.apk")  # only v+digit is stripped

    def test_nonalpha_folder_and_templates(self):
        cfg = make_cfg(naming__nonalpha_folder="0-9",
                       naming__filename_template="{package}_{versionCode}.apk",
                       naming__key_template="foss/{folder}/{filename}")
        self.assertEqual(fm.render_names(make_app(package="a.b", name="9 Lives", vc=12), cfg)[1],
                         "foss/0-9/a.b_12.apk")

    def test_traversal_template_rejected(self):
        cfg = make_cfg(naming__key_template="{folder}/../{filename}")
        with self.assertRaises(ValueError):
            fm.render_names(make_app(), cfg)

    def test_key_collisions_are_deterministic(self):
        a = make_app(package="org.b.notes", name="Notes", version="1.0")
        b = make_app(package="org.a.notes", name="Notes", version="1.0")
        fm.assign_keys([a, b], make_cfg())
        self.assertEqual(b.key, "N/Notes-v1.0-Y4APK.COM.apk")
        self.assertEqual(a.key, "N/Notes-v1.0-Y4APK.COM-org.b.notes.apk")
        self.assertEqual(a.filename, "Notes-v1.0-Y4APK.COM-org.b.notes.apk")


# ---------------------------------------------------------------- download

class DownloadTest(TempDirTest):
    def test_good_download_and_sha_mismatch(self):
        sel = fm.select_apps(load_fixture_index(), make_cfg())
        by = {a.package: a for a in sel.eligible}
        with RepoServer() as srv:
            d = fm.Downloader(srv.url, [], self.tmp / "dl", retries=0)
            path = d.download(by["org.example.notes"])
            self.assertEqual(path.name, by["org.example.notes"].sha256 + ".apk")
            self.assertEqual(path.read_bytes(), (FIXTURE_REPO / "org.example.notes_3.apk").read_bytes())
            with self.assertRaises(fm.IntegrityError):
                d.download(by["org.example.badhash"])
        self.assertEqual(sorted(p.name for p in (self.tmp / "dl").iterdir()), [path.name])  # no .part left

    def test_size_mismatch(self):
        app = make_app(data=b"other", file_name="/org.example.notes_3.apk")
        with RepoServer() as srv:
            with self.assertRaises(fm.IntegrityError):
                fm.Downloader(srv.url, [], self.tmp, retries=0).download(app)

    def test_mirror_failure_falls_back_to_primary(self):
        data = (FIXTURE_REPO / "org.example.digits_7.apk").read_bytes()
        app = make_app(data=data, file_name="/org.example.digits_7.apk")
        with RepoServer() as srv:
            d = fm.Downloader(srv.url, ["http://127.0.0.1:1/repo"], self.tmp, retries=0)
            self.assertEqual(d.download(app).read_bytes(), data)

    def test_disk_space_check(self):
        Usage = namedtuple("Usage", "total used free")
        with mock.patch.object(fm.shutil, "disk_usage", return_value=Usage(0, 0, 10)):
            with self.assertRaises(fm.DownloadError) as ctx:
                fm.Downloader("http://127.0.0.1:1", [], self.tmp, retries=0).download(make_app())
        self.assertIn("disk", str(ctx.exception))

    def test_unsafe_index_file_name(self):
        with self.assertRaises(fm.DownloadError):
            fm.Downloader("http://x", [], self.tmp).download(make_app(file_name="/../../etc/passwd"))

    def test_mirror_list(self):
        index = load_fixture_index()
        primary = "https://repo.example.invalid/fdroid/repo"
        self.assertEqual(fm.download_mirrors(index, primary, ["https://my.mirror/repo/"], True),
                         ["https://my.mirror/repo", "https://mirror.example.invalid/fdroid/repo"])
        self.assertEqual(fm.download_mirrors(index, primary, [], False), [])

    def test_index_cache_uses_etag(self):
        with RepoServer() as srv:
            first = fm.fetch_index(srv.url, self.tmp / "cache", retries=0)
            second = fm.fetch_index(srv.url, self.tmp / "cache", retries=0)
            self.assertEqual(srv.httpd.statuses, [200, 304])
        self.assertEqual(first, second)
        self.assertIn("org.example.notes", second["packages"])


# ---------------------------------------------------------------- scanning

def scan_cfg(**kw):
    base = {"scan__scan": "clamav", "scan__clamscan_path": FAKE_CLAMSCAN, "virustotal__requests_per_minute": "0",
            "virustotal__poll_interval": "0"}
    base.update(kw)
    return make_cfg(**base)


class ClamAVTest(TempDirTest):
    def _file(self, content):
        p = self.tmp / "f.apk"
        p.write_bytes(content)
        return p

    def test_exit_codes(self):
        s = fm.Scanner(scan_cfg())
        s.check_available()
        clean = s.scan(self._file(b"clean"), "0" * 64)
        self.assertFalse(clean.infected)
        self.assertEqual(clean.engines, ["clamav"])
        bad = s.scan(self._file(b"xx VIRUS-TEST-SIGNATURE xx"), "0" * 64)
        self.assertTrue(bad.infected)
        self.assertIn("FOUND", bad.detail)
        with self.assertRaises(fm.ScanError):
            s.scan(self._file(b"SCANNER-ERROR"), "0" * 64)

    def test_command_is_a_list_without_shell(self):
        s = fm.Scanner(scan_cfg(scan__clamscan_path="C:\\Program Files\\ClamAV\\clamscan.exe"))
        done = mock.Mock(returncode=0, stdout="OK", stderr="")
        with mock.patch.object(fm.subprocess, "run", return_value=done) as run:
            s.clamav(self.tmp / "a b.apk")
        cmd = run.call_args[0][0]
        self.assertEqual(cmd[0], "C:\\Program Files\\ClamAV\\clamscan.exe")
        self.assertEqual(cmd[-2:], ["--no-summary", str(self.tmp / "a b.apk")])
        self.assertIn("--max-filesize=1024M", cmd)
        self.assertNotIn("shell", run.call_args[1])

    def test_missing_scanner(self):
        s = fm.Scanner(scan_cfg(scan__clamscan_path=str(self.tmp / "nope" / "clamscan.exe")))
        with self.assertRaises(fm.ScanError) as ctx:
            s.check_available()
        self.assertIn("ClamAV", str(ctx.exception))
        self.assertIn("ไม่พบ", str(ctx.exception))

    def test_scan_none(self):
        s = fm.Scanner(scan_cfg(scan__scan="none"))
        s.check_available()
        self.assertEqual(s.scan(self._file(b"VIRUS-TEST-SIGNATURE"), "0" * 64).engines, [])

    def test_bad_mode(self):
        with self.assertRaises(fm.ConfigError):
            fm.Scanner(scan_cfg(scan__scan="norton"))


def resp(status, data=None):
    r = mock.Mock()
    r.status_code = status
    r.json.return_value = data or {}
    return r


def vt_stats(malicious):
    return resp(200, {"data": {"attributes": {"last_analysis_stats": {"malicious": malicious, "harmless": 60}}}})


def vt_done(malicious, sha="a" * 64, stats=None):
    stats = {"malicious": malicious, "undetected": 60} if stats is None else stats
    return resp(200, {"data": {"attributes": {"status": "completed", "stats": stats}},
                      "meta": {"file_info": {"sha256": sha}}})


class VirusTotalTest(TempDirTest):
    def scanner(self, responses, **kw):
        session = mock.Mock()
        session.request.side_effect = responses
        opts = {"scan__scan": "virustotal", "virustotal__api_key": "test-key"}
        opts.update(kw)
        return fm.Scanner(scan_cfg(**opts), session=session), session

    def apk(self, size=10):
        p = self.tmp / "a.apk"
        with open(p, "wb") as fh:
            fh.truncate(size)
        return p

    def test_known_file_verdicts(self):
        s, session = self.scanner([vt_stats(2)])
        r = s.scan(self.apk(), "a" * 64)
        self.assertFalse(r.infected)
        self.assertEqual(r.malicious_count, 2)
        method, url = session.request.call_args[0]
        self.assertEqual(url, fm.VT_API + "/files/" + "a" * 64)
        self.assertEqual(session.request.call_args[1]["headers"]["x-apikey"], "test-key")
        s, _ = self.scanner([vt_stats(3)])
        self.assertTrue(s.scan(self.apk(), "a" * 64).infected)

    def test_unknown_without_upload(self):
        s, _ = self.scanner([resp(404)], virustotal__upload_unknown="false")
        with self.assertRaises(fm.ScanError):
            s.scan(self.apk(), "a" * 64)
        s, _ = self.scanner([resp(404)], virustotal__upload_unknown="false", scan__scan="clamav+virustotal")
        r = s.scan(self.apk(), "a" * 64)
        self.assertEqual((r.infected, r.engines), (False, ["clamav"]))

    def test_unknown_small_upload_and_poll(self):
        s, session = self.scanner([
            resp(404), resp(200, {"data": {"id": "an-1"}}),
            resp(200, {"data": {"attributes": {"status": "queued"}}}),
            vt_done(0),
        ])
        r = s.scan(self.apk(), "a" * 64)
        self.assertEqual((r.infected, r.malicious_count), (False, 0))
        urls = [c[0][:2] for c in session.request.call_args_list]
        self.assertEqual(urls[1], ("POST", fm.VT_API + "/files"))
        self.assertEqual(urls[3], ("GET", fm.VT_API + "/analyses/an-1"))

    def test_large_upload_uses_upload_url(self):
        s, session = self.scanner([
            resp(404), resp(200, {"data": "https://upload.example/u1"}), resp(200, {"data": {"id": "an-2"}}),
            vt_done(5),
        ])
        r = s.scan(self.apk(33 * MB), "a" * 64)
        self.assertTrue(r.infected)
        self.assertEqual(session.request.call_args_list[2][0][:2], ("POST", "https://upload.example/u1"))

    def test_bad_key(self):
        s, _ = self.scanner([resp(401)])
        with self.assertRaises(fm.ScanError):
            s.scan(self.apk(), "a" * 64)

    def test_rate_limiter_spacing(self):
        rl = fm.RateLimiter(600)  # one call per 0.1 s
        start = time.monotonic()
        for _ in range(3):
            rl.acquire()
        self.assertGreaterEqual(time.monotonic() - start, 0.18)


# ---------------------------------------------------------------- destinations

class FakeClientError(Exception):
    def __init__(self, code, status):
        super().__init__(code)
        self.response = {"Error": {"Code": code}, "ResponseMetadata": {"HTTPStatusCode": status}}


class FakeS3:
    def __init__(self):
        self.objects = {}
        self.uploads = []

    def head_object(self, Bucket, Key):
        if Key not in self.objects:
            raise FakeClientError("404", 404)
        return {"Metadata": self.objects[Key][1]}

    def upload_file(self, Filename, Bucket, Key, ExtraArgs=None, Config=None):
        self.uploads.append((Bucket, Key, ExtraArgs))
        self.objects[Key] = (Path(Filename).read_bytes(), dict(ExtraArgs["Metadata"]))

    def get_object(self, Bucket, Key):
        if Key not in self.objects:
            raise FakeClientError("NoSuchKey", 404)
        return {"Body": io.BytesIO(self.objects[Key][0])}

    def put_object(self, Bucket, Key, Body, ContentType):
        self.objects[Key] = (Body, {})


class R2Test(TempDirTest):
    def setUp(self):
        super().setUp()
        self.s3 = FakeS3()
        self.dest = fm.R2Dest(make_cfg(r2__bucket="files"), client=self.s3)
        self.file = self.tmp / "x.apk"
        self.file.write_bytes(b"hello")
        self.app = make_app(name="Hello", data=b"hello", license="GPL-3.0-only")
        fm.assign_keys([self.app], make_cfg())

    def test_upload_then_exists(self):
        self.assertEqual(fm.upload_guarded(self.dest, self.file, self.app), "uploaded")
        bucket, key, extra = self.s3.uploads[0]
        self.assertEqual((bucket, key), ("files", "H/Hello-v1.0-Y4APK.COM.apk"))
        self.assertEqual(extra["ContentType"], "application/vnd.android.package-archive")
        self.assertEqual(extra["ContentDisposition"], 'attachment; filename="Hello-v1.0-Y4APK.COM.apk"')
        self.assertEqual(extra["Metadata"]["sha256"], self.app.sha256)
        self.assertEqual(extra["Metadata"]["source"], "f-droid")
        self.assertEqual(fm.upload_guarded(self.dest, self.file, self.app), "exists")
        self.assertEqual(len(self.s3.uploads), 1)

    def test_never_overwrites_foreign_object(self):
        self.s3.objects[self.app.key] = (b"mod apk", {})
        self.assertEqual(fm.upload_guarded(self.dest, self.file, self.app), "conflict")
        self.s3.objects[self.app.key] = (b"other", {"sha256": "b" * 64})
        self.assertEqual(fm.upload_guarded(self.dest, self.file, self.app), "conflict")
        self.assertEqual(self.s3.uploads, [])
        self.assertEqual(self.s3.objects[self.app.key][0], b"other")

    def test_head_errors_propagate(self):
        self.s3.head_object = mock.Mock(side_effect=FakeClientError("AccessDenied", 403))
        with self.assertRaises(FakeClientError):
            fm.upload_guarded(self.dest, self.file, self.app)

    def test_catalog_text_roundtrip(self):
        self.assertIsNone(self.dest.get_text("catalog/foss-apps.json"))
        self.dest.put_text("catalog/foss-apps.json", '{"packages": {}}')
        self.assertEqual(self.dest.get_text("catalog/foss-apps.json"), '{"packages": {}}')

    def test_real_client_config(self):
        cfg = make_cfg(r2__account_id="acc123", r2__access_key_id="AKIDTEST", r2__secret_access_key="SECRET1234")
        client = fm.make_r2_client(cfg)
        self.assertEqual(client.meta.endpoint_url, "https://acc123.r2.cloudflarestorage.com")
        self.assertEqual(client.meta.region_name, "auto")
        self.assertEqual(client.meta.config.request_checksum_calculation, "when_required")
        self.assertEqual(client.meta.config.response_checksum_validation, "when_required")
        with self.assertRaises(fm.ConfigError):
            fm.make_r2_client(make_cfg())

    def test_env_overrides_and_masking(self):
        env = {"R2_ACCESS_KEY_ID": "AKIDFROMENV", "R2_SECRET_ACCESS_KEY": "supersecretvalue"}
        with mock.patch.dict(os.environ, env):
            cfg = fm.Config(None)
        self.assertEqual(cfg.get("r2", "access_key_id"), "AKIDFROMENV")
        dump = cfg.dump_masked()
        self.assertNotIn("supersecretvalue", dump)
        self.assertNotIn("AKIDFROMENV", dump)
        f = fm.SecretMaskFilter(cfg.secrets())
        rec = fm.logging.LogRecord("x", 20, "p", 1, "key=%s", ("supersecretvalue",), None)
        f.filter(rec)
        self.assertEqual(rec.getMessage(), "key=***")


# ---------------------------------------------------------------- catalog

class CatalogTest(TempDirTest):
    def test_skip_resume_and_last_attempt(self):
        cat = fm.Catalog(self.tmp / "catalog.json")
        app = make_app()
        self.assertFalse(cat.is_done(app))
        rec = fm.base_record(app, "https://dl.example.com")
        rec["status"] = "uploaded"
        cat.record(rec)
        self.assertTrue(cat.is_done(app))
        newer = make_app(vc=2, data=b"v2")
        self.assertFalse(cat.is_done(newer))
        fail = fm.base_record(newer, "")
        fail["error"] = "boom"
        cat.record(fail)
        kept = cat.get(app.package)
        self.assertEqual((kept["status"], kept["versionCode"]), ("uploaded", 1))
        self.assertEqual(kept["last_attempt"]["error"], "boom")

        cat.save()
        again = fm.Catalog(self.tmp / "catalog.json")
        again.load()
        self.assertTrue(again.is_done(app))

    def test_merge_newer_wins_and_csv(self):
        cat = fm.Catalog(self.tmp / "catalog.json")
        cat.record({"package": "p", "status": "failed", "updated_at": "2026-01-02T00:00:00+00:00"})
        cat.merge_json(json.dumps({"packages": {
            "p": {"package": "p", "status": "uploaded", "updated_at": "2026-01-01T00:00:00+00:00"},
            "q": {"package": "q", "status": "uploaded", "updated_at": "2026-01-01T00:00:00+00:00",
                  "categories": ["Games", "Puzzle"], "scan": {"engines": ["clamav"], "malicious_count": 0}}}}))
        self.assertEqual(cat.get("p")["status"], "failed")
        self.assertEqual(cat.get("q")["status"], "uploaded")
        cat.export_csv(self.tmp / "c.csv")
        raw = (self.tmp / "c.csv").read_bytes()
        self.assertTrue(raw.startswith(b"\xef\xbb\xbf"))
        self.assertIn("Games; Puzzle", raw.decode("utf-8-sig"))

    def test_public_url_quoting(self):
        self.assertEqual(fm.public_url("https://cdn.x/", "A/a b+c.apk"), "https://cdn.x/A/a%20b%2Bc.apk")
        self.assertIsNone(fm.public_url("", "A/x.apk"))


# ---------------------------------------------------------------- end to end

class EndToEndTest(TempDirTest):
    def write_config(self, **extra):
        cfg = self.tmp / "config.ini"
        lines = ["[source]", "use_index_mirrors = false", "retries = 0",
                 "[scan]", "scan = clamav", "clamscan_path = " + FAKE_CLAMSCAN]
        for k, v in extra.items():
            lines.append(f"{k} = {v}")
        cfg.write_text("\n".join(lines) + "\n", "utf-8")
        return cfg

    def run_main(self, srv, *args):
        out, err = io.StringIO(), io.StringIO()
        argv = ["--config", str(self.write_config()), "--repo", srv.url, "--work-dir", str(self.tmp / "work")]
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            rc = fm.main(argv + list(args))
        return rc, out.getvalue(), err.getvalue()

    def test_full_run_local_backend(self):
        dest = self.tmp / "bucket"
        with RepoServer() as srv:
            rc, out, err = self.run_main(srv, "run", "--dest", "local", "--dest-dir", str(dest))
            self.assertEqual(rc, 1, out + err)  # badhash failed + virus infected
            catalog = json.loads((self.tmp / "work" / "catalog.json").read_text("utf-8"))["packages"]
            status = {p: r["status"] for p, r in catalog.items()}
            self.assertEqual(status, {
                "org.example.notes": "uploaded", "org.example.notes2": "uploaded",
                "org.example.game": "uploaded", "org.example.thai": "uploaded",
                "org.example.digits": "uploaded", "org.example.nonet": "uploaded",
                "org.example.badhash": "failed", "org.example.virus": "infected"})
            expected = {
                "N/Notes-v1.0-Y4APK.COM.apk": "org.example.notes_3.apk",
                "N/Notes-v1.0-Y4APK.COM-org.example.notes2.apk": "org.example.notes2_1.apk",
                "S/Super-Game-v2.0-Y4APK.COM.apk": "org.example.game_10.apk",
                "O/org.example.thai-v5.0-Y4APK.COM.apk": "org.example.thai_5.apk",
                "A/2048-Puzzle-v7.0-Y4APK.COM.apk": "org.example.digits_7.apk",
                "N/Net-App-v0.2-Y4APK.COM.apk": "org.example.nonet_2.apk",
            }
            for key, src in expected.items():
                self.assertEqual((dest / key).read_bytes(), (FIXTURE_REPO / src).read_bytes(), key)
            apks = sorted(str(p.relative_to(dest)).replace("\\", "/") for p in dest.rglob("*.apk"))
            self.assertEqual(apks, sorted(expected))
            self.assertEqual(list((self.tmp / "work" / "downloads").glob("*.apk*")), [])
            self.assertTrue((dest / "catalog" / "foss-apps.json").is_file())
            self.assertTrue((self.tmp / "work" / "catalog.csv").is_file())
            self.assertIn("sha256 mismatch", catalog["org.example.badhash"]["error"])
            self.assertEqual(catalog["org.example.nonet"]["antiFeatures"], ["NonFreeNet"])
            self.assertEqual(catalog["org.example.notes"]["scan"], {"engines": ["clamav"], "malicious_count": 0})
            # a non-F-Droid repo (here: the local test server) must not be credited to f-droid.org
            self.assertIsNone(catalog["org.example.notes"]["fdroid_url"])
            self.assertEqual(catalog["org.example.notes"]["source_repo"], srv.url)
            self.assertEqual(catalog["org.example.notes"]["source_tarball_url"],
                             srv.url + "/org.example.notes_3_src.tar.gz")
            self.assertIsNone(catalog["org.example.digits"]["source_tarball_url"])
            # GPL corresponding source is mirrored next to the APK (F-Droid archives it later)
            src_key = "src/org.example.notes_3_src.tar.gz"
            self.assertEqual(catalog["org.example.notes"]["source_mirror_key"], src_key)
            self.assertEqual((dest / src_key).read_bytes(),
                             (FIXTURE_REPO / "org.example.notes_3_src.tar.gz").read_bytes())
            self.assertEqual(list((self.tmp / "work" / "downloads").iterdir()), [])
            self.assertTrue((self.tmp / "work" / "logs" / "foss-mirror.log").is_file())

            # second run: uploaded and infected versions are skipped, failures retried
            before = catalog["org.example.notes"]["updated_at"]
            virus_before = catalog["org.example.virus"]["updated_at"]
            rc2, out2, err2 = self.run_main(srv, "run", "--dest", "local", "--dest-dir", str(dest))
            self.assertEqual(rc2, 1)
            self.assertIn("skipped", out2)
            self.assertNotIn("org.example.virus: downloading", err2)
            again = json.loads((self.tmp / "work" / "catalog.json").read_text("utf-8"))["packages"]
            self.assertEqual(again["org.example.notes"]["updated_at"], before)
            self.assertEqual(again["org.example.virus"]["updated_at"], virus_before)

    def test_conflict_is_not_overwritten(self):
        dest = self.tmp / "bucket"
        (dest / "N").mkdir(parents=True)
        (dest / "N" / "Notes-v1.0-Y4APK.COM.apk").write_bytes(b"someone else's file")
        with RepoServer() as srv:
            rc, out, err = self.run_main(srv, "run", "--dest", "local", "--dest-dir", str(dest),
                                         "--packages", "org.example.notes")
        self.assertEqual(rc, 0, out + err)
        self.assertIn("conflict", out)
        self.assertNotIn("downloading", err)  # conflict detected before downloading
        self.assertEqual((dest / "N" / "Notes-v1.0-Y4APK.COM.apk").read_bytes(), b"someone else's file")

    def test_dry_run_downloads_nothing(self):
        with RepoServer() as srv:
            rc, out, _ = self.run_main(srv, "run", "--dry-run", "--dest", "local", "--dest-dir",
                                       str(self.tmp / "bucket"))
        self.assertEqual(rc, 0)
        self.assertIn("[dry-run] 8 to upload", out)
        self.assertFalse((self.tmp / "bucket").exists())

    def test_stats_json_and_list(self):
        with RepoServer() as srv:
            rc, out, _ = self.run_main(srv, "stats", "--json")
            self.assertEqual(rc, 0)
            data = json.loads(out)
            self.assertEqual((data["packages_in_index"], data["eligible"], data["games"]), (12, 8, 2))
            self.assertEqual(data["excluded"]["license"], 2)
            csv_path = self.tmp / "list.csv"
            rc, out, _ = self.run_main(srv, "list", "--games", "--csv", str(csv_path))
            self.assertEqual(rc, 0)
            self.assertIn("A/2048-Puzzle-v7.0-Y4APK.COM.apk", out)
            self.assertIn("org.example.game", csv_path.read_text("utf-8-sig"))

    def test_missing_scanner_stops_before_download(self):
        with RepoServer() as srv:
            argv = ["--repo", srv.url, "--work-dir", str(self.tmp / "work"), "run", "--dest", "local",
                    "--dest-dir", str(self.tmp / "b")]
            cfg = self.tmp / "c.ini"
            cfg.write_text("[scan]\nclamscan_path = %s\n" % (self.tmp / "missing-clamscan.exe"), "utf-8")
            with contextlib.redirect_stderr(io.StringIO()) as err, contextlib.redirect_stdout(io.StringIO()):
                rc = fm.main(["--config", str(cfg)] + argv)
        self.assertEqual(rc, 2)
        self.assertIn("ClamAV", err.getvalue())
        self.assertFalse((self.tmp / "work" / "downloads").exists())

    def test_ctrl_c_saves_catalog(self):
        cfg = scan_cfg(scan__scan="none")
        stop = threading.Event()
        dl = fm.Downloader("http://127.0.0.1:1", [], self.tmp / "dl", retries=0, stop_event=stop)
        cat = fm.Catalog(self.tmp / "catalog.json")
        runner = fm.Runner(cfg, self.tmp, dl, fm.Scanner(cfg), fm.LocalDest(self.tmp / "b"), cat)
        with mock.patch.object(fm, "wait", side_effect=KeyboardInterrupt):
            counts = runner.run_all([make_app(package="p%d" % i) for i in range(3)], 1)
        self.assertTrue(stop.is_set())
        self.assertEqual(sum(counts.values()), 3)
        self.assertTrue((self.tmp / "catalog.json").is_file())


# ---------------------------------------------------------------- review fixes

Args = namedtuple("Args", "packages category games search limit")


class SelectionFixesTest(unittest.TestCase):
    def test_abi_splits_with_different_version_codes(self):
        # F-Droid ABI splits: v7=+1, arm64=+2, x86=+3, x86_64=+4
        versions = {"a": v(1201, "v7", ["armeabi-v7a"]), "b": v(1202, "arm64", ["arm64-v8a"]),
                    "c": v(1203, "x86", ["x86"]), "d": v(1204, "x64", ["x86_64"])}
        self.assertEqual(fm.pick_version({}, versions)["manifest"]["versionCode"], 1202)
        self.assertEqual(fm.pick_version({}, versions, "armeabi-v7a")["manifest"]["versionCode"], 1201)
        only_v7_and_x86 = {"a": v(1201, "v7", ["armeabi-v7a"]), "c": v(1203, "x86", ["x86"])}
        self.assertEqual(fm.pick_version({}, only_v7_and_x86)["manifest"]["nativecode"], ["armeabi-v7a"])

    def test_never_falls_back_to_beta_or_above_suggested(self):
        self.assertIsNone(fm.pick_version({}, {"a": v(5, beta=True)}))
        self.assertIsNone(fm.pick_version({"suggestedVersionCode": 3}, {"a": v(5), "b": v(4)}))
        index = {"packages": {"p.beta": {"metadata": {"license": "MIT"}, "versions": {"x": v(5, beta=True)}}}}
        self.assertEqual(fm.select_apps(index, make_cfg()).excluded["no_stable_version"], 1)

    def test_obb_versions_are_excluded(self):
        ver = v(3)
        ver["file"].update(sha256="a" * 64, size=10)
        ver["obbMainFile"] = {"name": "/main.3.p.obb", "sha256": "b" * 64, "size": 5}
        index = {"packages": {"p": {"metadata": {"license": "MIT"}, "versions": {"x": ver}}}}
        sel = fm.select_apps(index, make_cfg())
        self.assertEqual(sel.excluded["needs_obb"], 1)
        self.assertEqual(sel.reasons["p"], ["needs_obb"])

    def test_preferred_signer_wins_tie(self):
        a = v(1619, file_name="/p_1619.apk")
        b = v(1619, file_name="/p_1619_release.apk")
        a["manifest"]["signer"] = {"sha256": ["aa" * 32]}
        b["manifest"]["signer"] = {"sha256": ["bb" * 32]}
        chosen = fm.pick_version({"preferredSigner": "bb" * 32}, {"a": a, "b": b})
        self.assertEqual(chosen["file"]["name"], "/p_1619_release.apk")

    def test_malformed_entries_do_not_abort_selection(self):
        index = load_fixture_index()
        pk = index["packages"]
        good = json.loads(json.dumps(pk["org.example.digits"]))
        bad_size = json.loads(json.dumps(good))
        next(iter(bad_size["versions"].values()))["file"]["size"] = "12 MB"
        bad_name = json.loads(json.dumps(good))
        bad_name["metadata"]["name"] = {"en-US": 7}
        bad_cats = json.loads(json.dumps(good))
        bad_cats["metadata"]["categories"] = "Games"
        pk.update({"z.size": bad_size, "z.name": bad_name, "z.cats": bad_cats, "z.none": None})
        sel = fm.select_apps(index, make_cfg())
        self.assertEqual(sel.reasons["z.size"], ["bad_index_entry"])
        by = {a.package: a for a in sel.eligible}
        self.assertEqual(by["z.name"].name, "z.name")
        self.assertEqual(by["z.cats"].categories, [])
        self.assertIn("org.example.notes", by)

    def test_or_semantics_and_more_licences(self):
        self.assertTrue(fm.license_allowed("GPL-3.0-only OR Proprietary"))
        self.assertTrue(fm.license_allowed("MPL-2.0 OR LicenseRef-Commercial"))
        self.assertTrue(fm.license_allowed("MIT AND (Apache-2.0 OR Proprietary)"))
        self.assertFalse(fm.license_allowed("(MIT OR Apache-2.0) AND Proprietary"))
        self.assertFalse(fm.license_allowed("MIT AND"))
        self.assertFalse(fm.license_allowed("(MIT"))
        for lic in ("PublicDomain", "UPL-1.0", "MPL-2.0-no-copyleft-exception", "OSL-3.0", "BSD-2-Clause-Patent"):
            self.assertTrue(fm.license_allowed(lic), lic)

    def test_limit_must_be_positive(self):
        for bad in ("0", "-1", "x"):
            with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
                fm.build_parser().parse_args(["run", "--limit", bad])
        self.assertEqual(fm.build_parser().parse_args(["run", "--limit", "3"]).limit, 3)


class RepoUrlTest(unittest.TestCase):
    def test_https_required(self):
        with self.assertRaises(fm.ConfigError):
            fm.check_repo_url("http://f-droid.org/repo")
        with self.assertRaises(fm.ConfigError):
            fm.check_repo_url("ftp://x/repo")
        fm.check_repo_url("https://apt.izzysoft.de/fdroid/repo")
        fm.check_repo_url("http://127.0.0.1:8000/repo")

    def test_store_links_follow_the_repo(self):
        self.assertEqual(fm.store_url("https://f-droid.org/repo", "a.b"), "https://f-droid.org/packages/a.b/")
        self.assertEqual(fm.store_url("https://apt.izzysoft.de/fdroid/repo", "a.b"),
                         "https://apt.izzysoft.de/fdroid/index/apk/a.b")
        self.assertIsNone(fm.store_url("https://other.example/repo", "a.b"))
        self.assertEqual(fm.source_label("https://f-droid.org/repo"), "f-droid")
        self.assertEqual(fm.source_label("https://apt.izzysoft.de/fdroid/repo"), "apt.izzysoft.de")
        app = make_app()
        app.repo = "https://apt.izzysoft.de/fdroid/repo"
        rec = fm.base_record(app, "")
        self.assertIsNone(rec["fdroid_url"])
        self.assertEqual(rec["store_url"], "https://apt.izzysoft.de/fdroid/index/apk/org.x.app")
        self.assertEqual(fm.object_metadata(app, app.sha256)["source"], "apt.izzysoft.de")


class DownloadFixesTest(TempDirTest):
    def test_bad_mirror_file_falls_back_to_primary(self):
        data = (FIXTURE_REPO / "org.example.digits_7.apk").read_bytes()
        app = make_app(data=data, file_name="/org.example.digits_7.apk")
        with RepoServer() as srv:
            mirror = srv.url.split("/fdroid/")[0] + "/badmirror"
            d = fm.Downloader(srv.url, [mirror], self.tmp, retries=0)
            self.assertEqual(d.download(app).read_bytes(), data)
            self.assertEqual(srv.httpd.statuses, ["badmirror"])

    def test_html_error_page_does_not_replace_cached_index(self):
        cache = self.tmp / "cache"
        with RepoServer() as srv:
            good = fm.fetch_index(srv.url, cache, retries=0)
            srv.httpd.html_index = True
            with contextlib.redirect_stderr(io.StringIO()):
                again = fm.fetch_index(srv.url, cache, retries=0)
            self.assertEqual(again, good)
            meta = json.loads(next(cache.glob("*.meta.json")).read_text("utf-8"))
            self.assertTrue(meta["etag"])  # old validators kept
            with self.assertRaises(fm.ConfigError):
                fm.fetch_index(srv.url, self.tmp / "empty-cache", retries=0)
        self.assertEqual(list(cache.glob("*.part")), [])


class ScanFixesTest(TempDirTest):
    def _file(self, content):
        p = self.tmp / "f.apk"
        p.write_bytes(content)
        return p

    def test_clamav_limits_exceeded_is_not_clean(self):
        s = fm.Scanner(scan_cfg())
        with self.assertRaises(fm.ScanError) as ctx:
            s.scan(self._file(b"LIMITS-EXCEEDED"), "0" * 64)
        self.assertIn("limit", str(ctx.exception))
        done = mock.Mock(returncode=0, stdout="OK", stderr="")
        with mock.patch.object(fm.subprocess, "run", return_value=done) as run:
            s.clamav(self.tmp / "x.apk")
        self.assertIn("--alert-exceeds-max=yes", run.call_args[0][0])

    def vt(self, handler):
        session = mock.Mock()
        session.request.side_effect = handler
        cfg = scan_cfg(**{"scan__scan": "virustotal", "virustotal__api_key": "k"})
        return fm.Scanner(cfg, session=session)

    def test_vt_upload_retry_resends_the_whole_file(self):
        data = b"x" * 5000
        sha = hashlib.sha256(data).hexdigest()
        bodies = []
        replies = [resp(404), resp(503), resp(200, {"data": {"id": "an"}}), vt_done(0, sha)]

        def handler(method, url, **kw):
            if method == "POST":
                bodies.append(kw["files"]["file"][1].read())
            return replies.pop(0)

        s = self.vt(handler)
        r = s.scan(self._file(data), sha)
        self.assertEqual(bodies, [data, data])
        self.assertEqual((r.infected, r.malicious_count, r.engines), (False, 0, ["virustotal"]))

    def test_vt_result_for_other_file_or_without_engines_is_rejected(self):
        data = b"apk"
        sha = hashlib.sha256(data).hexdigest()
        for final in (vt_done(0, sha="e3b0" + "0" * 60), vt_done(0, sha, stats={"malicious": 0})):
            s = self.vt([resp(404), resp(200, {"data": {"id": "an"}}), final])
            with self.assertRaises(fm.ScanError):
                s.scan(self._file(data), sha)

    def test_rate_limiter_wait_is_cancellable(self):
        stop = threading.Event()
        rl = fm.RateLimiter(1)  # one call per 60 s
        rl.acquire(stop)
        stop.set()
        start = time.monotonic()
        with self.assertRaises(fm.Cancelled):
            rl.acquire(stop)
        self.assertLess(time.monotonic() - start, 5)

    def test_unscanned_upload_is_not_marked_clean_or_done(self):
        cfg = scan_cfg(scan__scan="none")
        data = (FIXTURE_REPO / "org.example.digits_7.apk").read_bytes()
        app = make_app(data=data, file_name="/org.example.digits_7.apk")
        fm.assign_keys([app], cfg)
        cat = fm.Catalog(self.tmp / "catalog.json")
        with RepoServer() as srv:
            dl = fm.Downloader(srv.url, [], self.tmp / "dl", retries=0)
            runner = fm.Runner(cfg, self.tmp, dl, fm.Scanner(cfg), fm.LocalDest(self.tmp / "b"), cat)
            with contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(runner.process(app), "unscanned")
        rec = cat.get(app.package)
        self.assertEqual(rec["scan"], {"engines": [], "malicious_count": None})
        self.assertFalse(cat.is_done(app))


class CatalogFixesTest(TempDirTest):
    def test_csv_formulas_are_neutralised(self):
        cat = fm.Catalog(self.tmp / "catalog.json")
        rec = fm.base_record(make_app(summary='=HYPERLINK("https://evil.example","x")'), "")
        rec["summary"] = '=HYPERLINK("https://evil.example","x")'
        rec["name"] = "@SUM(1)"
        cat.record(rec)
        cat.export_csv(self.tmp / "c.csv")
        text = (self.tmp / "c.csv").read_text("utf-8-sig")
        self.assertIn("'=HYPERLINK", text)
        self.assertIn("'@SUM(1)", text)
        self.assertEqual(fm.csv_safe("-1"), "'-1")
        self.assertEqual(fm.csv_safe(5), 5)

    def test_failed_save_does_not_stop_the_run(self):
        cfg = scan_cfg(scan__scan="none")
        cat = fm.Catalog(self.tmp / "catalog.json")
        dl = fm.Downloader("http://127.0.0.1:1", [], self.tmp / "dl", retries=0)
        runner = fm.Runner(cfg, self.tmp, dl, fm.Scanner(cfg), fm.LocalDest(self.tmp / "b"), cat)
        with mock.patch.object(cat, "save", side_effect=PermissionError("WinError 32")) as save, \
                contextlib.redirect_stderr(io.StringIO()):
            runner.save_catalog()
        self.assertEqual(save.call_count, 3)

    def test_withdrawn_apps_are_flagged(self):
        index = load_fixture_index()
        sel = fm.select_apps(index, make_cfg())
        cat = fm.Catalog(self.tmp / "catalog.json")
        for pkg in ("org.example.tracker", "org.gone", "org.example.notes"):
            cat.record({"package": pkg, "status": "uploaded", "versionCode": 1, "updated_at": "2026-01-01"})
        with contextlib.redirect_stderr(io.StringIO()):
            flagged = cat.mark_withdrawn(sel, index, "https://f-droid.org/repo")
        self.assertEqual(sorted(flagged), ["org.example.tracker", "org.gone"])
        self.assertEqual(cat.get("org.example.tracker")["withdrawn_reason"], "antifeature:Tracking")
        self.assertEqual(cat.get("org.gone")["withdrawn_reason"], "removed_from_repo")
        self.assertIsNone(cat.get("org.example.notes").get("withdrawn_reason"))

    def test_old_versions_are_kept_in_history(self):
        cat = fm.Catalog(self.tmp / "catalog.json")
        for vc in (1, 2):
            rec = fm.base_record(make_app(vc=vc, data=b"v%d" % vc), "")
            rec["status"] = "uploaded"
            rec["source_mirror_url"] = "https://cdn/src/%d" % vc
            cat.record(rec)
        hist = cat.get("org.x.app")["previous_versions"]
        self.assertEqual([h["versionCode"] for h in hist], [1])
        self.assertEqual(hist[0]["source_mirror_url"], "https://cdn/src/1")

    def test_infected_version_is_skipped_unless_retry(self):
        cat = fm.Catalog(self.tmp / "catalog.json")
        app = make_app()
        rec = fm.base_record(app, "")
        rec["status"] = "infected"
        cat.record(rec)
        self.assertIsNotNone(cat.skip_reason(app))
        self.assertIsNone(cat.skip_reason(app, retry=True))
        self.assertIsNone(cat.skip_reason(make_app(vc=2, data=b"new")))


class ConfigFixesTest(TempDirTest):
    def test_inline_comments_and_bad_numbers(self):
        p = self.tmp / "c.ini"
        p.write_text("[run]\nworkers = 3 ; keep low\n[source]\ntimeout = soon\n", "utf-8")
        cfg = fm.Config(p)
        self.assertEqual(cfg.getint("run", "workers"), 3)
        with self.assertRaises(fm.ConfigError) as ctx:
            cfg.getint("source", "timeout")
        self.assertIn("timeout", str(ctx.exception))

    def test_broken_config_files_give_clear_errors(self):
        dup = self.tmp / "dup.ini"
        dup.write_text("[scan]\nclamscan_path = a\nclamscan_path = b\n", "utf-8")
        ansi = self.tmp / "ansi.ini"
        ansi.write_bytes("[source]\n; ตัวอย่าง\n".encode("cp874"))
        for path in (dup, ansi):
            err = io.StringIO()
            with contextlib.redirect_stderr(err), contextlib.redirect_stdout(io.StringIO()):
                rc = fm.main(["--config", str(path), "--work-dir", str(self.tmp / "w"), "catalog"])
            self.assertEqual(rc, 2)
            self.assertIn("config.ini", err.getvalue())
            self.assertNotIn("Traceback", err.getvalue())

    def test_config_ini_ini_is_reported(self):
        (self.tmp / "config.ini.ini").write_text("", "utf-8")
        with self.assertLogs(fm.log, "WARNING") as logs:
            fm._report_config_file(self.tmp / "config.ini")
        self.assertIn("config.ini.ini", "\n".join(logs.output))


class RunFixesTest(EndToEndTest):
    def test_limit_moves_on_to_new_packages(self):
        dest = self.tmp / "bucket"
        done = []
        with RepoServer() as srv:
            for _ in range(3):
                rc, out, err = self.run_main(srv, "run", "--dest", "local", "--dest-dir", str(dest), "--limit", "1")
                cat = json.loads((self.tmp / "work" / "catalog.json").read_text("utf-8"))["packages"]
                done.append(set(cat))
        self.assertEqual([len(d) for d in done], [1, 2, 3])

    def test_scan_none_refused_for_r2(self):
        with RepoServer() as srv:
            rc, out, err = self.run_main(srv, "run", "--scan", "none")
        self.assertEqual(rc, 2)
        self.assertIn("--dest local", err)

    def test_r2_errors_are_readable(self):
        boom = FakeClientError("InvalidAccessKeyId", 403)
        with RepoServer() as srv, mock.patch.object(fm, "make_dest", side_effect=boom):
            rc, out, err = self.run_main(srv, "run")
        self.assertEqual(rc, 2)
        self.assertIn("R2", err)
        self.assertIn("InvalidAccessKeyId", err)
        self.assertNotIn("Traceback", err)

    def test_unselected_packages_are_explained(self):
        with RepoServer() as srv:
            rc, out, err = self.run_main(srv, "list", "--packages", "org.example.tracker,org.nope")
        self.assertEqual(rc, 0)
        self.assertIn("antifeature:Tracking", err)
        self.assertIn("org.nope: not in index", err)

    def test_list_csv_that_cannot_be_replaced(self):
        (self.tmp / "l.csv").mkdir()  # stands in for a file locked by Excel
        with RepoServer() as srv:
            rc, out, err = self.run_main(srv, "list", "--csv", str(self.tmp / "l.csv"))
        self.assertEqual(rc, 2)
        self.assertIn("Excel", err)
        self.assertNotIn("Traceback", err)
        self.assertFalse((self.tmp / "l.csv.tmp").exists())


if __name__ == "__main__":
    unittest.main()
