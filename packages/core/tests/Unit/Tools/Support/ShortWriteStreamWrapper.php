<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools\Support;

final class ShortWriteStreamWrapper
{
    public static string $truncateNeedle = '.phpclaw-edit-';

    /** @var resource */
    public $context;

    /** @var resource */
    private $handle;

    private bool $truncate = false;

    private int $writtenSoFar = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $real = self::stripProtocol($path);

        stream_wrapper_restore('file');
        $handle = @fopen($real, $mode);
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);

        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;
        $this->truncate = str_contains(basename($real), self::$truncateNeedle);

        return true;
    }

    public function stream_write(string $data): int
    {
        if ($this->truncate && $this->writtenSoFar >= 5) {
            return 0;
        }

        if ($this->truncate && ($this->writtenSoFar + strlen($data)) > 5) {
            $data = substr($data, 0, 5 - $this->writtenSoFar);
        }

        $written = fwrite($this->handle, $data);
        $written = $written === false ? 0 : $written;

        $this->writtenSoFar += $written;

        return $written;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    public function stream_lock(int $operation): bool
    {
        return $operation === 0 ? true : flock($this->handle, $operation);
    }

    public function stream_tell(): int
    {
        return ftell($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    public function stream_truncate(int $newSize): bool
    {
        return ftruncate($this->handle, $newSize);
    }

    public function url_stat(string $path, int $flags): array|false
    {
        stream_wrapper_restore('file');
        $real = self::stripProtocol($path);
        $result = ($flags & STREAM_URL_STAT_LINK) === STREAM_URL_STAT_LINK ? @lstat($real) : @stat($real);
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);

        return $result;
    }

    public function unlink(string $path): bool
    {
        stream_wrapper_restore('file');
        $result = @unlink(self::stripProtocol($path));
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);

        return $result;
    }

    public function rename(string $pathFrom, string $pathTo): bool
    {
        stream_wrapper_restore('file');
        $result = @rename(self::stripProtocol($pathFrom), self::stripProtocol($pathTo));
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);

        return $result;
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        stream_wrapper_restore('file');
        $result = @mkdir(self::stripProtocol($path), $mode, ($options & STREAM_MKDIR_RECURSIVE) === STREAM_MKDIR_RECURSIVE);
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);

        return $result;
    }

    public static function install(): void
    {
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);
    }

    public static function uninstall(): void
    {
        stream_wrapper_restore('file');
    }

    private static function stripProtocol(string $path): string
    {
        return str_starts_with($path, 'file://') ? substr($path, 7) : $path;
    }
}
