<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreEquipmentAttachmentRequest;
use App\Http\Resources\Management\EquipmentAttachmentResource;
use App\Models\Equipment;
use App\Models\EquipmentAttachment;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EquipmentAttachmentController extends Controller
{
    private const string DISK = 'local';

    public function store(StoreEquipmentAttachmentRequest $request, Equipment $equipment, FileUploadService $uploads): JsonResponse
    {
        $stored = $uploads->storeDocument($request->file('file'), self::DISK, "equipment-attachments/{$equipment->id}");

        $attachment = new EquipmentAttachment([
            ...$request->safe()->only(['document_type', 'document_no']),
            'original_filename' => mb_substr((string) $stored['original_filename'], 0, 255),
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'mime_type' => $stored['mime_type'],
            'file_size' => $stored['file_size'],
            'file_hash' => $stored['file_hash'],
        ]);
        $attachment->equipment()->associate($equipment);
        $attachment->uploadedBy()->associate($request->user());
        $attachment->save();

        $this->log('equipment.attachment.uploaded', $equipment, $attachment);

        return new EquipmentAttachmentResource($attachment->load('uploadedBy:id,name'))
            ->additional(['message' => 'File attached.'])
            ->response()
            ->setStatusCode(201);
    }

    public function download(Equipment $equipment, EquipmentAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $equipment);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->original_filename, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Equipment $equipment, EquipmentAttachment $attachment): Response
    {
        $this->authorize('update', $equipment);

        $attachment->delete();
        Storage::disk($attachment->disk)->delete($attachment->path);

        $this->log('equipment.attachment.deleted', $equipment, $attachment);

        return response()->noContent();
    }

    private function log(string $event, Equipment $equipment, EquipmentAttachment $attachment): void
    {
        activity()
            ->performedOn($equipment)
            ->event($event)
            ->withProperties([
                'attachment_id' => $attachment->id,
                'document_type' => $attachment->document_type,
                'document_no' => $attachment->document_no,
                'original_filename' => $attachment->original_filename,
                'file_hash' => $attachment->file_hash,
            ])
            ->log($event);
    }
}
