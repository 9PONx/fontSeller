# FontSeller — ร้านขายชุดฟอนต์ 100 บาท

เว็บ PHP ธรรมดา (ไม่มี framework, ไม่ต้องใช้ Composer) เก็บข้อมูลเป็นไฟล์ JSON ทั้งหมด
แสดงตัวอย่างฟอนต์จาก `C:\Windows\Fonts` ด้วย GD และขายชุด ZIP ราคา 100 บาท ผ่าน InwCloud (PromptPay / TrueMoney)

## โครงสร้าง

```
FontSeller/
|-- bin/scan-fonts.php      # สร้าง index ฟอนต์ลง storage/data/fonts.json
|-- inc/                    # โค้ด PHP ธรรมดา (functions)
|   |-- bootstrap.php       # รวมไฟล์ทั้งหมด + ตรวจ .env
|   |-- config.php          # โหลด .env
|   |-- storage.php         # อ่าน/เขียน JSON
|   |-- fonts.php           # ค้นหา + สแกนฟอนต์
|   |-- orders.php          # คำสั่งซื้อ
|   |-- payments.php        # InwCloud + demo mode
|   |-- preview.php         # สร้างภาพตัวอย่างฟอนต์ (GD)
|   |-- downloads.php       # โทเคนดาวน์โหลด
|   `-- layout.php          # header/footer
|-- public/                 # web root เท่านั้นที่เข้าเว็บได้
|   |-- index.php           # landing page: จำนวนฟอนต์ + รายชื่อทั้งหมด
|   |-- checkout.php        # ฟอร์มสั่งซื้อ
|   |-- pay.php             # หน้าชำระเงิน (PromptPay QR / TrueMoney)
|   |-- success.php         # หน้าดาวน์โหลดหลังชำระสำเร็จ
|   |-- download.php        # สตรีมไฟล์ ZIP
|   |-- api/preview.php     # API รูปตัวอย่าง (PNG)
|   |-- api/status.php      # API ตรวจสถานะ PromptPay
|   |-- api/truemoney.php   # API แลกซอง TrueMoney
|   `-- assets/app.js       # โหลดตัวอย่างฟอนต์ + poll สถานะ
|-- storage/
|   |-- data/               # fonts.json, orders.json, ...
|   |-- cache/previews/     # ภาพตัวอย่างที่ cache ไว้
|   `-- private/products/all-fonts.zip   # ไฟล์ขาย (เจ้าของวางเอง)
`-- .env
```

## ติดตั้ง

1. คัดลอก `.env.example` → `.env` แล้วตั้งค่า
   - `APP_KEY` = 64 ตัวอักษร hex (เช่น `php -r "echo bin2hex(random_bytes(32));"`)
   - `INWCLOUD_API_KEY` = คีย์จริงจาก InwCloud
   - `STORAGE_PATH` = ไว้ที่โฟลเดอร์ `storage/data`

2. สร้าง index ฟอนต์ (รันทุกครั้งที่ฟอนต์เปลี่ยน):

```bash
php bin/scan-fonts.php
# ผลลัพธ์: seen=3625 inserted=3625 ... supported=3406 unsupported=219 errors=0
```

3. สร้างไฟล์สินค้า (ZIP ชุดฟอนต์) จากฟอนต์ที่สแกนไว้:

```bash
php bin/build-zip.php            # สร้างถ้ายังไม่มี
php bin/build-zip.php --force    # สร้างใหม่ทุกครั้ง (ควรทำหลัง scan ฟอนต์ใหม่)
# ผลลัพธ์: added=3406 skipped=0 size=454.3 MB stored at: ...\storage\private\products\all-fonts.zip
```

   ต้องการ ext/zip (ZipArchive) — เปิดใน php.ini: `extension=zip`
   (ถ้าอยากวาง ZIP เองก็ได้: ใส่ไฟล์ที่ `storage/private/products/all-fonts.zip`)

4. ชี้ document root ของ Laragon ไปที่ `C:\laragon\www\FontSeller\public`

5. ทดสอบ: `php -S 127.0.0.1:8080 -t public`

## การขึ้น Production

- `APP_ENV=production`, `APP_SESSION_SECURE=true`, `APP_URL=https://...` (HTTPS)
- ตั้ง `APP_KEY` 64 ตัวอักษร hex: `php -r "echo bin2hex(random_bytes(32));"`
- ใส่ `INWCLOUD_API_KEY` จริงจาก InwCloud
- ตรวจสอบว่า `INWCLOUD_QR_IMAGE_HOST=api.qrserver.com` ตรงกับ host ของ QR ที่ InwCloud คืนมา
- ทดสอบ PromptPay 100 บาทจริง และ TrueMoney 100 บาทจริง อย่างละ 1 ครั้งก่อนเปิด
- ระบบจะปลดล็อกไฟล์เมื่อ InwCloud ยืนยันยอด **10000 สตางค์ (100 บาท) พอดี** เท่านั้น

## ฟีเจอร์

- Landing page แสดงจำนวนฟอนต์ทั้งหมด (TTF/OTF) แยกตามรูปแบบ + รายชื่อฟอนต์แบบ plain text
- ฟอนต์ที่สแกน: เฉพาะ TTF/OTF อยู่ในชุดขาย, TTC/FON ไม่รองรับ
- ชำระเงิน PromptPay QR หรือ TrueMoney Wallet ราคา 100 บาทพอดี
- ดาวน์โหลด ZIP ได้ 3 ครั้งภายใน 24 ชั่วโมง (เก็บแค่ hash ของโทเคน)
- เมื่อฟอนต์เปลี่ยน ให้รัน `php bin/scan-fonts.php && php bin/build-zip.php --force` (build เขียนไฟล์ชั่วคราวแล้ว rename ทับแบบ atomic — ลูกค้าที่ดาวน์โหลดอยู่ไม่โดนไฟล์ครึ่งๆ กลางๆ)
- ถ้า session หายหลังจ่ายเงิน (เปิดลิงก์ในเครื่องอื่น/ล้างคุกกี้) หน้ายืนยันจะออกลิงก์ใหม่ให้อัตโนมัติ โดยลิงก์เก่าจะใช้ไม่ได้
- ไฟล์ `.env`, ฟอนต์ต้นฉบับ, ZIP ไม่ถูกเข้าถึงผ่านเว็บได้
