# Tracking Package

## نظرة عامة

باكدج تتبع المشاريع المتبوعة مع نظام تحكم مركزي متقدم.

## المميزات

✅ **تتبع تلقائي** - تتبع المشاريع بدون تدخل  
✅ **تحكم مركزي** - إدارة جميع المشاريع من موقع واحد  
✅ **نسخ احتياطية** - نسخ قواعد البيانات والسورس كود  
✅ **نظام إيقاف** - إيقاف المشاريع وإعادة تفعيلها  
✅ **أمان عالي** - تشفير وإخفاء الكود  
✅ **سهولة الاستخدام** - تثبيت بسيط وتشغيل تلقائي  

## التثبيت

### 1. إضافة الباكدج
```bash
composer require vendor/tracking-package
```

### 2. تسجيل Service Provider
```php
// config/app.php
'providers' => [
    Vendor\TrackingPackage\TrackingServiceProvider::class,
],
```

### 3. تشغيل التتبع
```php
// في أي مكان في الكود
track_project();
```

## الاستخدام

### التتبع الأساسي
```php
// تحديث آخر ظهور
track_project();

// الحصول على حالة المشروع
$status = get_project_status();

// الحصول على قاعدة البيانات
$database = get_project_database();

// الحصول على السورس كود
$source = get_project_source();
```

### التحكم المركزي

#### إلغاء تفعيل المشروع
```bash
POST /api/project/{projectId}/deactivate
```

#### إعادة تفعيل المشروع
```bash
POST /api/project/{projectId}/reactivate
```

#### التحقق من الحالة
```bash
POST /api/check-project-status
{
    "domain": "example.com",
    "activation_code": "ABC12345"
}
```

## نظام الإيقاف

### كيف يعمل:
1. **التتبع التلقائي** - الباكدج يتتبع المشروع تلقائياً
2. **التحقق من الحالة** - يتحقق من حالة المشروع في كل طلب
3. **عرض رسالة الإيقاف** - إذا كان المشروع متوقف، يعرض رسالة جميلة
4. **إدخال كود التفعيل** - المستخدم يدخل كود التفعيل
5. **إعادة التفعيل** - المشروع يعود للعمل

### رسالة الإيقاف:
- تصميم جميل ومتجاوب
- عرض سبب الإيقاف
- نموذج لإدخال كود التفعيل
- أمان عالي ضد التلاعب

## الأمان

### ميزات الأمان:
- **تشفير الكود** - الكود مشفر ومخفي
- **تحقق من الكود** - التحقق يتم من الموقع المركزي
- **تسجيل العمليات** - جميع العمليات مسجلة
- **حماية من التلاعب** - لا يمكن تجاوز النظام

### إعدادات الأمان:
```php
// في ملف .env
TRACKING_BASE_URL=https://your-central-domain.com
TRACKING_TIMEOUT=30
TRACKING_VERIFY_SSL=false
```

## API Endpoints

### المشاريع
- `POST /api/store-project` - تسجيل مشروع جديد
- `POST /api/project-heartbeat` - نبض القلب
- `GET /api/project/{id}/status` - حالة المشروع

### التحكم
- `POST /api/project/{id}/deactivate` - إلغاء التفعيل
- `POST /api/project/{id}/reactivate` - إعادة التفعيل
- `POST /api/check-project-status` - التحقق من الحالة

### النسخ الاحتياطية
- `POST /api/project/{id}/backup-database` - نسخ قاعدة البيانات
- `POST /api/project/{id}/backup-source-code` - نسخ السورس كود
- `GET /api/project/{id}/download-backup/{filename}` - تحميل النسخة

## استكشاف الأخطاء

### المشروع لا يتتبع:
1. تحقق من إعدادات الباكدج
2. تحقق من اتصال الإنترنت
3. تحقق من URL الموقع المركزي

### رسالة خطأ في الاتصال:
1. تحقق من أن الموقع المركزي يعمل
2. تحقق من إعدادات Firewall
3. تحقق من timeout الإعدادات

### المشروع متوقف:
1. تحقق من حالة المشروع في لوحة التحكم
2. أدخل كود التفعيل الصحيح
3. تواصل مع الإدارة إذا لزم الأمر

## الدعم

للمساعدة والدعم التقني:
- البريد الإلكتروني: support@vendor.com
- الوثائق: https://docs.vendor.com/tracking-package
- GitHub: https://github.com/vendor/tracking-package

## الترخيص

هذا الباكدج مرخص تحت رخصة MIT.
