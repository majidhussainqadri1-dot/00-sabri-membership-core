# الہامی دور — مستقل حاکم نظرِ ثانی و اصلاحِ نقائص کا اصول

## 1. نام اور حیثیت

اس مستقل QA، forensic review اور defect-remediation doctrine کا رسمی نام **الہامی دور** ہے۔ Sabri Social Homeopathy Platform کی ہر فائل، plugin، module، service، API، schema، workflow، package اور متعلقہ تحریری منصوبے پر یہ اصول اس وقت لازم ہوگا جب متعلقہ coding یا implementation اپنی مقررہ تکمیل تک پہنچ جائے۔

## 2. بنیادی تسلسل

الہامی دور کسی ایک review پر ختم نہیں ہوگا۔ اس کا لازمی تسلسل یہ ہے:

1. مکمل، تازہ اور حتی الامکان adversarial نظرِ ثانی؛
2. سامنے آنے والے ہر ثابت نقص، کمی، اختلاف، regression، security weakness، privacy defect، integration mismatch، naming/versioning conflict، packaging error اور documentation overclaim کی اصلاح؛
3. اصلاح شدہ پورے نظام پر ازسرِنو آزاد نظرِ ثانی؛
4. نئی نظرِ ثانی میں سامنے آنے والے تمام نقائص کی نئی اصلاح؛
5. یہی review → correction → fresh review → correction کا سلسلہ اس وقت تک جاری رہے گا جب تک مقررہ scope میں کوئی معلوم unresolved defect باقی نہ رہے۔

پہلی اصلاحات کو بعد کی نظرِ ثانی سے مستثنیٰ نہیں سمجھا جائے گا۔ ہر دور میں سابقہ اصلاحات، regressions، cross-file effects اور newly exposed defects دوبارہ جانچے جائیں گے۔

## 3. رکنے کی حاکم شرط

الہامی دور صرف اس وقت repository scope میں بند ہوسکتا ہے جب:

- دو مسلسل، الگ اور تازہ whole-system reviews کوئی نیا repository-correctable defect دریافت نہ کریں؛
- تمام سابقہ discovered defects کے regression tests موجود اور کامیاب ہوں؛
- exact-head CI کامیاب ہو؛
- deterministic package، manifest، checksum، syntax، static، runtime اور security/privacy checks کامیاب ہوں؛
- کوئی معلوم unresolved defect، misleading completion claim یا unrecorded exception باقی نہ ہو۔

کسی check کے fail ہونے، نئے defect کے سامنے آنے یا correction سے regression پیدا ہونے پر clean-review count دوبارہ صفر سے شروع ہوگا۔

## 4. زیرو نقص کی صادقانہ تعریف

**زیرو نقص** سے مراد مقررہ repository، automated-test، evidence اور review scope میں **صفر معلوم unresolved defects** ہے؛ یہ مطلق، غیر محدود یا فلسفیانہ عصمت کا دعویٰ نہیں۔

یہ درجہ درج ذیل بیرونی gates کو خود بخود مکمل نہیں کرتا:

- Hostinger staging installation اور legacy upgrade؛
- حقیقی email/mobile/guardian/scanner providers؛
- cross-plugin runtime integration؛
- browser، mobile، RTL، keyboard، screen-reader اور WCAG testing؛
- performance، concurrency، load، backup، restore، rollback، key-loss، disk-full اور disaster-recovery rehearsal؛
- jurisdiction-specific legal/child-safety approval؛
- Founder acceptance، production approval اور live deployment۔

ان gates میں کسی نقص کے ظاہر ہونے پر الہامی دور متعلقہ scope کے لیے دوبارہ کھل جائے گا۔

## 5. لازمی ثبوت

ہر الہامی دور میں کم از کم یہ evidence محفوظ ہوگا:

- review-round number اور scope؛
- discovered defects اور severity؛
- corrected files اور rationale؛
- نئے/تبدیل شدہ regression tests؛
- exact commit SHA، PR اور CI run؛
- deterministic package identity اور SHA-256؛
- pending external gates؛
- truthful release/staging/production classification۔

## 6. نفاذ

یہ اصول File 00 — Sabri Membership Core سے باقاعدہ نافذ کیا گیا ہے اور آئندہ ہر مکمل فائل کے بعد اسی نام اور اسی تسلسل کے ساتھ لاگو ہوگا۔
