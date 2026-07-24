<?php

namespace Larva\Flysystem\Qiniu;

use Exception;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperationFailed;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Qiniu\Auth;
use Qiniu\Http\Client;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;
use Throwable;

/**
 * 七牛 Kodo 适配器
 */
class QiniuKodoAdapter implements FilesystemAdapter
{
    /**
     * 扩展 MetaData 字段
     * @var string[]
     */
    private const EXTRA_METADATA_FIELDS = [
        'hash',
        'mimeType',
        'type',
        'status',
    ];

    /**
     * @var Auth
     */
    private Auth $auth;

    /**
     * @var BucketManager
     */
    private BucketManager $bucketManager;

    /**
     * @var UploadManager
     */
    private UploadManager $uploadManager;

    /**
     * @var PathPrefixer
     */
    private PathPrefixer $prefixer;

    /**
     * @var string
     */
    private string $bucket;

    /**
     * @var string
     */
    private string $domain;

    /**
     * @var VisibilityConverter
     */
    private VisibilityConverter $visibility;

    /**
     * @var MimeTypeDetector
     */
    private MimeTypeDetector $mimeTypeDetector;

    /**
     * @var array
     */
    private array $options;

    /**
     * Adapter constructor.
     *
     * @param  Auth  $auth
     * @param  string  $bucket
     * @param  string  $domain  绑定的域名（用于下载文件）
     * @param  string  $prefix
     * @param  VisibilityConverter|null  $visibility
     * @param  MimeTypeDetector|null  $mimeTypeDetector
     * @param  array  $options
     */
    public function __construct(
        Auth $auth,
        string $bucket,
        string $domain = '',
        string $prefix = '',
        ?VisibilityConverter $visibility = null,
        ?MimeTypeDetector $mimeTypeDetector = null,
        array $options = []
    ) {
        $this->auth = $auth;
        $this->prefixer = new PathPrefixer($prefix);
        $this->bucketManager = new BucketManager($this->auth);
        $this->uploadManager = new UploadManager();
        $this->bucket = $bucket;
        $this->domain = rtrim($domain, '/');
        $this->visibility = $visibility ?: new PortableVisibilityConverter();
        $this->mimeTypeDetector = $mimeTypeDetector ?: new FinfoMimeTypeDetector();
        $this->options = $options;
    }

    /**
     * 判断文件是否存在
     *
     * @throws UnableToCheckExistence
     * @throws FilesystemException
     */
    public function fileExists(string $path): bool
    {
        try {
            [, $error] = $this->bucketManager->stat($this->bucket, $this->prefixer->prefixPath($path));
            return $error === null;
        } catch (Throwable $exception) {
            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    /**
     * 判断目录是否存在
     *
     * @throws UnableToCheckExistence
     * @throws FilesystemException
     */
    public function directoryExists(string $path): bool
    {
        $prefix = $this->prefixer->prefixDirectoryPath($path);
        try {
            [$result, $error] = $this->bucketManager->listFiles($this->bucket, $prefix, null, 1, '/');
            if ($error !== null) {
                throw UnableToCheckExistence::forLocation($path, new Exception($error->message(), $error->code()));
            }
            return !empty($result['items']) || !empty($result['commonPrefixes']);
        } catch (Throwable $exception) {
            if ($exception instanceof UnableToCheckExistence) {
                throw $exception;
            }
            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    /**
     * 写入文件到对象
     *
     * @throws UnableToWriteFile
     * @throws FilesystemException
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    /**
     * 将流写入对象
     *
     * @param  resource  $contents
     *
     * @throws UnableToWriteFile
     * @throws FilesystemException
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->upload($path, stream_get_contents($contents), $config);
    }

    /**
     * 上传
     *
     * @param  string  $path
     * @param  string  $body
     * @param  Config  $config
     *
     * @throws UnableToWriteFile
     */
    private function upload(string $path, string $body, Config $config): void
    {
        $key = $this->prefixer->prefixPath($path);
        $mimeType = $config->get('mimeType') ?: $this->mimeTypeDetector->detectMimeType($path, $body);
        try {
            $policy = [];
            if ($config->get('callbackUrl')) {
                $policy['callbackUrl'] = $config->get('callbackUrl');
            }
            if ($config->get('callbackBody')) {
                $policy['callbackBody'] = $config->get('callbackBody');
            }
            if ($config->get('callbackBodyType')) {
                $policy['callbackBodyType'] = $config->get('callbackBodyType');
            }
            $token = $this->auth->uploadToken($this->bucket, $key, 3600, $policy);
            [, $error] = $this->uploadManager->put($token, $key, $body, null, $mimeType ?: 'application/octet-stream');
            if ($error !== null) {
                throw new Exception($error->message(), $error->code());
            }
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, '', $exception);
        }
    }

    /**
     * 读取对象
     *
     * @throws UnableToReadFile
     * @throws FilesystemException
     */
    public function read(string $path): string
    {
        try {
            $url = $this->getDownloadUrl($path);
            $response = Client::get($url);
            if (!$response->ok()) {
                throw UnableToReadFile::fromLocation($path, $response->error);
            }
            return $response->body();
        } catch (Throwable $exception) {
            if ($exception instanceof UnableToReadFile) {
                throw $exception;
            }
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * 以流的形式读取对象
     *
     * @return resource
     *
     * @throws UnableToReadFile
     * @throws FilesystemException
     */
    public function readStream(string $path)
    {
        try {
            $url = $this->getDownloadUrl($path);
            $response = Client::get($url);
            if (!$response->ok()) {
                throw UnableToReadFile::fromLocation($path, $response->error);
            }
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, (string) $response->body());
            rewind($stream);
            return $stream;
        } catch (Throwable $exception) {
            if ($exception instanceof UnableToReadFile) {
                throw $exception;
            }
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * 删除对象
     *
     * @throws UnableToDeleteFile
     * @throws FilesystemException
     */
    public function delete(string $path): void
    {
        try {
            [, $error] = $this->bucketManager->delete($this->bucket, $this->prefixer->prefixPath($path));
            if ($error !== null) {
                throw new Exception($error->message(), $error->code());
            }
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, '', $exception);
        }
    }

    /**
     * 删除目录
     *
     * @throws UnableToDeleteDirectory
     * @throws FilesystemException
     */
    public function deleteDirectory(string $path): void
    {
        $prefix = $this->prefixer->prefixDirectoryPath($path);
        try {
            $marker = null;
            do {
                [$result, $error] = $this->bucketManager->listFiles($this->bucket, $prefix, $marker, 1000, null);
                if ($error !== null) {
                    throw new Exception($error->message(), $error->code());
                }
                $items = $result['items'] ?? [];
                if (!empty($items)) {
                    $operations = BucketManager::buildBatchDelete($this->bucket, array_column($items, 'key'));
                    [, $batchError] = $this->bucketManager->batch($operations);
                    if ($batchError !== null) {
                        throw new Exception($batchError->message(), $batchError->code());
                    }
                }
                $marker = $result['marker'] ?? '';
            } while (!empty($marker));
        } catch (Throwable $exception) {
            throw UnableToDeleteDirectory::atLocation($path, '', $exception);
        }
    }

    /**
     * 创建目录
     *
     * @throws UnableToCreateDirectory
     * @throws FilesystemException
     */
    public function createDirectory(string $path, Config $config): void
    {
        $dirPath = rtrim($path, '/') . '/';
        $this->upload($dirPath, '', $config);
    }

    /**
     * 设置对象可见性
     *
     * 七牛云的可见性为 bucket 级别设置，非单文件级别。
     *
     * @throws InvalidVisibilityProvided
     * @throws FilesystemException
     */
    public function setVisibility(string $path, string $visibility): void
    {
        try {
            $acl = $this->visibility->visibilityToAcl($visibility);
            $private = $acl === 'private' ? 1 : 0;
            [, $error] = $this->bucketManager->putBucketAccessMode($this->bucket, $private);
            if ($error !== null) {
                throw new Exception($error->message(), $error->code());
            }
        } catch (Throwable $exception) {
            throw UnableToSetVisibility::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * 获取对象可见性
     *
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function visibility(string $path): FileAttributes
    {
        try {
            [, $error] = $this->bucketManager->bucketInfo($this->bucket);
            if ($error !== null) {
                throw new Exception($error->message(), $error->code());
            }
            return new FileAttributes($path, null, Visibility::PRIVATE);
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::create($path, FileAttributes::ATTRIBUTE_VISIBILITY, $exception->getMessage(), $exception);
        }
    }

    /**
     * 获取文件元数据
     *
     * @param  string  $path
     * @param  string  $type
     * @return FileAttributes
     *
     * @throws UnableToRetrieveMetadata
     */
    private function fetchFileMetadata(string $path, string $type): FileAttributes
    {
        try {
            [$result, $error] = $this->bucketManager->stat($this->bucket, $this->prefixer->prefixPath($path));
            if ($error !== null) {
                throw new Exception($error->message(), $error->code());
            }
            $fileSize = isset($result['fsize']) ? (int) $result['fsize'] : null;
            $mimeType = $result['mimeType'] ?? null;
            $lastModified = isset($result['putTime']) ? (int) ($result['putTime'] / 10000000) : null;
            return new FileAttributes(
                $path,
                $fileSize,
                null,
                $lastModified,
                $mimeType,
                $this->extractExtraMetadata($result)
            );
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::create($path, $type, $exception->getMessage(), $exception);
        }
    }

    /**
     * 导出扩展 Meta Data
     *
     * @param  array  $metadata
     * @return array
     */
    private function extractExtraMetadata(array $metadata): array
    {
        $extracted = [];
        foreach (self::EXTRA_METADATA_FIELDS as $field) {
            if (isset($metadata[$field]) && $metadata[$field] !== '') {
                $extracted[$field] = $metadata[$field];
            }
        }
        return $extracted;
    }

    /**
     * 获取对象 mime type
     *
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function mimeType(string $path): FileAttributes
    {
        $attributes = $this->fetchFileMetadata($path, FileAttributes::ATTRIBUTE_MIME_TYPE);
        if ($attributes->mimeType() === null) {
            throw UnableToRetrieveMetadata::mimeType($path);
        }
        return $attributes;
    }

    /**
     * 获取对象最后修改时间
     *
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function lastModified(string $path): FileAttributes
    {
        $attributes = $this->fetchFileMetadata($path, FileAttributes::ATTRIBUTE_LAST_MODIFIED);
        if ($attributes->lastModified() === null) {
            throw UnableToRetrieveMetadata::lastModified($path);
        }
        return $attributes;
    }

    /**
     * 获取对象大小
     *
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function fileSize(string $path): FileAttributes
    {
        $attributes = $this->fetchFileMetadata($path, FileAttributes::ATTRIBUTE_FILE_SIZE);
        if ($attributes->fileSize() === null) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }
        return $attributes;
    }

    /**
     * 列出对象
     *
     * @return iterable<StorageAttributes>
     *
     * @throws FilesystemException
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = $this->prefixer->prefixDirectoryPath($path);
        $delimiter = $deep ? null : '/';
        $marker = null;
        do {
            [$result, $error] = $this->bucketManager->listFiles($this->bucket, $prefix, $marker, 1000, $delimiter);
            if ($error !== null) {
                throw UnableToListContents::atLocation($path, $deep, new Exception($error->message(), $error->code()));
            }
            // 处理目录
            foreach ($result['commonPrefixes'] ?? [] as $dir) {
                yield new DirectoryAttributes($this->prefixer->stripPrefix($dir));
            }
            // 处理文件
            foreach ($result['items'] ?? [] as $item) {
                $key = $item['key'] ?? '';
                if ($key === '' || $key === $prefix) {
                    continue;
                }
                yield new FileAttributes(
                    $this->prefixer->stripPrefix($key),
                    isset($item['fsize']) ? (int) $item['fsize'] : null,
                    null,
                    isset($item['putTime']) ? (int) ($item['putTime'] / 10000000) : null,
                    $item['mimeType'] ?? null
                );
            }
            $marker = $result['marker'] ?? '';
        } while (!empty($marker));
    }

    /**
     * 移动对象到新位置
     *
     * @throws UnableToMoveFile
     * @throws FilesystemException
     */
    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (FilesystemOperationFailed $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    /**
     * 复制对象到新位置
     *
     * @throws UnableToCopyFile
     * @throws FilesystemException
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            [, $error] = $this->bucketManager->copy(
                $this->bucket,
                $this->prefixer->prefixPath($source),
                $this->bucket,
                $this->prefixer->prefixPath($destination),
                true
            );
            if ($error !== null) {
                throw new Exception($error->message(), $error->code());
            }
        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    /**
     * 获取下载地址
     *
     * @param  string  $path
     * @return string
     */
    private function getDownloadUrl(string $path): string
    {
        $key = $this->prefixer->prefixPath($path);
        $baseUrl = $this->domain . '/' . $key;
        return $this->auth->privateDownloadUrl($baseUrl);
    }

    /**
     * 获取七牛 Auth 实例
     *
     * @return Auth
     */
    public function getAuth(): Auth
    {
        return $this->auth;
    }

    /**
     * 获取 BucketManager 实例
     *
     * @return BucketManager
     */
    public function getBucketManager(): BucketManager
    {
        return $this->bucketManager;
    }

    /**
     * 获取 UploadManager 实例
     *
     * @return UploadManager
     */
    public function getUploadManager(): UploadManager
    {
        return $this->uploadManager;
    }

    /**
     * 获取 bucket 名称
     *
     * @return string
     */
    public function getBucket(): string
    {
        return $this->bucket;
    }

    /**
     * 获取绑定域名
     *
     * @return string
     */
    public function getDomain(): string
    {
        return $this->domain;
    }
}
