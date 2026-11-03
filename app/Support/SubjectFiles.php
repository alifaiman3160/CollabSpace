<?php

namespace App\Support;

use App\Models\Enrolment;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\SubjectFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * The lecturer's files for a subject: what may be uploaded, how it is stored, and who may read it.
 *
 * The files live on the private disk (storage/app/private, never web-served) under subject-files/{subject}/, with a
 * random stored name. A file is accepted only when its extension is one we allow AND what is inside really is that
 * kind of file: a PHP or exe file renamed to .pdf is refused. Reading goes through one authorised route only.
 */
class SubjectFiles
{
    public const DISK = 'local';

    public const MAX_KB = 10240;          // 10 MB

    public const MAX_PER_SUBJECT = 10;

    /** extension => the type we store and send it as, and how its contents are checked. */
    public const TYPES = [
        'pdf' => ['mime' => 'application/pdf', 'check' => 'pdf'],
        'doc' => ['mime' => 'application/msword', 'check' => 'ole'],
        'ppt' => ['mime' => 'application/vnd.ms-powerpoint', 'check' => 'ole'],
        'xls' => ['mime' => 'application/vnd.ms-excel', 'check' => 'ole'],
        'docx' => ['mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'check' => 'ooxml', 'part' => 'word/'],
        'pptx' => ['mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'check' => 'ooxml', 'part' => 'ppt/'],
        'xlsx' => ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'check' => 'ooxml', 'part' => 'xl/'],
    ];

    /** What the content sniffer may call the older Office formats (they are OLE containers). */
    protected const OLE_MIMES = [
        'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'application/vnd.ms-office',
        'application/x-ole-storage', 'application/CDFV2', 'application/CDFV2-corrupt', 'application/CDFV2-encrypted',
    ];

    /** What it may call an Office Open XML file (a zip inside). */
    protected const ZIP_MIMES = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];

    public static function allowedExtensions(): array
    {
        return array_keys(self::TYPES);
    }

    public static function directory(Subject|int $subject): string
    {
        return 'subject-files/'.($subject instanceof Subject ? $subject->getKey() : $subject);
    }

    /**
     * The canonical type of the file when it really is what its extension says, or null when it is not allowed or
     * does not match. Never trusts the browser's own claim about the type.
     */
    public static function verify(UploadedFile $file): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $type = self::TYPES[$extension] ?? null;
        $path = $file->getRealPath();

        if ($type === null || $path === false || ! is_file($path)) {
            return null;
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        $head = (string) file_get_contents($path, false, null, 0, 8);

        $ok = match ($type['check']) {
            'pdf' => $detected === 'application/pdf' && str_starts_with($head, '%PDF-'),
            'ole' => $head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" && in_array($detected, self::OLE_MIMES, true),
            'ooxml' => in_array($detected, [$type['mime'], ...self::ZIP_MIMES], true) && self::zipHasPart($path, $type['part']),
            default => false,
        };

        return $ok ? $type['mime'] : null;
    }

    /** A real Office Open XML package: it has [Content_Types].xml and a part under word/, ppt/ or xl/. */
    protected static function zipHasPart(string $path, string $prefix): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        $found = false;

        if ($zip->locateName('[Content_Types].xml') !== false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_starts_with((string) $zip->getNameIndex($i), $prefix)) {
                    $found = true;

                    break;
                }
            }
        }

        $zip->close();

        return $found;
    }

    /** Stores a verified file and records it. */
    public static function store(Subject $subject, UploadedFile $file, string $mime, string $title, string $kind, User $by, ?int $assessmentId = null, array $targetIds = []): SubjectFile
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $name = Str::random(40).'.'.$extension;

        Storage::disk(self::DISK)->putFileAs(self::directory($subject), $file, $name);

        $created = $subject->files()->create([
            'assessment_id' => $assessmentId,
            'uploaded_by' => $by->getKey(),
            'kind' => $kind,
            'title' => $title,
            'original_name' => self::safeName($file->getClientOriginalName(), $extension),
            'stored_path' => self::directory($subject).'/'.$name,
            'mime' => $mime,
            'size' => $file->getSize(),
        ]);

        // A shared file may be shown under only some of the assessments; none chosen means all of them.
        if ($assessmentId === null && $targetIds !== []) {
            $created->targets()->sync($targetIds);
        }

        return $created;
    }

    /** Removes the row and the file; a file already missing from the disk is not an error. */
    public static function delete(SubjectFile $file): void
    {
        $disk = Storage::disk(self::DISK);

        if ($disk->exists($file->stored_path)) {
            $disk->delete($file->stored_path);
        }

        $file->delete();
    }

    public static function deleteAll(Subject $subject): void
    {
        Storage::disk(self::DISK)->deleteDirectory(self::directory($subject));
    }

    /**
     * A name that is safe to show and to send as the download name: no path separators or control characters, nothing
     * that could break out of a header, never longer than 150 characters, and ending in the real extension.
     */
    public static function safeName(string $name, string $extension): string
    {
        $extension = strtolower($extension);
        $base = pathinfo(str_replace('\\', '/', $name), PATHINFO_FILENAME);
        $base = preg_replace('/[\x00-\x1F\x7F]/u', '', $base) ?? '';
        $base = preg_replace('#[/\\\\:*?"<>|%;\']#u', '_', $base) ?? '';
        $base = trim((string) preg_replace('/\s+/u', ' ', $base), " ._");
        $base = Str::limit($base, 140, '');

        return ($base === '' ? 'file' : $base).'.'.$extension;
    }

    /** Whether this person may read the subject's files: the lecturer who owns it, or a student enrolled in it or in one of its groups. */
    public static function canRead(User $user, Subject $subject): bool
    {
        return match ($user->role) {
            'lecturer' => $subject->lecturer_id === $user->getKey(),
            'student' => Enrolment::query()->where('user_id', $user->getKey())->where('subject_id', $subject->getKey())->exists()
                || GroupMember::query()->where('user_id', $user->getKey())->whereIn('group_id', $subject->groups()->select('id'))->exists(),
            default => false,
        };
    }
}
