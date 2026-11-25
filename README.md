# NuGet Proxy

**پراکسی اختصاصی NuGet V3 با PHP برای هاست‌های اشتراکی**

یک پراکسی سبک و مستقل برای عبور درخواست‌های NuGet از طریق یک هاست PHP، با هدف دسترسی پایدارتر به مخازن NuGet در محیط‌هایی که دسترسی مستقیم به سرویس‌های NuGet با محدودیت مواجه است.

[English Documentation](#english) · [فارسی](#فارسی)

---

## فارسی

### معرفی

Nexora NuGet V3 Proxy یک پراکسی مبتنی بر PHP است که امکان استفاده از NuGet را از طریق یک سرور واسط فراهم می‌کند.

این پروژه به‌گونه‌ای طراحی شده که روی هاست‌های اشتراکی دارای PHP و cURL نیز قابل اجرا باشد و برای راه‌اندازی آن نیازی به VPS یا سرور اختصاصی نداشته باشید.

### امکانات

- پشتیبانی از NuGet V3 Service Index
- پشتیبانی از Azure Search برای جست‌وجوی پکیج‌ها
- نمایش اطلاعات و نسخه‌های پکیج‌ها
- دریافت فایل‌های `.nupkg`
- بازنویسی آدرس‌های upstream به آدرس پراکسی
- پشتیبانی از مسیرهای Gateway
- احراز هویت با Access Token
- Cache محلی برای فایل‌های دانلودشده
- محدودیت حجم دانلود و پاسخ‌های JSON
- پاک‌سازی Cache بر اساس محدودیت فضای تعریف‌شده
- پشتیبانی از HTTPS و اعتبارسنجی SSL
- مناسب برای هاست‌های اشتراکی cPanel و DirectAdmin

### معماری کلی

```text
Visual Studio / .NET CLI
          |
          v
    NuGet Client
          |
          v
   Nexora NuGet Proxy
          |
          v
    PHP + cURL
          |
          v
      NuGet.org
          |
          v
    Package Cache
```

### پیش‌نیازها

- PHP 8.1 یا بالاتر
- فعال بودن PHP cURL Extension
- فعال بودن HTTPS
- امکان نوشتن فایل در پوشه Cache
- دسترسی خروجی HTTPS از هاست به سرویس‌های NuGet

### نصب

1. فایل‌های پروژه را در یک پوشه روی هاست خود آپلود کنید.
2. یک فایل `config.php` مطابق نمونه موجود در پروژه ایجاد کنید.
3. برای Access Token یک مقدار تصادفی و غیرقابل حدس تعیین کنید.
4. پوشه Cache را ایجاد کنید و دسترسی نوشتن PHP را به آن بدهید.
5. تنظیمات Rewrite و مسیریابی را مطابق فایل‌های پروژه فعال کنید.
6. آدرس Service Index را در NuGet به‌عنوان Package Source اضافه کنید.

### پیکربندی

نمونه ساختار تنظیمات:

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

**نکته امنیتی:** این فایل نمونه است. مقادیر را متناسب با نسخه پروژه خود تنظیم کنید. فایل واقعی حاوی توکن را در GitHub منتشر نکنید.

### افزودن به NuGet

آدرس Package Source باید به Service Index پراکسی اشاره کند:

```text
https://YOUR-DOMAIN/nuget-proxy/YOUR_SECRET_TOKEN/v3/index.json
```

در صورت استفاده از .NET CLI:

```powershell
dotnet nuget add source "https://YOUR-DOMAIN/nuget-proxy/YOUR_SECRET_TOKEN/v3/index.json" --name NexoraProxy
```

بررسی منابع:

```powershell
dotnet nuget list source
```

پاک‌سازی Cache محلی NuGet:

```powershell
dotnet nuget locals http-cache --clear
```

### بررسی سلامت سرویس

در نسخه فعلی، مسیر Health برای بررسی وضعیت سرویس در نظر گرفته شده است:

```text
https://YOUR-DOMAIN/nuget-proxy/YOUR_SECRET_TOKEN/health
```

این مسیر برای بررسی وضعیت اجزای اصلی پراکسی استفاده می‌شود.

### تست Restore

برای بررسی عملکرد پراکسی می‌توانید در پروژه .NET خود اجرا کنید:

```powershell
dotnet restore
```

یا برای یک Solution مشخص:

```powershell
dotnet restore YourSolution.sln
```

### امنیت

- توکن دسترسی را در مخزن عمومی قرار ندهید.
- فایل `config.php` واقعی را در Git منتشر نکنید.
- برای هر نصب از توکن مستقل استفاده کنید.
- ارتباطات را فقط از طریق HTTPS برقرار کنید.
- محدودیت حجم فایل‌ها را متناسب با فضای هاست تنظیم کنید.
- از قرار گرفتن پوشه Cache در معرض دسترسی مستقیم عمومی جلوگیری کنید.
- توکن‌هایی که قبلاً در لاگ‌ها یا تصاویر عمومی منتشر شده‌اند، باید تعویض شوند.

### محدودیت‌ها

- عملکرد پروژه به محدودیت‌های هاست اشتراکی وابسته است.
- سرعت دانلود به اتصال هاست به NuGet.org بستگی دارد.
- فضای Cache محدود به مقدار تنظیم‌شده در `config.php` است.
- این پروژه جایگزین رسمی NuGet نیست؛ یک واسط برای دسترسی به سرویس‌های آن است.

### وضعیت پروژه

پروژه در محیط واقعی روی هاست اشتراکی PHP آزمایش شده است.

موارد آزمایش‌شده:

- دریافت Service Index
- جست‌وجوی Azure Search
- دریافت اطلاعات نسخه‌های پکیج
- دانلود پکیج‌ها
- Restore وابستگی‌های پروژه‌های .NET

Build نهایی و بررسی امنیتی انتشار عمومی باید پیش از اعلام نسخه پایدار تکمیل شوند.

---

## English

### Overview

Nexora NuGet V3 Proxy is a lightweight PHP-based reverse proxy that allows .NET applications to access NuGet services through an intermediate web host.

It is designed to work on shared hosting environments with PHP and cURL, without requiring a VPS or dedicated server.

### Features

- NuGet V3 Service Index support
- Azure Search support
- Package metadata and version discovery
- `.nupkg` package downloads
- Upstream URL rewriting
- Gateway-based request routing
- Access Token authentication
- Local package caching
- Configurable package and JSON size limits
- Cache cleanup based on configured storage limits
- HTTPS and SSL verification
- Compatible with shared cPanel and DirectAdmin hosting environments

### Architecture

```text
Visual Studio / .NET CLI
          |
          v
    NuGet Client
          |
          v
   Nexora NuGet Proxy
          |
          v
    PHP + cURL
          |
          v
      NuGet.org
          |
          v
    Package Cache
```

### Requirements

- PHP 8.1 or newer
- PHP cURL extension
- HTTPS support
- Writable cache directory
- Outbound HTTPS connectivity to NuGet services

### Installation

1. Upload the project files to your hosting account.
2. Create and configure `config.php` using the provided configuration structure.
3. Generate a secure and random access token.
4. Create the cache directory and grant PHP write permissions.
5. Configure URL rewriting and routing according to the project files.
6. Add the proxy Service Index as a NuGet package source.

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

**Security:** Never commit your real configuration file or access token to a public repository.

### Configure NuGet

Use the following Service Index URL:

```text
https://YOUR-DOMAIN/nuget-proxy/YOUR_SECRET_TOKEN/v3/index.json
```

Add the source using the .NET CLI:

```powershell
dotnet nuget add source "https://YOUR-DOMAIN/nuget-proxy/YOUR_SECRET_TOKEN/v3/index.json" --name NexoraProxy
```

List configured sources:

```powershell
dotnet nuget list source
```

Clear the local NuGet HTTP cache:

```powershell
dotnet nuget locals http-cache --clear
```

### Health Check

The current implementation provides a health-check endpoint:

```text
https://YOUR-DOMAIN/nuget-proxy/YOUR_SECRET_TOKEN/health
```

Use it to inspect the status of the proxy components.

### Testing

Run the following command inside a .NET project directory:

```powershell
dotnet restore
```

Or restore a specific solution:

```powershell
dotnet restore YourSolution.sln
```

### Security Considerations

- Never expose your access token in a public repository.
- Keep your real `config.php` out of version control.
- Use a unique token for each deployment.
- Always use HTTPS.
- Configure package size limits according to your hosting resources.
- Prevent direct public access to the cache directory.
- Rotate any token that has been exposed in logs, screenshots, or public repositories.

### Limitations

- Performance depends on shared hosting resource limits.
- Download speed depends on the hosting provider's connectivity to NuGet.org.
- Cache storage is limited by the configured cache size.
- This project is an independent proxy and is not an official NuGet service.

### Project Status

The project has been tested on a PHP shared hosting environment.

Verified functionality includes:

- NuGet Service Index retrieval
- Azure Search requests
- Package version discovery
- Package downloads
- .NET dependency restoration

A final solution build and security review are still required before declaring a stable public release.

---

## License

Choose and add an appropriate open-source license before publishing this project.

