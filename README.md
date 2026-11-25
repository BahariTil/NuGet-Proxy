# NuGet Proxy

A lightweight PHP-based NuGet V3 reverse proxy designed for shared hosting environments.

---

## فارسی

### معرفی

**NuGet Proxy** یک پراکسی معکوس سبک مبتنی بر PHP است که امکان دسترسی به سرویس NuGet V3 و دریافت پکیج‌های NuGet را از طریق یک سرور واسط فراهم می‌کند.

این پروژه برای محیط‌هایی مانند هاست اشتراکی طراحی شده و به نصب Node.js، .NET یا راه‌اندازی VPS نیاز ندارد.

### قابلیت‌ها

- پشتیبانی از NuGet V3 Service Index
- پشتیبانی از جستجوی پکیج‌ها و مشاهده نسخه‌ها
- دریافت و دانلود پکیج‌های NuGet
- بازنویسی آدرس‌های upstream برای عبور درخواست‌ها از پراکسی
- پشتیبانی از مسیرهای مختلف منابع NuGet
- سیستم Cache برای کاهش درخواست‌های تکراری
- محدودسازی دسترسی با Access Token
- سازگار با PHP 8.1 و بالاتر
- قابل اجرا روی هاست‌های اشتراکی دارای cURL

### پیش‌نیازها

- PHP 8.1 یا بالاتر
- فعال بودن PHP cURL
- فعال بودن قابلیت URL rewriting در وب‌سرور
- دسترسی HTTPS به NuGet.org
- امکان نوشتن در پوشه Cache

### نصب و راه‌اندازی

1. فایل‌های پروژه را روی هاست خود آپلود کنید.
2. فایل `config.example.php` را به `config.php` تغییر نام دهید.
3. مقدار `access_token` را در فایل تنظیمات وارد کنید.
4. تنظیمات Cache و محدودیت حجم فایل‌ها را متناسب با نیاز خود تعیین کنید.
5. مطمئن شوید پوشه Cache قابل نوشتن است.
6. آدرس Service Index را در مرورگر بررسی کنید.

**نکته مهم:** پروژه می‌تواند مستقیماً در ریشه وب‌سایت یا داخل هر زیرپوشه دلخواه نصب شود. استفاده از پوشه‌ای با نام `nuget-proxy` الزامی نیست. آدرس نهایی سرویس به محل نصب و تنظیمات وب‌سرور بستگی دارد.

### تنظیمات

نمونه تنظیمات:

```php
<?php
declare(strict_types=1);

return [
    'access_token' => 'YOUR_SECRET_TOKEN',
    'cache_limit_bytes' => 200 * 1024 * 1024,
    'max_package_bytes' => 100 * 1024 * 1024,
    'max_json_bytes' => 20 * 1024 * 1024,
];
```

مقادیر بالا نمونه هستند و می‌توانند متناسب با منابع هاست تغییر کنند.

### استفاده در NuGet

پس از نصب، آدرس Service Index را به‌عنوان Package Source در Visual Studio یا NuGet CLI اضافه کنید.

نمونه آدرس:

```text
https://YOUR-DOMAIN/YOUR-OPTIONAL-PATH/YOUR_SECRET_TOKEN/v3/index.json
```

در صورتی که پروژه مستقیماً در ریشه وب‌سایت نصب شده باشد، بخش `YOUR-OPTIONAL-PATH` حذف می‌شود.

برای NuGet CLI:

```powershell
dotnet nuget add source "https://YOUR-DOMAIN/YOUR-OPTIONAL-PATH/YOUR_SECRET_TOKEN/v3/index.json" --name "NuGet Proxy"
```

### بررسی وضعیت سرویس

برای بررسی عملکرد پراکسی، endpoint مربوط به Health را باز کنید:

```text
https://YOUR-DOMAIN/YOUR-OPTIONAL-PATH/YOUR_SECRET_TOKEN/health
```

### امنیت

- از یک Access Token طولانی و تصادفی استفاده کنید.
- فایل `config.php` را در مخزن عمومی GitHub قرار ندهید.
- فایل‌های Cache و Log را از دسترس عمومی محافظت کنید.
- از HTTPS استفاده کنید.
- توکن خود را در اختیار افراد غیرمجاز قرار ندهید.

### محدودیت‌ها

- عملکرد پراکسی به محدودیت‌های منابع هاست و تنظیمات PHP وابسته است.
- سرعت دانلود به کیفیت اتصال هاست به NuGet.org بستگی دارد.
- محدودیت حجم فایل و زمان اجرای درخواست‌ها ممکن است توسط شرکت میزبان تعیین شود.

---

## English

### Overview

**NuGet Proxy** is a lightweight PHP-based reverse proxy that provides access to the NuGet V3 service and package downloads through an intermediate server.

It is designed for shared hosting environments and does not require Node.js, .NET, or a VPS.

### Features

- NuGet V3 Service Index support
- Package search and version listing
- NuGet package downloads
- Upstream URL rewriting
- Support for NuGet resource paths
- Caching to reduce repeated requests
- Access Token-based authentication
- Compatible with PHP 8.1+
- Suitable for shared hosting environments with cURL support

### Requirements

- PHP 8.1 or higher
- PHP cURL extension enabled
- URL rewriting support on the web server
- HTTPS access to NuGet.org
- Writable cache directory

### Installation

1. Upload the project files to your hosting account.
2. Rename `config.example.php` to `config.php`.
3. Set your `access_token` in the configuration file.
4. Configure cache and file size limits according to your hosting resources.
5. Make sure the cache directory is writable.
6. Open the Service Index URL to verify the installation.

**Important:** The project can be deployed directly in the website's document root or inside any custom subdirectory. A directory named `nuget-proxy` is not required. The final service URL depends on your deployment location and web server configuration.

### Configuration

Example configuration:

```php
<?php
declare(strict_types=1);

return [
    'access_token' => 'YOUR_SECRET_TOKEN',
    'cache_limit_bytes' => 200 * 1024 * 1024,
    'max_package_bytes' => 100 * 1024 * 1024,
    'max_json_bytes' => 20 * 1024 * 1024,
];
```

These values are examples and can be adjusted according to your hosting resources.

### Using with NuGet

After deployment, add the Service Index URL as a Package Source in Visual Studio or NuGet CLI.

Example URL:

```text
https://YOUR-DOMAIN/YOUR-OPTIONAL-PATH/YOUR_SECRET_TOKEN/v3/index.json
```

If the project is deployed directly in the website's document root, omit `YOUR-OPTIONAL-PATH`.

Using NuGet CLI:

```powershell
dotnet nuget add source "https://YOUR-DOMAIN/YOUR-OPTIONAL-PATH/YOUR_SECRET_TOKEN/v3/index.json" --name "NuGet Proxy"
```

### Health Check

To verify the proxy status, open the Health endpoint:

```text
https://YOUR-DOMAIN/YOUR-OPTIONAL-PATH/YOUR_SECRET_TOKEN/health
```

### Security

- Use a long, randomly generated Access Token.
- Never commit `config.php` to a public GitHub repository.
- Protect cache and log files from public access.
- Always use HTTPS.
- Do not share your access token with unauthorized users.

### Limitations

- Proxy performance depends on hosting resources and PHP configuration.
- Download speed depends on the hosting provider's connection to NuGet.org.
- Package size and request execution time may be limited by the hosting provider.

---

## License

Choose and specify a license before publishing this project.

