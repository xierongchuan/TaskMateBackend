<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeleteProofFileJob;
use App\Jobs\StoreTaskProofsJob;
use App\Models\TaskProof;
use App\Models\TaskResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Сервис для работы с доказательствами выполнения задач.
 *
 * Файлы хранятся в приватном хранилище и доступны только через
 * подписанные URL с проверкой авторизации.
 */
class TaskProofService
{
    /**
     * Имя диска для хранения файлов.
     */
    private const STORAGE_DISK = 'task_proofs';

    /**
     * Разрешённые расширения файлов.
     */
    private const ALLOWED_EXTENSIONS = [
        // Изображения
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        // Документы
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'odt', 'txt', 'json',
        // Архивы
        'zip', 'tar', '7z',
        // Видео
        'mp4', 'webm', 'mov', 'avi',
    ];

    /**
     * Разрешённые MIME-типы файлов.
     */
    private const ALLOWED_MIME_TYPES = [
        // Изображения
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        // Документы
        'application/pdf',
        'application/msword', // .doc файлы
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx файлы
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx файлы
        'application/vnd.ms-excel', // .xls файлы
        'text/csv',
        'text/plain',
        'application/json',
        'application/vnd.oasis.opendocument.text', // .odt файлы
        // Архивы
        'application/zip',
        'application/x-tar',
        'application/x-7z-compressed',
        'application/x-compressed',
        'application/octet-stream', // Для 7z и некоторых архивов
        // Видео
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'video/x-msvideo',
    ];

    /**
     * Максимальный размер файла по категориям (в байтах).
     */
    private const MAX_SIZE_IMAGE = 5 * 1024 * 1024;      // 5 MB
    private const MAX_SIZE_DOCUMENT = 50 * 1024 * 1024;  // 50 MB
    private const MAX_SIZE_VIDEO = 100 * 1024 * 1024;    // 100 MB

    /**
     * Максимальное количество файлов на один ответ.
     */
    public const MAX_FILES_PER_RESPONSE = 5;

    /**
     * Максимальный общий размер всех файлов (в байтах).
     */
    public const MAX_TOTAL_SIZE = 200 * 1024 * 1024; // 200 MB

    /**
     * Сохранить файл доказательства.
     *
     * @param TaskResponse $response Ответ на задачу
     * @param UploadedFile $file Загружаемый файл
     * @param int $dealershipId ID автосалона
     * @return TaskProof
     * @throws InvalidArgumentException
     */
    public function storeProof(
        TaskResponse $response,
        UploadedFile $file,
        int $dealershipId
    ): TaskProof {
        $this->validateFile($file);

        $path = $this->generateFilePath($response->task_id, $dealershipId);
        $filename = $this->generateFilename($file, $response->user_id);

        $storedPath = $file->storeAs($path, $filename, self::STORAGE_DISK);

        if ($storedPath === false) {
            throw new InvalidArgumentException('Не удалось сохранить файл');
        }

        return TaskProof::create([
            'task_response_id' => $response->id,
            'file_path' => $storedPath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $this->getCorrectMimeType($file),
            'file_size' => $file->getSize(),
        ]);
    }

    /**
     * Сохранить несколько файлов доказательств с транзакцией.
     *
     * При ошибке загрузки любого файла все уже загруженные файлы
     * будут удалены, а транзакция БД откачена.
     *
     * @param TaskResponse $response Ответ на задачу
     * @param array<UploadedFile> $files Массив загружаемых файлов
     * @param int $dealershipId ID автосалона
     * @return array<TaskProof>
     * @throws InvalidArgumentException
     */
    public function storeProofs(
        TaskResponse $response,
        array $files,
        int $dealershipId
    ): array {
        // Проверяем количество файлов
        $existingCount = $response->proofs()->count();
        $newCount = count($files);

        if ($existingCount + $newCount > self::MAX_FILES_PER_RESPONSE) {
            throw new InvalidArgumentException(
                sprintf(
                    'Превышено максимальное количество файлов. Максимум: %d, уже загружено: %d, новых: %d',
                    self::MAX_FILES_PER_RESPONSE,
                    $existingCount,
                    $newCount
                )
            );
        }

        // Проверяем общий размер
        $existingSize = $response->proofs()->sum('file_size');
        $newSize = array_reduce($files, fn ($carry, $file) => $carry + $file->getSize(), 0);

        if ($existingSize + $newSize > self::MAX_TOTAL_SIZE) {
            throw new InvalidArgumentException(
                sprintf(
                    'Превышен максимальный общий размер файлов. Максимум: %d MB',
                    self::MAX_TOTAL_SIZE / 1024 / 1024
                )
            );
        }

        // Предварительная валидация всех файлов перед загрузкой
        foreach ($files as $file) {
            $this->validateFile($file);
        }

        // Загрузка файлов с транзакцией и откатом при ошибке
        $storedPaths = [];
        $proofs = [];

        try {
            DB::beginTransaction();

            foreach ($files as $file) {
                $path = $this->generateFilePath($response->task_id, $dealershipId);
                $filename = $this->generateFilename($file, $response->user_id);

                $storedPath = $file->storeAs($path, $filename, self::STORAGE_DISK);

                if ($storedPath === false) {
                    throw new InvalidArgumentException('Не удалось сохранить файл: ' . $file->getClientOriginalName());
                }

                $storedPaths[] = $storedPath;

                $proofs[] = TaskProof::create([
                    'task_response_id' => $response->id,
                    'file_path' => $storedPath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $this->getCorrectMimeType($file),
                    'file_size' => $file->getSize(),
                ]);
            }

            DB::commit();

            return $proofs;
        } catch (\Throwable $e) {
            DB::rollBack();

            // Удаляем все уже загруженные файлы
            foreach ($storedPaths as $storedPath) {
                Storage::disk(self::STORAGE_DISK)->delete($storedPath);
            }

            throw $e;
        }
    }

    /**
     * Сохранить файлы доказательств асинхронно (через очередь).
     *
     * Валидация выполняется синхронно — ошибки возвращаются сразу.
     * Файлы сохраняются во временное хранилище и передаются в Job.
     *
     * @param TaskResponse $response Ответ на задачу
     * @param array<UploadedFile> $files Массив загружаемых файлов
     * @param int $dealershipId ID автосалона
     * @throws InvalidArgumentException
     */
    public function storeProofsAsync(
        TaskResponse $response,
        array $files,
        int $dealershipId
    ): void {
        // Проверяем количество файлов
        $existingCount = $response->proofs()->count();
        $newCount = count($files);

        if ($existingCount + $newCount > self::MAX_FILES_PER_RESPONSE) {
            throw new InvalidArgumentException(
                sprintf(
                    'Превышено максимальное количество файлов. Максимум: %d, уже загружено: %d, новых: %d',
                    self::MAX_FILES_PER_RESPONSE,
                    $existingCount,
                    $newCount
                )
            );
        }

        // Проверяем общий размер
        $existingSize = $response->proofs()->sum('file_size');
        $newSize = array_reduce($files, fn ($carry, $file) => $carry + $file->getSize(), 0);

        if ($existingSize + $newSize > self::MAX_TOTAL_SIZE) {
            throw new InvalidArgumentException(
                sprintf(
                    'Превышен максимальный общий размер файлов. Максимум: %d MB',
                    self::MAX_TOTAL_SIZE / 1024 / 1024
                )
            );
        }

        // Валидация всех файлов (синхронно — пользователь получает ошибку сразу)
        foreach ($files as $file) {
            $this->validateFile($file);
        }

        // Сохранение во временное хранилище
        $filesData = [];
        foreach ($files as $file) {
            $tempPath = $file->store('temp/proof_uploads');

            if ($tempPath === false) {
                // Очищаем уже сохранённые temp файлы
                foreach ($filesData as $data) {
                    Storage::delete($data['path']);
                }
                throw new InvalidArgumentException('Не удалось сохранить файл во временное хранилище');
            }

            $filesData[] = [
                'path' => $tempPath,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $this->getCorrectMimeType($file),
                'size' => $file->getSize(),
                'user_id' => $response->user_id,
            ];
        }

        // Dispatch job
        StoreTaskProofsJob::dispatch(
            $response->id,
            $filesData,
            $dealershipId,
            $response->task_id
        );
    }

    /**
     * Удалить файл доказательства.
     *
     * @param TaskProof $proof Доказательство для удаления
     */
    public function deleteProof(TaskProof $proof): void
    {
        $filePath = $proof->file_path;
        $proof->delete();
        DeleteProofFileJob::dispatch($filePath, self::STORAGE_DISK);
    }

    /**
     * Удалить все доказательства для ответа.
     *
     * @param TaskResponse $response Ответ на задачу
     */
    public function deleteAllProofs(TaskResponse $response): void
    {
        foreach ($response->proofs as $proof) {
            $this->deleteProof($proof);
        }
    }

    /**
     * Удалить один общий файл задачи (shared_proof).
     *
     * @param \App\Models\TaskSharedProof $proof Общий файл задачи
     */
    public function deleteSharedProof(\App\Models\TaskSharedProof $proof): void
    {
        $filePath = $proof->file_path;
        $proof->delete();
        DeleteProofFileJob::dispatch($filePath, 'local');
    }

    /**
     * Удалить все общие файлы задачи (shared_proofs).
     *
     * Используется при отклонении групповой задачи с complete_for_all.
     *
     * @param \App\Models\Task $task Задача
     */
    public function deleteSharedProofs(\App\Models\Task $task): void
    {
        foreach ($task->sharedProofs as $proof) {
            $this->deleteSharedProof($proof);
        }
    }

    /**
     * Получить полный путь к файлу на диске.
     *
     * @param TaskProof $proof Доказательство
     * @return string|null Путь или null если файл не существует
     */
    public function getFilePath(TaskProof $proof): ?string
    {
        if (!Storage::disk(self::STORAGE_DISK)->exists($proof->file_path)) {
            return null;
        }

        return Storage::disk(self::STORAGE_DISK)->path($proof->file_path);
    }

    /**
     * Проверить существование файла.
     *
     * @param TaskProof $proof Доказательство
     * @return bool
     */
    public function fileExists(TaskProof $proof): bool
    {
        return Storage::disk(self::STORAGE_DISK)->exists($proof->file_path);
    }

    /**
     * Валидация файла.
     *
     * Проверяет расширение, MIME-тип, размер и реальное содержимое файла.
     *
     * @param UploadedFile $file Загружаемый файл
     * @throws InvalidArgumentException
     */
    private function validateFile(UploadedFile $file): void
    {
        // Проверка расширения
        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Недопустимое расширение файла "%s". Разрешены: %s',
                    $extension,
                    implode(', ', self::ALLOWED_EXTENSIONS)
                )
            );
        }

        // СНАЧАЛА получить правильный MIME (с коррекцией для Office документов)
        $mimeType = $this->getCorrectMimeType($file);

        // ПОТОМ проверить MIME-тип
        if ($mimeType && !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Недопустимый тип файла: %s', $mimeType)
            );
        }

        // Проверка реального содержимого файла
        $this->validateFileContent($file, $extension, $mimeType ?? '');

        // Проверка размера в зависимости от типа
        $fileSize = $file->getSize();
        $maxSize = $this->getMaxSizeForFile($mimeType ?? '');

        if ($fileSize > $maxSize) {
            throw new InvalidArgumentException(
                sprintf(
                    'Файл слишком большой (%d MB). Максимальный размер для этого типа: %d MB',
                    round($fileSize / 1024 / 1024, 1),
                    $maxSize / 1024 / 1024
                )
            );
        }
    }

    /**
     * Проверка реального содержимого файла.
     *
     * Защита от загрузки файлов с подменённым расширением.
     *
     * @param UploadedFile $file Загружаемый файл
     * @param string $extension Расширение файла
     * @param string $mimeType MIME-тип файла
     * @throws InvalidArgumentException
     */
    private function validateFileContent(UploadedFile $file, string $extension, string $mimeType): void
    {
        $filePath = $file->getPathname();

        // Проверка изображений через getimagesize
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($extension, $imageExtensions, true)) {
            $imageInfo = @getimagesize($filePath);

            if ($imageInfo === false) {
                throw new InvalidArgumentException(
                    'Файл не является допустимым изображением'
                );
            }

            // Проверяем соответствие типа изображения расширению
            $imageTypeMap = [
                IMAGETYPE_JPEG => ['jpg', 'jpeg'],
                IMAGETYPE_PNG => ['png'],
                IMAGETYPE_GIF => ['gif'],
                IMAGETYPE_WEBP => ['webp'],
            ];

            $detectedType = $imageInfo[2];
            $allowedExtensions = $imageTypeMap[$detectedType] ?? [];

            if (!in_array($extension, $allowedExtensions, true)) {
                throw new InvalidArgumentException(
                    'Расширение файла не соответствует реальному типу изображения'
                );
            }
        }

        // Проверка PDF через magic bytes
        if ($extension === 'pdf') {
            $handle = fopen($filePath, 'rb');
            if ($handle) {
                $header = fread($handle, 4);
                fclose($handle);

                if ($header !== '%PDF') {
                    throw new InvalidArgumentException(
                        'Файл не является допустимым PDF-документом'
                    );
                }
            }
        }

        // Проверка ZIP-архивов через magic bytes
        if ($extension === 'zip') {
            $handle = fopen($filePath, 'rb');
            if ($handle) {
                $header = fread($handle, 4);
                fclose($handle);

                // ZIP magic bytes: PK\x03\x04 или PK\x05\x06 (пустой архив)
                if (substr($header, 0, 2) !== 'PK') {
                    throw new InvalidArgumentException(
                        'Файл не является допустимым ZIP-архивом'
                    );
                }
            }
        }

        // Проверка видео через finfo (более строгая проверка MIME)
        $videoExtensions = ['mp4', 'webm', 'mov', 'avi'];
        if (in_array($extension, $videoExtensions, true)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->file($filePath);

            $videoMimes = [
                'video/mp4',
                'video/webm',
                'video/quicktime',
                'video/x-msvideo',
                'application/octet-stream', // Некоторые видео определяются так
            ];

            if (!in_array($detectedMime, $videoMimes, true)) {
                throw new InvalidArgumentException(
                    sprintf('Файл не является допустимым видео (обнаружен тип: %s)', $detectedMime)
                );
            }
        }
    }

    /**
     * Получить максимальный размер для типа файла.
     */
    private function getMaxSizeForFile(string $mimeType): int
    {
        if (str_starts_with($mimeType, 'image/')) {
            return self::MAX_SIZE_IMAGE;
        }

        if (str_starts_with($mimeType, 'video/')) {
            return self::MAX_SIZE_VIDEO;
        }

        return self::MAX_SIZE_DOCUMENT;
    }

    /**
     * Определить правильный MIME тип на основе расширения файла.
     *
     * Office документы (.docx, .xlsx) являются ZIP-архивами, поэтому getMimeType()
     * может вернуть application/zip. Эта функция исправляет MIME тип на основе расширения.
     *
     * @param UploadedFile $file Загружаемый файл
     * @return string Правильный MIME тип
     */
    private function getCorrectMimeType(UploadedFile $file): string
    {
        $detectedMime = $file->getMimeType() ?? 'application/octet-stream';
        $extension = strtolower($file->getClientOriginalExtension());

        // Карта расширений Office документов к их правильным MIME типам
        $extensionToMime = [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'doc' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'odt' => 'application/vnd.oasis.opendocument.text',
        ];

        // Если обнаружен ZIP, но расширение указывает на Office документ
        if ($detectedMime === 'application/zip' && isset($extensionToMime[$extension])) {
            return $extensionToMime[$extension];
        }

        // Если MIME тип не определён, но расширение известно
        if ($detectedMime === 'application/octet-stream' && isset($extensionToMime[$extension])) {
            return $extensionToMime[$extension];
        }

        return $detectedMime;
    }

    /**
     * Генерация пути для хранения файла.
     */
    private function generateFilePath(int $taskId, int $dealershipId): string
    {
        $date = date('Y/m/d');

        return sprintf(
            'dealerships/%d/tasks/%d/%s',
            $dealershipId,
            $taskId,
            $date
        );
    }

    /**
     * Генерация имени файла.
     */
    private function generateFilename(UploadedFile $file, int $userId): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $timestamp = time();
        $random = bin2hex(random_bytes(8));

        return sprintf('proof_%d_%d_%s.%s', $timestamp, $userId, $random, $extension);
    }

    /**
     * Получить список разрешённых расширений.
     *
     * @return array<string>
     */
    public static function getAllowedExtensions(): array
    {
        return self::ALLOWED_EXTENSIONS;
    }

    /**
     * Получить список разрешённых MIME-типов.
     *
     * @return array<string>
     */
    public static function getAllowedMimeTypes(): array
    {
        return self::ALLOWED_MIME_TYPES;
    }

    /**
     * Получить имя диска хранилища.
     *
     * @return string
     */
    public static function getStorageDisk(): string
    {
        return self::STORAGE_DISK;
    }
}
