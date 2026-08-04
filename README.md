# FontSeller — ร้านขายชุดฟอนต์ 100 บาท (เวอร์ชัน JavaScript)

ร้านขายชุดฟอนต์ไทยและอังกฤษ 100 บาท — **รันบน static hosting (GitHub Pages) ได้ทั้งหมด** ไม่ต้องใช้เซิร์ฟเวอร์ PHP

เดิมโปรเจกต์เขียนด้วย PHP (Laragon + GD + InwCloud API) แล้วแปลงเป็น **JavaScript (SPA) ล้วน** ตามโจทย์ "แปลง PHP เป็น JavaScript ให้หมด"

## ทดลองเล่นได้เลย

- **GitHub Pages:** https://9ponx.github.io/fontSeller/
- หรือเปิด `index.html` ผ่าน static server ใดก็ได้ (เช่น `python -m http.server`)

## ฟีเจอร์

| ฟีเจอร์ | เวอร์ชัน PHP (เดิม) | เวอร์ชัน JS (ใหม่) |
|---|---|---|
| หน้าแรก + รายชื่อฟอนต์ 3,406 ชื่อ + ค้นหา | PHP render + AJAX | client-side จาก `data/fonts.json` |
| ตัวอย่างฟอนต์ | GD สร้าง PNG จากฟอนต์ Windows | FontFace + ฟอนต์ตัวอย่าง OFL 9 แบบ |
| คำสั่งซื้อ / ข้อมูล | ไฟล์ JSON บนเซิร์ฟเวอร์ | localStorage |
| ชำระเงิน PromptPay QR | InwCloud API (curl) | InwCloud API (fetch, เรียกจริงจาก browser) |
| TrueMoney Wallet | InwCloud API (curl) | InwCloud API (fetch) |
| ดาวน์โหลด ZIP | สตรีม `all-fonts.zip` 455MB | สร้าง ZIP ชุดตัวอย่าง 2.4MB ด้วย JSZip |
| โทเค็นดาวน์โหลด (24 ชม. / 3 ครั้ง) | ไฟล์ JSON + session | localStorage + token hash |

> ⚠️ **ข้อจำกัด static host:** ไฟล์ฟอนต์เต็มชุด (455MB จาก `C:\Windows\Fonts`) ไม่สามารถฝังใน GitHub Pages ได้ — หน้าดาวน์โหลดจึงแจก **ชุดตัวอย่างฟรี (OFL/Apache)** แทน ส่วนการเรียก InwCloud API จริงจาก browser อาจถูก CORS บล็อกในบางกรณี ระบบจะมี **โหมดทดลอง (Demo)** ให้กดจำลองยอดชำระได้เสมอ

## โครงสร้าง

```
FontSeller/
|-- index.html            # SPA shell (hash router: #/, #/checkout, #/pay, #/success)
|-- js/
|   |-- config.js         # ค่าตั้ง: InwCloud API key, ราคา 100฿, ขีดจำกัดดาวน์โหลด
|   |-- store.js          # localStorage store + orders (แปลงจาก storage.php/orders.php)
|   |-- fonts.js          # แคตตาล็อกฟอนต์ + ค้นหา + โหลดฟอนต์ตัวอย่าง (แปลงจาก fonts.php/preview.php)
|   |-- payments.js       # InwCloud client + ตรวจยอด (แปลงจาก payments.php)
|   |-- downloads.js      # โทเค็นดาวน์โหลด + สร้าง ZIP (แปลงจาก downloads.php)
|   |-- router.js         # hash router
|   `-- app.js            # views: home/checkout/pay/success (แปลงจาก public/*.php)
|-- data/fonts.json       # แคตตาล็อกฟอนต์ 3,406 ตัว (สแกนจาก C:\Windows\Fonts)
|-- fonts/                # ฟอนต์ตัวอย่างลิขสิทธิ์ฟรี 11 ไฟล์ (OFL/Apache)
|-- tests/e2e.test.js     # E2E test (Playwright)
|-- docs/inwcloud/        # คู่มือ API ของ InwCloud
`-- .nojekyll             # ให้ GitHub Pages เสิร์ฟไฟล์ตรง ๆ
```

## ชำระเงินจริง (InwCloud)

- `POST /v1/promptpay/generate` — สร้าง QR PromptPay (ยอด 100฿ + ค่าธรรมเนียมช่องทาง)
- `POST /v1/promptpay/check` — ตรวจสถานะการจ่าย (poll ทุก 5 วินาที)
- `POST /v1/truewallet/redeem` — แลกซองของขวัญ TrueMoney (รับเฉพาะ 100฿ พอดี)

API key อยู่ใน `js/config.js` — เพราะ static site ไม่มีเซิร์ฟเวอร์ซ่อน key ได้ (หากต้องการความปลอดภัยสูง ต้องย้ายไป proxy/server)

## วิธีรันทดสอบ

```bash
# static server
python -m http.server 8123
# เปิด http://127.0.0.1:8123/

# E2E test (ต้องติดตั้ง playwright: npm i playwright)
NODE_PATH=$(npm root -g) node tests/e2e.test.js
```

## License

- ฟอนต์ตัวอย่างใน `/fonts` เป็นลิขสิทธิ์ฟรี (SIL OFL / Apache 2.0)
- ฟอนต์ทั้งชุดมีสิทธิ์การเผยแพร่ตามที่เจ้าของลิขสิทธิ์อนุญาต
