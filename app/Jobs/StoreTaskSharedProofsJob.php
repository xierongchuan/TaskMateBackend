<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Task;
use App\Models\TaskSharedProof;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job для асинхронной загрузки общих файлов задачи.
 */
class StoreTaskSharedProofsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_FILES = 5;
    private const MAX_TOTAL_SIZE = 200 * 1024 * 1024; // 200 MB

    private const ALLOWED_MIME_TYPES = [
        // Изображения
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        // Документы
        'application/pdf',
        'application/msword', // .doc
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
        'application/vnd.ms-excel', // .xls
        'text/csv', 'text/plain', 'application/json',
        'application/vnd.oasis.opendocument.text', // .odt
        // Архивы
        'application/zip', 'application/x-tar', 'application/x-7z-compressed',
        'application/x-compressed', 'application/octet-stream',
        // Видео
        'video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo',
    ];

    /**
     * @param int $taskId ID задачи
     * @param array<array{path: string, original_name: string, mime: string, size: int}> $filesData
     * @param int $dealershipId
     */
    public function __construct(
        public readonly int $taskId,
        public readonly array $filesData,
        public readonly int $dealershipId
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $task = Task::find($this->taskId);

        if (!$task) {
            Log::warning('Task not found for shared proofs', ['task_id' => $this->taskId]);
            $this->cleanupTempFiles();
            return;
        }

        // Очистка ghost-записей (файлы не существуют на диске)
        $this->cleanupGhostRecords($task);

        // Проверка лимитов
        $existingCount = $task->sharedProofs()->count();
        $newCount = count($this->filesData);

        if ($existingCount + $newCount > self::MAX_FILES) {
            Log::error('Too many shared proof files', [
                'task_id' => $this->taskId,
                'existing' => $existingCount,
                'new' => $newCount,
                'max' => self::MAX_FILES,
            ]);
            $this->cleanupTempFiles();
            return;
        }

        $storedCount = 0;

        foreach ($this->filesData as $fileData) {
            try {
                $this->storeFile($task, $fileData);
                $storedCount++;
            } catch (\Throwable $e) {
                Log::error('Failed to store shared proof', [
                    'task_id' => $this->taskId,
                    'file' => $fileData['original_name'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Если ни один файл не сохранён, очищаем temp
        if ($storedCount === 0) {
            $this->cleanupTempFiles();
        }

        Log::info('StoreTaskSharedProofsJob completed', [
            'task_id' => $this->taskId,
            'stored' => $storedCount,
            'total' => count($this->filesData),
        ]);
    }

    private function storeFile(Task $task, array $fileData): void
    {
        // Валидация MIME типа
        if (!in_array($fileData['mime'], self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException("Недопустимый тип файла: {$fileData['mime']}");
        }

        // Перемещаем файл из temp в постоянное хранилище
        $extension = pathinfo($fileData['original_name'], PATHINFO_EXTENSION);
        $filename = sprintf(
            'shared_proof_%d_%d_%s.%s',
            time(),
            $task->id,
            bin2hex(random_bytes(8)),
            $extension
        );

        $destinationPath = "task_proofs/{$this->dealershipId}/{$filename}";

        // Копируем из temp
        if (!Storage::move($fileData['path'], $destinationPath)) {
            throw new \RuntimeException("Failed to move file: {$fileData['path']}");
        }

        // Создаем запись
        TaskSharedProof::create([
            'task_id' => $task->id,
            'file_path' => $destinationPath,
            'original_filename' => $fileData['original_name'],
            'mime_type' => $fileData['mime'],
            'file_size' => $fileData['size'],
        ]);
    }

    /**
     * Удалить ghost-записи (DB-записи без файлов на диске).
     */
    private function cleanupGhostRecords(Task $task): void
    {
        $proofs = $task->sharedProofs()->get();

        foreach ($proofs as $proof) {
            if (!Storage::exists($proof->file_path)) {
                Log::warning('Removing ghost shared proof record', [
                    'proof_id' => $proof->id,
                    'task_id' => $task->id,
                    'file_path' => $proof->file_path,
                ]);
                $proof->delete();
            }
        }
    }

    /**
     * Очистить temp-файлы при неуспешной обработке.
     */
    private function cleanupTempFiles(): void
    {
        foreach ($this->filesData as $fileData) {
            if (Storage::exists($fileData['path'])) {
                Storage::delete($fileData['path']);
            }
        }
    }
}
