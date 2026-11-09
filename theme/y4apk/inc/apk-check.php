<?php
/**
 * What a member's file really is (2.50), read from inside it on R2 — a few
 * small range requests, never the whole file (they can be 10 GB).
 *
 *   APK   its AndroidManifest.xml (Android's binary XML): package, versionName
 *   XAPK  manifest.json: package_name, version_name
 *   APKM  info.json: pname, release_version
 *   APKS  meta.sai_v2.json / meta.sai_v1.json, or the base.apk inside
 *   ZIP   the OBB folders in it: Android/obb/<package>/…
 *   OBB   its name: main.<version code>.<package>.obb
 *
 * Y4APK_Share uses it to publish at once a file that is really the app the
 * member chose (the same package as on Google Play or the main post), and to
 * show it on the Review card when a file waits.
 */

defined('ABSPATH') || exit;

class Y4APK_Apk_Check {

	const MAX_CD    = 4194304; // central directory read (4 MB: thousands of entries)
	const MAX_ENTRY = 4194304; // a manifest, compressed

	/** ['package' => '', 'version' => '', 'kind' => apk|xapk|…, 'why' => '' or what could not be read]. */
	public static function inspect($key, $size, $name) {
		$ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
		$out = ['package' => '', 'version' => '', 'kind' => $ext, 'why' => ''];
		if ($ext === 'ipa') {
			return $out; // an iOS app (2.61.2): no Android manifest to read
		}
		if ($ext === 'obb') {
			if (preg_match('/^(?:main|patch)\.\d+\.([A-Za-z]\w*(?:\.\w+)+)\.obb$/i', basename((string) $name), $m)) {
				$out['package'] = $m[1];
			} else {
				$out['why'] = 'name';
			}
			return $out;
		}
		$zip = new Y4APK_Zip(function ($start, $len) use ($key) {
			return Y4APK_R2::bytes($key, $start, $len);
		}, (float) $size);
		return self::from_zip($zip, $ext, $out);
	}

	/** The same, for a file on this server (tests, and the admin's own uploads). */
	public static function inspect_local($path, $name = '') {
		$ext = strtolower(pathinfo($name !== '' ? $name : $path, PATHINFO_EXTENSION));
		$out = ['package' => '', 'version' => '', 'kind' => $ext, 'why' => ''];
		$zip = new Y4APK_Zip(function ($start, $len) use ($path) {
			$f = @fopen($path, 'rb');
			if (!$f) {
				return '';
			}
			fseek($f, (int) $start);
			$b = (string) fread($f, (int) $len);
			fclose($f);
			return $b;
		}, (float) filesize($path));
		return self::from_zip($zip, $ext, $out);
	}

	private static function from_zip($zip, $ext, $out) {
		$entries = $zip->entries();
		if ($entries === null) {
			$out['why'] = 'zip';
			return $out;
		}
		if (isset($entries['AndroidManifest.xml'])) {
			$m = self::manifest($zip->read($entries['AndroidManifest.xml'], self::MAX_ENTRY));
			if ($m) {
				$out['package'] = $m['package'];
				$out['version'] = $m['versionName'];
				$out['kind'] = 'apk';
				return $out;
			}
			if ($ext === 'apk') {
				$out['why'] = 'manifest'; // there, but not read: a person looks
				return $out;
			}
		} elseif ($ext === 'apk') {
			$out['why'] = 'nomanifest'; // a ZIP called .apk, with no app in it
			return $out;
		}
		// XAPK, APKM, APKS: their own small JSON
		foreach (['manifest.json' => ['package_name', 'version_name'], 'info.json' => ['pname', 'release_version'], 'meta.sai_v2.json' => ['package', 'version_name'], 'meta.sai_v1.json' => ['package', 'version_name']] as $file => $k) {
			if (!isset($entries[$file])) {
				continue;
			}
			$j = json_decode((string) $zip->read($entries[$file], 262144), true);
			$pkg = is_array($j) && isset($j[$k[0]]) ? (string) $j[$k[0]] : '';
			if (self::package_ok($pkg)) {
				$out['package'] = $pkg;
				$out['version'] = is_array($j) && isset($j[$k[1]]) && is_scalar($j[$k[1]]) ? (string) $j[$k[1]] : '';
				return $out;
			}
		}
		// A base.apk stored as it is (APKS): its own manifest
		foreach (['base.apk', 'base-master.apk'] as $file) {
			if (isset($entries[$file]) && $entries[$file]['method'] === 0) {
				$inner = $zip->sub($entries[$file]);
				$e2 = $inner ? $inner->entries() : null;
				if ($e2 && isset($e2['AndroidManifest.xml'])) {
					$m = self::manifest($inner->read($e2['AndroidManifest.xml'], self::MAX_ENTRY));
					if ($m) {
						$out['package'] = $m['package'];
						$out['version'] = $m['versionName'];
						return $out;
					}
				}
			}
		}
		// A ZIP of OBB data: Android/obb/<package>/… (or <package>/main.….obb)
		$found = [];
		foreach (array_keys($entries) as $n) {
			if (preg_match('~(?:^|/)obb/([A-Za-z]\w*(?:\.\w+)+)/~i', $n, $m) || preg_match('~(?:^|/)(?:main|patch)\.\d+\.([A-Za-z]\w*(?:\.\w+)+)\.obb$~i', $n, $m)) {
				$found[$m[1]] = 1;
			}
		}
		if (count($found) === 1) {
			$out['package'] = (string) array_key_first($found);
			$out['kind'] = $ext === 'zip' ? 'obb' : $ext;
			return $out;
		}
		$out['why'] = 'unknown';
		return $out;
	}

	public static function package_ok($p) {
		return (bool) preg_match('/^[A-Za-z][\w]*(\.[A-Za-z0-9_]+)+$/', (string) $p) && strlen($p) <= 200;
	}

	/**
	 * Android's binary XML (AXML): the <manifest> element's package,
	 * versionName and versionCode, or null.
	 */
	public static function manifest($bin) {
		$bin = (string) $bin;
		$len = strlen($bin);
		if ($len < 8 || self::u16($bin, 0) !== 0x0003) {
			return null;
		}
		$pool = null;
		$ids = [];
		$pos = 8;
		$names = [0x0101021b => 'versionCode', 0x0101021c => 'versionName'];
		while ($pos + 8 <= $len) {
			$type = self::u16($bin, $pos);
			$head = self::u16($bin, $pos + 2);
			$size = self::u32($bin, $pos + 4);
			if ($size < 8 || $pos + $size > $len) {
				return null;
			}
			if ($type === 0x0001) {
				$pool = ['at' => $pos, 'count' => self::u32($bin, $pos + 8), 'utf8' => (self::u32($bin, $pos + 16) & 0x100) !== 0,
					'strings' => $pos + self::u32($bin, $pos + 20), 'offsets' => $pos + $head];
			} elseif ($type === 0x0180) {
				for ($o = $pos + $head; $o + 4 <= $pos + $size; $o += 4) {
					$ids[] = self::u32($bin, $o);
				}
			} elseif ($type === 0x0102 && $pool) {
				$ext = $pos + $head;
				$name = self::str($bin, $pool, self::u32($bin, $ext + 4));
				if ($name === 'manifest') {
					$start = self::u16($bin, $ext + 8);
					$step = self::u16($bin, $ext + 10) ?: 20;
					$count = self::u16($bin, $ext + 12);
					$out = ['package' => '', 'versionName' => '', 'versionCode' => ''];
					for ($i = 0; $i < $count; $i++) {
						$a = $ext + $start + $i * $step;
						if ($a + 20 > $len) {
							break;
						}
						$ni = self::u32($bin, $a + 4);
						$an = self::str($bin, $pool, $ni);
						if ($an === '' && isset($ids[$ni], $names[$ids[$ni]])) {
							$an = $names[$ids[$ni]]; // names left out of the strings: their Android resource ID says which
						}
						if (!array_key_exists($an, $out)) {
							continue;
						}
						$raw = self::u32($bin, $a + 8);
						$dtype = ord($bin[$a + 15]);
						$data = self::u32($bin, $a + 16);
						if ($raw !== 0xFFFFFFFF) {
							$out[$an] = self::str($bin, $pool, $raw);
						} elseif ($dtype === 0x03) {
							$out[$an] = self::str($bin, $pool, $data);
						} elseif ($dtype === 0x10 || $dtype === 0x11) {
							$out[$an] = (string) $data;
						}
					}
					return self::package_ok($out['package']) ? $out : null;
				}
			}
			$pos += $size;
		}
		return null;
	}

	private static function u16($b, $o) {
		return $o + 2 <= strlen($b) ? unpack('v', substr($b, $o, 2))[1] : 0;
	}

	private static function u32($b, $o) {
		return $o + 4 <= strlen($b) ? unpack('V', substr($b, $o, 4))[1] : 0;
	}

	/** String $i of the string pool, UTF-8 or UTF-16. */
	private static function str($b, $pool, $i) {
		if ($i < 0 || $i >= $pool['count'] || $i === 0xFFFFFFFF) {
			return '';
		}
		$o = $pool['strings'] + self::u32($b, $pool['offsets'] + 4 * $i);
		if ($pool['utf8']) {
			$n = ord($b[$o] ?? "\0");
			$o += ($n & 0x80) ? 2 : 1; // characters
			$n = ord($b[$o] ?? "\0");
			if ($n & 0x80) {
				$n = (($n & 0x7F) << 8) | ord($b[$o + 1] ?? "\0");
				$o += 2;
			} else {
				$o += 1;
			}
			return (string) substr($b, $o, $n);
		}
		$n = self::u16($b, $o);
		$o += 2;
		if ($n & 0x8000) {
			$n = (($n & 0x7FFF) << 16) | self::u16($b, $o);
			$o += 2;
		}
		$s = substr($b, $o, $n * 2);
		return function_exists('mb_convert_encoding') ? (string) mb_convert_encoding($s, 'UTF-8', 'UTF-16LE') : preg_replace('/\x00/', '', $s);
	}
}

/**
 * A ZIP read through a function that returns bytes ($start, $length): the
 * central directory (ZIP64 too) and single entries, stored or deflated.
 */
class Y4APK_Zip {

	private $fetch;
	private $size;
	private $base;

	public function __construct($fetch, $size, $base = 0) {
		$this->fetch = $fetch;
		$this->size = (float) $size;
		$this->base = (float) $base;
	}

	private function get($start, $len) {
		return (string) call_user_func($this->fetch, $this->base + $start, $len);
	}

	/** [name => ['method', 'csize', 'usize', 'offset']] or null when this is not a ZIP. */
	public function entries() {
		if ($this->size < 22) {
			return null;
		}
		$tail_len = (int) min($this->size, 65557);
		$tail = $this->get($this->size - $tail_len, $tail_len);
		$at = strrpos($tail, "PK\x05\x06");
		if ($at === false || strlen($tail) < $at + 22) {
			return null;
		}
		$e = unpack('vdisk/vcddisk/vcountdisk/vcount/Vcdsize/Vcdoffset', substr($tail, $at + 4, 16));
		$count = $e['count'];
		$cd_size = (float) $e['cdsize'];
		$cd_offset = (float) $e['cdoffset'];
		if ($cd_offset == 0xFFFFFFFF || $count == 0xFFFF || $cd_size == 0xFFFFFFFF) {
			// ZIP64: its locator sits just before, and points at the ZIP64 end record
			if ($at < 20 || substr($tail, $at - 20, 4) !== "PK\x06\x07") {
				return null;
			}
			$loc = unpack('Vdisk/Poffset', substr($tail, $at - 16, 12));
			$rec = $this->get($loc['offset'], 56);
			if (substr($rec, 0, 4) !== "PK\x06\x06") {
				return null;
			}
			$z = unpack('Psize/vver/vneed/Vdisk/Vcddisk/Pcountdisk/Pcount/Pcdsize/Pcdoffset', substr($rec, 4, 52));
			$count = $z['count'];
			$cd_size = (float) $z['cdsize'];
			$cd_offset = (float) $z['cdoffset'];
		}
		if ($cd_offset + $cd_size > $this->size) {
			return null;
		}
		$cd = $this->get($cd_offset, (int) min($cd_size, Y4APK_Apk_Check::MAX_CD));
		$out = [];
		$p = 0;
		$n = strlen($cd);
		while ($p + 46 <= $n && substr($cd, $p, 4) === "PK\x01\x02") {
			$h = unpack('vver/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/viattr/Veattr/Voffset', substr($cd, $p + 4, 42));
			$name = substr($cd, $p + 46, $h['nlen']);
			$extra = substr($cd, $p + 46 + $h['nlen'], $h['xlen']);
			$usize = (float) $h['usize'];
			$csize = (float) $h['csize'];
			$offset = (float) $h['offset'];
			if ($usize == 0xFFFFFFFF || $csize == 0xFFFFFFFF || $offset == 0xFFFFFFFF) {
				for ($x = 0; $x + 4 <= strlen($extra);) {
					$xh = unpack('vid/vlen', substr($extra, $x, 4));
					if ($xh['id'] === 0x0001) {
						$f = substr($extra, $x + 4, $xh['len']);
						$q = 0;
						foreach (['usize', 'csize', 'offset'] as $k) {
							if ($$k == 0xFFFFFFFF && $q + 8 <= strlen($f)) {
								$$k = (float) unpack('P', substr($f, $q, 8))[1];
								$q += 8;
							}
						}
						break;
					}
					$x += 4 + $xh['len'];
				}
			}
			if ($name !== '' && substr($name, -1) !== '/') {
				$out[$name] = ['method' => (int) $h['method'], 'csize' => $csize, 'usize' => $usize, 'offset' => $offset, 'flags' => (int) $h['flags']];
			}
			$p += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];
		}
		return $out ? $out : null;
	}

	/** Where an entry's data starts. */
	private function data_at($e) {
		$lh = $this->get($e['offset'], 30);
		if (strlen($lh) < 30 || substr($lh, 0, 4) !== "PK\x03\x04") {
			return null;
		}
		$l = unpack('vnlen/vxlen', substr($lh, 26, 4));
		return $e['offset'] + 30 + $l['nlen'] + $l['xlen'];
	}

	/** An entry's content (at most $max bytes compressed), or null. */
	public function read($e, $max) {
		if ($e['csize'] > $max || ($e['flags'] & 1)) {
			return null; // too big for a manifest, or encrypted
		}
		$at = $this->data_at($e);
		if ($at === null) {
			return null;
		}
		$data = $e['csize'] > 0 ? $this->get($at, (int) $e['csize']) : '';
		if ($e['method'] === 0) {
			return $data;
		}
		if ($e['method'] === 8) {
			$out = @gzinflate($data, (int) min(max($e['usize'], 1), 16777216));
			return $out === false ? null : $out;
		}
		return null;
	}

	/** A ZIP stored inside this one, as it is (a split APK's base.apk). */
	public function sub($e) {
		$at = $this->data_at($e);
		return $at === null ? null : new Y4APK_Zip($this->fetch, $e['csize'], $this->base + $at);
	}
}
