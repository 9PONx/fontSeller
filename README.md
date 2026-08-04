# FontSeller — โครงงานร้านชุดฟอนต์ 100 บาท (เวอร์ชัน JavaScript)

โครงงานสาธิตระบบร้านชุดฟอนต์สำหรับส่งอาจารย์ — **รันบน static hosting (GitHub Pages) ได้ทั้งหมด** ไม่ต้องใช้เซิร์ฟเวอร์ PHP และไม่ได้เปิดขายเชิงพาณิชย์จริง

เดิมโปรเจกต์เขียนด้วย PHP (Laragon + GD + InwCloud API) แล้วแปลงเป็น **JavaScript (SPA) ล้วน** ตามโจทย์ "แปลง PHP เป็น JavaScript ให้หมด"

## ทดลองเล่นได้เลย

- **GitHub Pages:** https://9ponx.github.io/fontSeller/
- หรือเปิด `index.html` ผ่าน static server ใดก็ได้ (เช่น `python -m http.server`)

## ฟีเจอร์

| ฟีเจอร์ | เวอร์ชัน PHP (เดิม) | เวอร์ชัน JS (ใหม่) |
|---|---|---|
| หน้าแรก + รายชื่อฟอนต์ 470 ชื่อ + ค้นหา | PHP render + AJAX | client-side จาก `data/fonts.json` ที่สร้างจาก ZIP ชุดเดียวกัน |
| ตัวอย่างฟอนต์ | GD สร้าง PNG จากฟอนต์ Windows | FontFace + ฟอนต์ตัวอย่าง OFL 9 แบบ |
| คำสั่งซื้อ / ข้อมูล | ไฟล์ JSON บนเซิร์ฟเวอร์ | localStorage |
| ชำระเงิน PromptPay QR | InwCloud API (curl) | InwCloud API (fetch, เรียกจริงจาก browser) |
| TrueMoney Wallet | InwCloud API (curl) | InwCloud API (fetch) |
| ดาวน์โหลด ZIP | สตรีม `all-fonts.zip` | ไฟล์จริง `fontseller-open-fonts.zip` 88.86 MiB บน GitHub Pages |
| โทเค็นดาวน์โหลด (24 ชม. / 3 ครั้ง) | ไฟล์ JSON + session | localStorage + token hash |

> **ชุดดาวน์โหลดจริง:** ระบบเผยแพร่เฉพาะ 470 ไฟล์ที่ metadata ระบุสิทธิ์แจกจ่ายแบบ SIL OFL 1.1, Apache 2.0 หรือ Ubuntu Font License 1.0 รวมข้อมูลต้นฉบับ 88.53 MiB และไม่รวมฟอนต์ Windows/Microsoft หรือไฟล์อื่นที่ไม่มีสิทธิ์ชัดเจน ไฟล์ ZIP 88.86 MiB ยังต่ำกว่าขีดจำกัดไฟล์ 100 MiB ของ GitHub ส่วน InwCloud จาก browser อาจถูก CORS บล็อก จึงยังมีโหมดทดลองสำหรับงานสาธิต

## โครงสร้าง

```
FontSeller/
|-- index.html            # SPA shell (hash router: #/, #/checkout, #/pay, #/success)
|-- js/
|   |-- config.js         # ค่าตั้ง: InwCloud API key, ราคา 100฿, ขีดจำกัดดาวน์โหลด
|   |-- store.js          # localStorage store + orders (แปลงจาก storage.php/orders.php)
|   |-- fonts.js          # แคตตาล็อกฟอนต์ + ค้นหา + โหลดฟอนต์ตัวอย่าง (แปลงจาก fonts.php/preview.php)
|   |-- payments.js       # InwCloud client + ตรวจยอด (แปลงจาก payments.php)
|   |-- downloads.js      # โทเค็นดาวน์โหลด + เรียกไฟล์ ZIP แบบ static
|   |-- router.js         # hash router
|   `-- app.js            # views: home/checkout/pay/success (แปลงจาก public/*.php)
|-- data/fonts.json       # แคตตาล็อก 470 ฟอนต์ที่ตรงกับ ZIP
|-- downloads/            # fontseller-open-fonts.zip (88.86 MiB)
|-- scripts/              # generator คัด license + สร้าง ZIP/catalog/manifest
|-- fonts/                # ฟอนต์ตัวอย่างหน้าเว็บ 11 ไฟล์ (แสดง 9 แบบ)
|-- tests/e2e.test.js     # E2E test (Playwright)
|-- docs/inwcloud/        # คู่มือ API ของ InwCloud
`-- .nojekyll             # ให้ GitHub Pages เสิร์ฟไฟล์ตรง ๆ
```

## สร้างชุดฟอนต์ใหม่

ต้องมี Python 3 และ `fontTools` จากนั้นรันบนเครื่องที่มีแหล่งฟอนต์:

```powershell
python scripts/build_open_font_bundle.py --source C:\Windows\Fonts
```

สคริปต์คัดเฉพาะ `.ttf`/`.otf` ที่เป็น SFNT จริงและมี license metadata ใน allowlist ตรวจ SHA-256/manifest/ขนาด ZIP ก่อนแทน `data/fonts.json` และ `downloads/fontseller-open-fonts.zip` แบบ rollback ได้ หาก ZIP มีขนาดตั้งแต่ 100 MiB ขึ้นไป สคริปต์จะหยุดโดยไม่ publish ผลลัพธ์

## ชำระเงินจริง (InwCloud)

- `POST /v1/promptpay/generate` — สร้าง QR PromptPay (ยอด 100฿ + ค่าธรรมเนียมช่องทาง)
- `POST /v1/promptpay/check` — ตรวจสถานะการจ่าย (poll ทุก 5 วินาที)
- `POST /v1/truewallet/redeem` — แลกซองของขวัญ TrueMoney (รับเฉพาะ 100฿ พอดี)

API key อยู่ใน `js/config.js` — เพราะ static site ไม่มีเซิร์ฟเวอร์ซ่อน key ได้ (หากต้องการความปลอดภัยสูง ต้องย้ายไป proxy/server)

## วิธีรันทดสอบ

```powershell
# เปิดเว็บเพื่อทดลองด้วยตนเอง
python -m http.server 8123
# แล้วเปิด http://127.0.0.1:8123/

# E2E เปิด static server พอร์ตว่างและปิดให้เอง (ติดตั้ง Playwright ก่อน)
npm install playwright
node tests/e2e.test.js
```

## License

- ฟอนต์ตัวอย่างใน `/fonts` เป็นฟอนต์โอเพนไลเซนส์สำหรับแสดงผลหน้าเว็บ
- ZIP มีเฉพาะไฟล์ที่ embedded metadata ระบุ SIL OFL 1.1, Apache 2.0 หรือ Ubuntu Font License 1.0; ดูรายละเอียดและ SHA-256 ได้ใน `manifest.json`/`manifest.csv` ภายใน ZIP
