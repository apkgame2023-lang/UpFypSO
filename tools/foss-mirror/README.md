# foss-mirror — ดึงแอปโอเพนซอร์สจาก F-Droid ขึ้น Cloudflare R2 อัตโนมัติ

เครื่องมือนี้ใช้สำหรับเว็บ Y4APK เพื่อ **เผยแพร่แอปที่ถูกต้องตามสิทธิ์** เท่านั้น
คือแอปโอเพนซอร์ส (FOSS) ที่สัญญาอนุญาตยอมให้แจกจ่ายต่อได้ จากคลังของ F-Droid
(หรือคลังรูปแบบเดียวกัน เช่น IzzyOnDroid)

สิ่งที่โปรแกรมทำให้ทีละแอป:

1. อ่านรายชื่อแอปจาก F-Droid (`index-v2.json`)
2. คัดเฉพาะแอปที่สัญญาอนุญาตเป็นโอเพนซอร์สที่ได้รับการรับรอง และไม่มี anti-feature อันตราย
3. ดาวน์โหลด APK แล้ว **ตรวจ sha256 และขนาดไฟล์** ให้ตรงกับที่ F-Droid ประกาศ (ไฟล์ไม่ตรง = ไม่ใช้)
4. **สแกนไวรัส** ด้วย ClamAV และ/หรือ VirusTotal (ติดไวรัส = ลบทิ้ง ไม่อัปโหลดเด็ดขาด)
5. อัปโหลดขึ้น R2 bucket `files` ตามรูปแบบชื่อเดิมของเว็บ เช่น `S/Super-Game-v2.0-Y4APK.COM.apk`
6. บันทึก **แคตตาล็อก** (JSON + CSV) ไว้ใช้ทำโพสต์ WordPress และจำว่าอัปโหลดอะไรไปแล้ว

> **ไม่รองรับ และห้ามใช้กับ APK ม็อด/แคร็ก/ดัดแปลง** เครื่องมือนี้ไม่แก้ไขเนื้อไฟล์ APK เลย
> (เปลี่ยนแค่ชื่อไฟล์) และไม่เขียนทับไฟล์ที่มีอยู่แล้วใน bucket ที่ไม่ได้สร้างโดยเครื่องมือนี้

### มีแอปโอเพนซอร์สกี่แอป?

คลังหลักของ F-Droid มีแอปประมาณ 4,000 กว่าแอป แต่ตัวเลขเปลี่ยนทุกวัน
ดูตัวเลขจริง ณ ตอนนี้ด้วยคำสั่ง `stats` (ไม่ดาวน์โหลด APK ไม่ต้องตั้งค่า R2):

```
run_windows.bat stats
run_windows.bat stats --games
```

---

## ข้อกฎหมายที่ต้องปฏิบัติ (สำคัญมาก)

แอปโอเพนซอร์สแจกต่อได้ **ก็ต่อเมื่อทำตามเงื่อนไขของสัญญาอนุญาต** สำหรับทุกหน้าดาวน์โหลด:

1. **แสดงสัญญาอนุญาต** (คอลัมน์ `license` เช่น `GPL-3.0-only`) ในหน้าดาวน์โหลด และลิงก์ไปยังข้อความสัญญา
   (เช่น `https://spdx.org/licenses/GPL-3.0-only.html`)
2. **ลิงก์ไปยังซอร์สโค้ด** (`sourceCode`) และสำหรับ GPL/LGPL/AGPL ให้ลิงก์ไปยัง
   **ซอร์สโค้ดของเวอร์ชันนั้นตรง ๆ** ด้วย — ใช้ **`source_mirror_url`** (สำเนาไฟล์ซอร์สที่โปรแกรมเก็บไว้ใน bucket
   ที่ `src/...` ให้อยู่คู่กับ APK ตลอดไป) เพราะลิงก์ `source_tarball_url` ของ F-Droid จะใช้ไม่ได้เมื่อ F-Droid
   ย้ายเวอร์ชันเก่าไปเก็บใน archive — GPL กำหนดให้ต้องให้ซอร์สได้ตลอดเวลาที่ยังแจก APK อยู่
   ถ้า repo ไม่มีไฟล์ซอร์ส (`source_mirror_url` ว่าง) ให้ใช้ลิงก์ tag/release ของเวอร์ชันนั้นในหน้าซอร์สโค้ด
3. **ให้เครดิตผู้พัฒนา** (`authorName`, `authorWebSite`, `webSite`) และลิงก์หน้าแอปในคลังต้นทาง (`store_url`)
   ถ้า `authorName` ว่าง ให้ใช้ชื่อเจ้าของในลิงก์ `sourceCode` แทน (เช่น `github.com/<ชื่อเจ้าของ>/...`)
4. **ห้ามอ้างว่าแอปเป็นของเรา** ให้เขียนชัดว่า "แอปนี้พัฒนาโดย ... เผยแพร่ต่อภายใต้สัญญาอนุญาต ..."
5. **ห้ามแก้ไขหรือเซ็นชื่อ (re-sign) APK ใหม่** เครื่องมือนี้อัปโหลดไฟล์ต้นฉบับเท่านั้น
6. **ชื่อแอปและโลโก้อาจเป็นเครื่องหมายการค้า** ห้ามทำให้เข้าใจว่าเจ้าของแอปหรือ F-Droid รับรอง/สนับสนุนเว็บเรา
7. **เกรงใจแบนด์วิดท์ของ F-Droid** — F-Droid เป็นโครงการอาสาสมัคร: ใช้ `workers` ต่ำ (1–3), เปิดใช้มิเรอร์
   (`use_index_mirrors = true`), รันทีละช่วง (`--limit`) และถ้าจะมิเรอร์ทั้งคลังจริงจัง
   ให้ใช้ rsync ตามคู่มือมิเรอร์ของ F-Droid แทนการดาวน์โหลดทีละไฟล์

### ทำไมคัดบางแอปออก (anti-features)

| Anti-feature | ทำไมคัดออก |
|---|---|
| `NoSourceSince` | ไม่มีซอร์สโค้ดของเวอร์ชันนี้แล้ว → ทำตามเงื่อนไขให้ซอร์สโค้ดไม่ได้ |
| `NonFreeAssets` | มีไฟล์ภาพ/เสียง/ข้อมูลที่ไม่ใช่โอเพนซอร์ส → อาจไม่มีสิทธิ์แจกต่อ |
| `KnownVuln` | มีช่องโหว่ความปลอดภัยที่รู้แล้ว → อันตรายต่อผู้ใช้ |
| `Tracking` | ติดตาม/เก็บข้อมูลผู้ใช้ → อันตรายต่อผู้ใช้ |
| `Ads` | มีโฆษณา → ไม่เหมาะกับผู้ใช้ |
| `NSFW` | เนื้อหาผู้ใหญ่ |

anti-feature อื่น (เช่น `NonFreeNet` ใช้บริการออนไลน์ที่ไม่ฟรี, `NonFreeDep`, `TetheredNet`, `NonFreeAdd`)
ยังเผยแพร่ได้ แต่จะถูกบันทึกในคอลัมน์ `antiFeatures` — **ควรแจ้งผู้ใช้ในหน้าดาวน์โหลด**

นอกจากนี้จะข้าม: แอปที่มีแต่เวอร์ชันทดสอบ (Beta) หรือเวอร์ชันสูงกว่าที่ F-Droid แนะนำ (`no_stable_version`)
และแอปที่ต้องใช้ไฟล์ `.obb` เพิ่ม (`needs_obb` — APK อย่างเดียวใช้งานไม่ได้)
ถ้าแอปแยก APK ตามสถาปัตยกรรม จะเลือกไฟล์สำหรับมือถือ ARM (`preferred_abi`) ไม่เลือก x86

### สัญญาอนุญาตที่รับ

รับเมื่อสัญญา (SPDX) ใช้ได้กับรายการโอเพนซอร์สที่ OSI/FSF รับรอง เช่น GPL/LGPL/AGPL
(ทุกเวอร์ชัน รวม `-only` และ `-or-later`), Apache-2.0, MIT, BSD-2/3-Clause, MPL-2.0, ISC, Unlicense,
CC0-1.0, EUPL-1.1/1.2, WTFPL, Zlib, Artistic-2.0, MIT-0, 0BSD, BSL-1.0, UPL-1.0, OSL-3.0,
`PublicDomain` (ป้ายของ F-Droid) ฯลฯ — `A OR B` = ใช้ได้ถ้า **อย่างน้อยหนึ่ง** สัญญาอยู่ในรายการ
(เราแจกต่อภายใต้สัญญาที่อยู่ในรายการ), `A AND B` = ต้องอยู่ในรายการ **ทุก** สัญญา

- `GPL-3.0-only OR Apache-2.0` → รับ
- `MPL-2.0 OR LicenseRef-Commercial` → รับ (แจกต่อภายใต้ MPL-2.0 — ในโพสต์ให้ระบุว่าใช้ MPL-2.0)
- `MIT AND Proprietary` → ไม่รับ (มีส่วนที่ไม่ใช่โอเพนซอร์ส)
- ไม่ระบุสัญญา หรือเขียนผิดรูปแบบ → ไม่รับ

---

## ติดตั้งบน Windows 10 ทีละขั้น

### 1) ติดตั้ง Python

1. เข้า https://www.python.org/downloads/ ดาวน์โหลด **Python 3.12** (Windows installer 64-bit)
2. ตอนติดตั้ง **ติ๊กช่อง "Add python.exe to PATH"** ที่หน้าแรก แล้วกด Install Now
3. เปิด Command Prompt ใหม่ พิมพ์ `python --version` ต้องเห็น `Python 3.12.x`

### 2) ติดตั้ง ClamAV (โปรแกรมสแกนไวรัส ฟรี)

1. เข้า https://www.clamav.net/downloads ดาวน์โหลดไฟล์ **Windows x64 .msi** แล้วติดตั้ง
   (ปกติจะอยู่ที่ `C:\Program Files\ClamAV\`)
2. ในโฟลเดอร์ `C:\Program Files\ClamAV\conf_examples\` คัดลอก `freshclam.conf.sample`
   ไปไว้ที่ `C:\Program Files\ClamAV\freshclam.conf`
3. เปิด `freshclam.conf` ด้วย Notepad ลบบรรทัดที่เขียนว่า `Example` (หรือใส่ `#` นำหน้า) แล้วบันทึก
4. เปิด **Command Prompt แบบ Run as administrator** แล้วรัน:
   ```
   "C:\Program Files\ClamAV\freshclam.exe"
   ```
   เพื่อโหลดฐานข้อมูลไวรัส (ควรรันซ้ำทุกวันก่อนใช้งาน)
5. ใน `config.ini` ตั้ง `clamscan_path = C:\Program Files\ClamAV\clamscan.exe`

### 3) สร้าง R2 API token ใน Cloudflare

1. เข้า Cloudflare dashboard → เมนู **R2 Object Storage**
2. ดู **Account ID** ที่หน้า R2 (อยู่ด้านขวา หรือใน endpoint `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`)
3. กด **Manage API tokens** (Manage R2 API Tokens) → **Create API token**
4. Permissions เลือก **Object Read & Write**
5. Specify bucket(s) เลือกเฉพาะ bucket **`files`** (ไม่ต้องให้สิทธิ์ทุก bucket)
6. กด Create แล้วคัดลอก **Access Key ID** และ **Secret Access Key** (Secret แสดงครั้งเดียว ห้ามแชร์ให้ใคร)
7. โดเมนสาธารณะของ bucket `files` คือ `https://files.y4apk.net` (R2 → files → Settings → Custom Domains)
   ตั้งไว้เป็นค่าเริ่มต้นของ `public_base_url` แล้ว เปลี่ยนเฉพาะเมื่อย้ายโดเมน

### 4) ตั้งค่าโปรแกรม

1. เปิดโฟลเดอร์ `tools\foss-mirror`
2. ใน File Explorer เปิดแท็บ **View** แล้วติ๊ก **File name extensions** (Windows ซ่อนนามสกุลไฟล์ไว้
   ถ้าไม่เปิด ตอนเปลี่ยนชื่อจะได้ `config.ini.ini` โดยไม่รู้ตัว และโปรแกรมจะหาไฟล์ไม่เจอ)
3. คัดลอก `config.example.ini` เป็น `config.ini`
4. แก้ `config.ini` ด้วย Notepad: ใส่ค่าในหมวด `[r2]` และ `clamscan_path` (คำอธิบายภาษาไทยอยู่ในไฟล์)
   แล้วบันทึกแบบ **UTF-8** (Notepad: File > Save as > Encoding: UTF-8) และอย่าใส่ค่าเดียวกันซ้ำสองบรรทัด

ทุกครั้งที่รัน โปรแกรมจะบอกว่าใช้ไฟล์ config ไหน (`Using config: ...`) หรือแจ้งว่าไม่พบ

> จะใส่ค่าลับผ่าน environment variables แทนก็ได้: `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`,
> `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_PUBLIC_BASE_URL`, `VT_API_KEY` (มีผลเหนือค่าใน config.ini)
> โปรแกรมจะไม่พิมพ์ค่าลับออกหน้าจอหรือ log (แสดงเป็น `***`)

### 5) รัน

เปิด Command Prompt ในโฟลเดอร์ `tools\foss-mirror` แล้วใช้ `run_windows.bat`
(ครั้งแรกจะสร้าง `.venv` และติดตั้งแพ็กเกจให้อัตโนมัติ; ถ้าดับเบิลคลิกไฟล์ .bat จะแสดงวิธีใช้แล้วรอให้กดปุ่ม)
path ที่พิมพ์เอง เช่น `--csv list.csv` จะนับจากโฟลเดอร์ที่ Command Prompt เปิดอยู่:

```
run_windows.bat stats
run_windows.bat list --games
run_windows.bat run --limit 5 --dry-run
run_windows.bat run --limit 5
```

---

## คำสั่งทั้งหมด

ตัวเลือกที่ใช้ได้ทุกคำสั่ง: `--config PATH`, `--repo URL`, `--work-dir PATH`, `-v` (แสดงรายละเอียด)

ตัวเลือกคัดกรอง (ใช้กับ `stats`, `list`, `run`):

| ตัวเลือก | ความหมาย |
|---|---|
| `--limit N` | ทำแค่ N แอป (เรียงตามชื่อ, N ต้องเป็น 1 ขึ้นไป) — กับ `run` นับเฉพาะแอปที่ยังไม่ได้อัปโหลด |
| `--packages a,b` | เฉพาะ package ที่ระบุ เช่น `org.example.notes` |
| `--category NAME` | เฉพาะหมวด (ใส่ซ้ำได้ ไม่สนตัวพิมพ์เล็ก/ใหญ่) |
| `--games` | เฉพาะหมวดที่มีคำว่า Game |
| `--search TEXT` | ค้นในชื่อ / package / คำอธิบายสั้น |
| `--max-size-mb N` | ข้ามไฟล์ที่ใหญ่กว่า N MB (ค่าเริ่มต้น 1024) |

### `stats` — นับจำนวนแอป

```
> run_windows.bat stats
Repo: https://f-droid.org/repo
แอปทั้งหมดในดัชนี / packages in index : 12
ผ่านเกณฑ์ / eligible                 : 8
ถูกคัดออก / excluded                 : 4
เลือกตามตัวกรอง / selected          : 8
เกม / games                         : 2
ขนาดรวม / total size               : 0.00 GB

เหตุผลที่คัดออก / excluded by reason:
  license                          2
  no_versions                      1
  antifeature:Tracking             1
...
```
(ตัวอย่างนี้มาจากคลังทดสอบ ตัวเลขจริงจะมากกว่านี้มาก)  เพิ่ม `--json` เพื่อได้ผลแบบ JSON

ความหมายของเหตุผล: `no_versions` = ไม่มีไฟล์ APK, `license` = สัญญาไม่ผ่าน,
`antifeature:X` = มี anti-feature ที่ถูกห้าม, `too_big` = ไฟล์ใหญ่เกิน, `bad_index_entry` = ข้อมูลในดัชนีผิดรูปแบบ,
`no_stable_version` = มีแต่เวอร์ชัน Beta, `needs_obb` = ต้องใช้ไฟล์ .obb เพิ่ม
หัวข้อ `rejected licences` แสดงสัญญาที่ถูกปฏิเสธบ่อย — ถ้าแน่ใจว่าเป็นโอเพนซอร์สจริง เพิ่มใน `extra_licenses` ได้

ถ้าใช้ `--packages` แล้วแอปไม่ขึ้น โปรแกรมจะบอกเหตุผล (ถูกคัดออกเพราะอะไร หรือ "not in index" = พิมพ์ชื่อผิด)

### `list` — ดูตัวอย่างว่าจะได้ไฟล์ชื่ออะไร

```
> run_windows.bat list --games
package                            name                     version      licence               MB  key
org.example.digits                 2048 Puzzle              7.0          MIT                  0.0  A/2048-Puzzle-v7.0-Y4APK.COM.apk
org.example.game                   Super Game               2.0          GPL-3.0-only OR~     0.0  S/Super-Game-v2.0-Y4APK.COM.apk
(2 shown)
```
บนจอแสดงสูงสุด 50 แถว — ใช้ `--csv list.csv` เพื่อบันทึกทั้งหมดเป็นไฟล์เปิดใน Excel

### `run` — ดาวน์โหลด ตรวจ สแกน อัปโหลด

```
> run_windows.bat run --limit 20
Using config: C:\...\tools\foss-mirror\config.ini
Processing 20 packages (35 already done) with 2 worker(s), 9 mirror(s) / เริ่มประมวลผล
org.example.digits: downloading 7.0 (3.2 MB)
org.example.digits: uploaded -> A/2048-Puzzle-v7.0-Y4APK.COM.apk
org.example.badhash: failed: IntegrityError: sha256 mismatch ...
org.example.virus: INFECTED - not uploaded / พบไวรัส ไม่อัปโหลด: ... FOUND
...
=== สรุป / Summary ===
  uploaded   18
  infected   1
  failed     1
  skipped    35
  data       512.3 MB uploaded
  elapsed    420 s
```

| ตัวเลือก | ความหมาย |
|---|---|
| `--dry-run` | แค่แสดงว่าจะทำอะไร ไม่ดาวน์โหลด/อัปโหลดจริง |
| `--dest local --dest-dir PATH` | คัดลอกลงโฟลเดอร์ในเครื่องแทน R2 (ไว้ดูตัวอย่าง) |
| `--scan clamav\|virustotal\|clamav+virustotal\|none` | วิธีสแกน (`none` ใช้ได้กับ `--dest local` เท่านั้น — ปลายทาง R2 จะไม่ยอมรัน) |
| `--retry` | ลองแอป/เวอร์ชันที่เคยเป็น `infected` หรือ `conflict` อีกครั้ง (ปกติข้ามเพื่อไม่ดาวน์โหลดซ้ำ) |
| `--workers N` | ดาวน์โหลดพร้อมกันกี่ไฟล์ (แนะนำ 1–3) |
| `--keep-files` | เก็บ APK ไว้ในเครื่องหลังอัปโหลด |

สถานะในแคตตาล็อก: `uploaded` (สแกนแล้ว อยู่ใน R2 แล้ว), `failed` (ดาวน์โหลด/ตรวจ/อัปโหลดไม่สำเร็จ — รอบหน้าลองใหม่),
`infected` (พบไวรัส หรือ VirusTotal เตือนเกินกำหนด — ไม่อัปโหลด),
`conflict` (มีไฟล์ชื่อเดียวกันอยู่ใน bucket แล้วแต่เนื้อไฟล์ต่างกัน — **ไม่เขียนทับ** และไม่ดาวน์โหลด),
`unscanned` (อัปโหลดโดยไม่สแกน เพราะใช้ `--scan none` — **ห้ามโพสต์** รอบหน้าที่สแกนได้จะสแกนให้)

- รันซ้ำได้เรื่อย ๆ: แอปที่อัปโหลดเวอร์ชันเดิมไปแล้วจะถูกข้าม และ `--limit` นับเฉพาะแอปที่ยังต้องทำ
  เช่น `--limit 20` ทุกรอบ จะได้แอปใหม่ 20 แอปต่อรอบไปเรื่อย ๆ (แอปที่เคย failed จะถูกลองใหม่หลังแอปที่ยังไม่เคยทำ)
- เมื่อแอปออกเวอร์ชันใหม่จะอัปโหลดไฟล์ใหม่ (ไฟล์เวอร์ชันเก่าใน R2 ไม่ถูกลบ และลิงก์เก่าเก็บไว้ใน `previous_versions` ของ catalog.json)
- ทุกรอบจะตรวจแอปที่อัปโหลดไปแล้วซ้ำ: ถ้าตอนนี้ไม่ผ่านเกณฑ์ (เช่น F-Droid ติดป้าย `KnownVuln`/`Tracking`,
  เปลี่ยนสัญญาอนุญาต หรือแอปถูกถอดออกจาก repo) จะเขียนเหตุผลในคอลัมน์ `withdrawn_reason` และเตือนบนจอ
- กด **Ctrl+C** เพื่อหยุด โปรแกรมจะบันทึกแคตตาล็อกทันที แล้วรอไฟล์ที่กำลังทำอยู่ให้จบ
  (กด Ctrl+C ซ้ำ = ไม่รอ แต่ยังบันทึกแคตตาล็อกให้)
- แอปหนึ่งพังไม่ทำให้แอปอื่นหยุด; exit code = 0 ถ้าไม่มี failed/infected, = 1 ถ้ามี

### `catalog` — สร้าง catalog.csv ใหม่จาก catalog.json

```
run_windows.bat catalog
```

---

## รูปแบบชื่อไฟล์

ค่าเริ่มต้น: ชื่อไฟล์ `{name}-v{version}-Y4APK.COM.apk` ตำแหน่ง `{folder}/{filename}`

- `{name}` = ชื่อแอปภาษาอังกฤษ ตัดอักษรพิเศษ/ภาษาอื่นออก ช่องว่างเป็น `-`
  (ถ้าไม่เหลืออะไรเลย เช่นชื่อภาษาไทยล้วน จะใช้ชื่อ package แทน)
- `{version}` = เวอร์ชัน (ทำความสะอาดแบบเดียวกัน; ตัด `v` นำหน้าออกเพื่อไม่ให้เป็น `vv22.0`,
  `+` และวงเล็บกลายเป็น `-` เช่น `1.0.0+12` → `1.0.0-12`) · `{folder}` = ตัวอักษรแรกของชื่อ (A–Z)
  ถ้าขึ้นต้นด้วยตัวเลข → โฟลเดอร์ `A` (เหมือน bucket เดิม ตั้งได้ที่ `nonalpha_folder`)
- ใช้ได้อีก: `{package}`, `{versionCode}`

| แอป | เวอร์ชัน | ได้ไฟล์ใน R2 |
|---|---|---|
| Super Game | 2.0 | `S/Super-Game-v2.0-Y4APK.COM.apk` |
| 2048 Puzzle | 7.0 | `A/2048-Puzzle-v7.0-Y4APK.COM.apk` |
| Ünïcödé App_Name | 1.2 beta | `U/Unicode-App-Name-v1.2-beta-Y4APK.COM.apk` |
| Tusky | v22.0 | `T/Tusky-v22.0-Y4APK.COM.apk` |
| Some App | 3.2(45) | `S/Some-App-v3.2-45-Y4APK.COM.apk` |
| แอปไทย (org.example.thai) | 5.0 (ทดสอบ) | `O/org.example.thai-v5.0-Y4APK.COM.apk` |
| Notes (มีอีกแอปชื่อซ้ำ) | 1.0 | `N/Notes-v1.0-Y4APK.COM-org.example.notes2.apk` |

ถ้าสองแอปได้ชื่อซ้ำกัน แอปที่ package id มาทีหลัง (เรียงตามตัวอักษร) จะถูกเติม `-{package}` ต่อท้าย
ชื่อที่มี `..`, `\`, `/` นำหน้า หรืออักขระควบคุม จะถูกปฏิเสธเสมอ

---

## GitHub Actions (รันบนคลาวด์ ไม่ต้องเปิดคอมทิ้งไว้)

ไฟล์ `.github/workflows/foss-mirror.yml` รัน **เฉพาะเมื่อกดสั่งเอง** (ไม่มีตั้งเวลาอัตโนมัติ)
ติดตั้ง ClamAV + อัปเดตฐานไวรัสให้ทุกครั้ง

1. ใน GitHub repo ไปที่ **Settings → Secrets and variables → Actions → New repository secret**
   แล้วเพิ่ม:
   - `R2_ACCOUNT_ID`
   - `R2_ACCESS_KEY_ID`
   - `R2_SECRET_ACCESS_KEY`
   - `R2_BUCKET` (ใส่ `files`)
   - `R2_PUBLIC_BASE_URL` (ไม่บังคับ — ค่าเริ่มต้นคือ `https://files.y4apk.net`)
   - `VT_API_KEY` (ไม่บังคับ — ถ้าใส่ จะสแกนด้วย ClamAV + VirusTotal)
2. ไปที่แท็บ **Actions → FOSS mirror (F-Droid -> R2) → Run workflow**
3. ใส่ `limit` (ค่าเริ่มต้น 20, ต้องเป็นตัวเลข 1 ขึ้นไป — แต่ละรอบจะทำแอปใหม่ที่ยังไม่ได้อัปโหลด) และเลือก `dry_run` (ค่าเริ่มต้นเปิดไว้ — ลองดูผลก่อน แล้วค่อยปิดเพื่ออัปโหลดจริง)
4. ดู log ได้ในหน้า run และดาวน์โหลด log + catalog ได้จากหัวข้อ Artifacts

แต่ละรอบบน GitHub จะโหลด `catalog/foss-apps.json` จาก bucket มาก่อน จึงรู้ว่าอัปโหลดอะไรไปแล้ว
และอัปโหลดแคตตาล็อกกลับขึ้น bucket เมื่อจบ (ทุก ๆ 10 แอปด้วย)

---

## แคตตาล็อก และการใช้ทำโพสต์ WordPress

ไฟล์อยู่ที่ `work\catalog.json` และ `work\catalog.csv` (เปิดใน Excel ได้ ภาษาไทยไม่เพี้ยน)
และอยู่ใน bucket ที่ `catalog/foss-apps.json`

คอลัมน์สำคัญ: `name`, `summary`, `version`, `license`, `sourceCode`, `source_mirror_url`, `source_tarball_url`,
`webSite`, `authorName`, `authorWebSite`, `issueTracker`, `categories`, `antiFeatures`, `sha256`, `size`, `minSdk`,
`r2_key`, `public_url`, `store_url` (หน้าแอปในคลังต้นทาง), `fdroid_url` (เฉพาะไฟล์จาก f-droid.org), `source_repo`,
`scan_engines`, `scan_malicious_count`, `status`, `withdrawn_reason`

วิธีใช้:
- ทำโพสต์เฉพาะแถวที่ `status = uploaded` **และ** `scan_engines` ไม่ว่าง
- แถวที่ `withdrawn_reason` ไม่ว่าง (เช่น `antifeature:KnownVuln`, `license`, `removed_from_repo`)
  = แอปนี้ไม่ผ่านเกณฑ์แล้ว → **ถอดโพสต์ หรือติดคำเตือนให้ชัด** (ไฟล์ใน R2 ไม่ถูกลบอัตโนมัติ)
- นำเข้าเป็นโพสต์ได้ด้วยปลั๊กอินนำเข้า CSV (เช่น WP All Import) หรือคัดลอกทีละแถว
- ปุ่มดาวน์โหลดใช้ `public_url`
- ตัวอย่างข้อความท้ายโพสต์ (ควรมีทุกโพสต์):

```
แอปนี้เป็นซอฟต์แวร์โอเพนซอร์ส พัฒนาโดย {authorName} ({authorWebSite} / {webSite})
สัญญาอนุญาต: {license}  ·  ซอร์สโค้ด: {sourceCode}
ซอร์สโค้ดของเวอร์ชันนี้: {source_mirror_url}  ·  หน้าแอปในคลังต้นทาง: {store_url}
ไฟล์ต้นฉบับจาก {source_repo} ไม่มีการดัดแปลง  SHA-256: {sha256}
Y4APK ไม่ได้เป็นเจ้าของ และไม่ได้รับการรับรองจากผู้พัฒนาหรือผู้ดูแลคลังต้นทาง
```
- ถ้า `authorName` ว่าง ให้ใส่ชื่อเจ้าของจากลิงก์ `sourceCode` (เช่น `github.com/<เจ้าของ>`) แทน อย่าเว้นว่าง
- ถ้าไฟล์มาจาก F-Droid (`fdroid_url` ไม่ว่าง) เขียนว่า "ไฟล์ต้นฉบับจาก F-Droid" ได้; ถ้ามาจากคลังอื่น
  (เช่น IzzyOnDroid) ให้เครดิตคลังนั้นตาม `source_repo` ห้ามเขียนว่ามาจาก F-Droid
- ถ้าใช้หลาย repo ให้แยก `work_dir` และ `catalog_key` ของแต่ละ repo (แคตตาล็อกจำได้ทีละ repo ต่อ 1 package)
- ถ้ามี `antiFeatures` (เช่น NonFreeNet) ให้แจ้งผู้ใช้ในโพสต์ด้วย
- `scan_malicious_count` 1–2 จาก VirusTotal มักเป็นการเตือนผิดของแอปโอเพนซอร์ส แต่ควรเปิดดูเองก่อนโพสต์

---

## โฟลเดอร์ `work\`

| โฟลเดอร์/ไฟล์ | ใช้ทำอะไร |
|---|---|
| `cache\` | ดัชนี F-Droid (โหลดใหม่เฉพาะเมื่อมีการเปลี่ยนแปลง) |
| `downloads\` | ไฟล์ระหว่างดาวน์โหลด (ลบเองหลังอัปโหลด) |
| `quarantine\` | ไฟล์ติดไวรัส (เฉพาะเมื่อ `keep_quarantine = true`) |
| `logs\foss-mirror.log` | บันทึกการทำงานละเอียด |
| `catalog.json`, `catalog.csv` | แคตตาล็อก |

---

## แก้ปัญหา

| อาการ | วิธีแก้ |
|---|---|
| `ClamAV (clamscan) not found` | ติดตั้ง ClamAV แล้วตั้ง `clamscan_path` ให้ถูก (ดูขั้นตอนที่ 2) |
| `scan error: clamscan error (exit 2)` | มักเป็นเพราะยังไม่มีฐานไวรัส → รัน `freshclam.exe` แบบ administrator |
| `Missing R2 settings` | ยังไม่ได้ใส่ค่า `[r2]` ใน config.ini หรือ secrets |
| `Cannot access the destination bucket` (`AccessDenied` / `SignatureDoesNotMatch` / `InvalidAccessKeyId`) | Account ID / Access Key / Secret ผิด หรือ token ไม่มีสิทธิ์ Object Read & Write ใน bucket `files` |
| `config.ini not found` ทั้งที่สร้างแล้ว | ไฟล์อาจชื่อ `config.ini.ini` หรือ `config.ini.txt` — เปิด View > File name extensions แล้วเปลี่ยนชื่อ |
| `config.ini is malformed` / `not saved as UTF-8` | มีค่าเดียวกันซ้ำสองบรรทัด หรือบันทึกผิด encoding — แก้บรรทัดที่บอก แล้ว Save as แบบ UTF-8 |
| `--scan none is refused for R2` | ติดตั้ง ClamAV ก่อน — `--scan none` ใช้ได้กับ `--dest local` (ทดสอบ) เท่านั้น |
| `scan error: clamscan could not scan the whole file` | ไฟล์ใหญ่/ซับซ้อนเกินขีดจำกัดของ ClamAV — เพิ่มค่าใน `clamscan_args` (เช่น `--max-scantime`, `--max-files=50000`) |
| `Repo URL must start with https://` | ใส่ `repo` ให้ขึ้นต้นด้วย `https://` |
| สถานะ `conflict` | มีไฟล์ชื่อเดียวกันใน bucket อยู่แล้ว (เช่นไฟล์ที่อัปเอง) — โปรแกรมไม่เขียนทับ ตรวจดูเอง หรือเปลี่ยน `filename_template` |
| สถานะ `failed` + `sha256 mismatch` | ไฟล์จาก repo หลักไม่ตรงกับที่ประกาศ (ถ้ามิเรอร์ให้ไฟล์ผิด โปรแกรมจะลอง repo หลักให้เอง) — รอบหน้าลองใหม่ |
| `Not enough disk space` | ลบไฟล์ในเครื่อง หรือเปลี่ยน `work_dir` ไปไดรฟ์ที่ว่างมากกว่า |
| ภาษาไทยในหน้าจอเป็นตัวแปลก | ใช้ `run_windows.bat` (ตั้ง UTF-8 ให้แล้ว) หรือพิมพ์ `chcp 65001` ก่อนรัน |
| เขียน `catalog.csv` ไม่ได้ / `File error` | ปิดไฟล์ใน Excel ก่อน แล้วรัน `run_windows.bat catalog` (หรือคำสั่งเดิม) ใหม่ |
| VirusTotal ช้ามาก | บัญชีฟรีได้ 4 ครั้ง/นาที — ปกติ; ลด `--limit` หรือใช้ ClamAV อย่างเดียว |
| อยากเริ่มใหม่หมด | ลบโฟลเดอร์ `work\` (ไฟล์ใน R2 ไม่ถูกลบ และแคตตาล็อกใน bucket จะถูกโหลดกลับมา) |
| ติดตั้งแพ็กเกจใหม่ | ลบโฟลเดอร์ `.venv\` แล้วรัน `run_windows.bat` ใหม่ |

---

## สำหรับนักพัฒนา: รันเทสต์

เทสต์ทำงานแบบออฟไลน์ทั้งหมด (จำลองคลัง F-Droid ด้วยเซิร์ฟเวอร์ในเครื่อง, จำลอง R2 และ VirusTotal):

```
pip install -r requirements.txt pytest
python -m pytest -q tests
```
