# flysystem-kodo

<p align="center">
    <a href="https://packagist.org/packages/larva/flysystem-kodo"><img src="https://poser.pugx.org/larva/flysystem-kodo/v/stable" alt="Stable Version"></a>
    <a href="https://packagist.org/packages/larva/flysystem-kodo"><img src="https://poser.pugx.org/larva/flysystem-kodo/downloads" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/larva/flysystem-kodo"><img src="https://poser.pugx.org/larva/flysystem-kodo/license" alt="License"></a>
</p>

这是七牛云 Kodo（对象存储）的 [Flysystem](https://flysystem.thephpleague.com/) 适配器，支持 Flysystem v2/v3。

## 环境要求

- PHP >= 8.2
- Composer 2.0+
- Flysystem v2 或 v3
- 七牛云 PHP SDK v7.14+

## 安装

```bash
composer require larva/flysystem-kodo -vv
```

## 基础用法

### 1. 创建七牛 Auth 实例

```php
use Qiniu\Auth;

$auth = new Auth('your-access-key', 'your-secret-key');
```

### 2. 创建适配器

```php
use Larva\Flysystem\Qiniu\QiniuKodoAdapter;
use Larva\Flysystem\Qiniu\PortableVisibilityConverter;

$adapter = new QiniuKodoAdapter(
    auth: $auth,
    bucket: 'your-bucket-name',
    domain: 'https://your-domain.com',        // 绑定的域名（用于下载文件）
    prefix: '',                                // 可选，存储路径前缀
    visibility: new PortableVisibilityConverter(), // 可选，可见性转换器
    mimeTypeDetector: null,                   // 可选，MIME 类型检测器
    options: []                               // 可选，额外选项
);
```

### 3. 配合 Filesystem 使用

```php
use League\Flysystem\Filesystem;

$filesystem = new Filesystem($adapter);

// 写入文件
$filesystem->write('path/to/file.txt', 'file contents');

// 读取文件
$contents = $filesystem->read('path/to/file.txt');

// 检查文件是否存在
$exists = $filesystem->fileExists('path/to/file.txt');

// 删除文件
$filesystem->delete('path/to/file.txt');

// 列出目录内容
foreach ($filesystem->listContents('path/to/dir') as $item) {
    echo $item->path() . PHP_EOL;
}
```

## 可见性控制

适配器通过 `VisibilityConverter` 接口将 Flysystem 的可见性（`public` / `private`）映射为七牛云的访问控制：

| Flysystem 可见性 | 七牛云 ACL |
|------------------|------------|
| `Visibility::PUBLIC` | `public-read` |
| `Visibility::PRIVATE` | `private` |

> **注意**：七牛云的可见性为 bucket 级别设置，非单文件级别。调用 `setVisibility` 会修改整个 bucket 的访问权限。

默认使用 `PortableVisibilityConverter`，你也可以实现 `VisibilityConverter` 接口自定义映射逻辑：

```php
use Larva\Flysystem\Qiniu\VisibilityConverter;
use League\Flysystem\Visibility;

class CustomVisibilityConverter implements VisibilityConverter
{
    public function visibilityToAcl(string $visibility): string
    {
        return $visibility === Visibility::PUBLIC ? 'public-read' : 'private';
    }

    public function aclToVisibility(string $acl): string
    {
        return $acl === 'public-read' ? Visibility::PUBLIC : Visibility::PRIVATE;
    }

    public function defaultForDirectories(): string
    {
        return Visibility::PUBLIC;
    }
}
```

## 上传回调

上传文件时支持七牛云的回调通知配置：

```php
use League\Flysystem\Config;

$filesystem->write('path/to/file.txt', 'contents', new Config([
    'callbackUrl' => 'https://example.com/callback',
    'callbackBody' => '{"key":"$(key)","hash":"$(etag)","fsize":$(fsize)}',
    'callbackBodyType' => 'application/json',
]));
```

## 支持的方法

| 方法 | 说明 |
|------|------|
| `write($path, $contents, $config)` | 写入文件 |
| `writeStream($path, $stream, $config)` | 以流的方式写入文件 |
| `read($path)` | 读取文件内容 |
| `readStream($path)` | 以流的方式读取文件 |
| `fileExists($path)` | 判断文件是否存在 |
| `directoryExists($path)` | 判断目录是否存在 |
| `delete($path)` | 删除文件 |
| `deleteDirectory($path)` | 删除目录（递归删除目录下所有文件） |
| `createDirectory($path, $config)` | 创建目录 |
| `setVisibility($path, $visibility)` | 设置 bucket 可见性 |
| `visibility($path)` | 获取文件可见性 |
| `mimeType($path)` | 获取文件 MIME 类型 |
| `lastModified($path)` | 获取文件最后修改时间 |
| `fileSize($path)` | 获取文件大小 |
| `listContents($path, $deep)` | 列出目录内容 |
| `move($source, $destination, $config)` | 移动文件 |
| `copy($source, $destination, $config)` | 复制文件 |

## Laravel 集成

在 Laravel 项目中，可以通过自定义 Filesystem 驱动的方式集成：

```php
// AppServiceProvider::boot()
use Illuminate\Support\Facades\Storage;
use Larva\Flysystem\Qiniu\QiniuKodoAdapter;
use League\Flysystem\Filesystem;
use Qiniu\Auth;

Storage::extend('qiniu', function ($app, $config) {
    $auth = new Auth($config['access_key'], $config['secret_key']);
    $adapter = new QiniuKodoAdapter(
        auth: $auth,
        bucket: $config['bucket'],
        domain: $config['domain'],
        prefix: $config['prefix'] ?? ''
    );
    return new Filesystem($adapter);
});
```

在 `config/filesystems.php` 中添加磁盘配置：

```php
'qiniu' => [
    'driver' => 'qiniu',
    'access_key' => env('QINIU_ACCESS_KEY'),
    'secret_key' => env('QINIU_SECRET_KEY'),
    'bucket' => env('QINIU_BUCKET'),
    'domain' => env('QINIU_DOMAIN'),
    'prefix' => env('QINIU_PREFIX', ''),
],
```

然后在 `.env` 中配置相应的环境变量：

```env
QINIU_ACCESS_KEY=your-access-key
QINIU_SECRET_KEY=your-secret-key
QINIU_BUCKET=your-bucket-name
QINIU_DOMAIN=https://your-domain.com
QINIU_PREFIX=
```

使用方式：

```php
Storage::disk('qiniu')->put('file.txt', 'contents');
$contents = Storage::disk('qiniu')->get('file.txt');
```

## 获取底层 SDK 实例

如需直接操作七牛云 SDK，可以获取底层实例：

```php
// 获取 Auth 实例
$auth = $adapter->getAuth();

// 获取 BucketManager 实例
$bucketManager = $adapter->getBucketManager();

// 获取 UploadManager 实例
$uploadManager = $adapter->getUploadManager();

// 获取 bucket 名称
$bucket = $adapter->getBucket();

// 获取绑定域名
$domain = $adapter->getDomain();
```

## 贡献

欢迎提交 Issue 和 Pull Request。

## License

[MIT](LICENSE)
