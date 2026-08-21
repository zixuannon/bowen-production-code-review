<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Immutable tenant-to-central contract for a student reference projection.
 * No financial amount, payment, balance, or tenant database name is carried.
 */
final class CentralFinanceStudentProfilePayload
{
    public function __construct(
        public readonly int $schoolId,
        public readonly int $tenantStudentId,
        public readonly string $sourceUuid,
        public readonly ?string $admissionNo,
        public readonly ?string $studentName,
        public readonly ?string $enrollmentStatus,
        public readonly CarbonImmutable $sourceUpdatedAt,
        public readonly ?CarbonImmutable $sourceDeletedAt = null,
        public readonly ?string $correlationId = null,
        public readonly ?int $classId = null,
        public readonly ?int $classSectionId = null,
        public readonly ?string $className = null,
        public readonly ?string $sectionName = null,
        public readonly ?string $guardianName = null,
        public readonly ?string $guardianEmail = null,
        public readonly ?string $guardianMobile = null,
        public readonly ?string $tenantUserStatus = null,
    ) {
        if ($this->schoolId < 1 || $this->tenantStudentId < 1) {
            throw new InvalidArgumentException('Central Finance source identities must be positive.');
        }

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->sourceUuid)) {
            throw new InvalidArgumentException('Central Finance requires a stable UUID source identity.');
        }
    }

    public function sourceVersion(): string
    {
        // Older tenant schemas persist updated_at only to whole seconds. A
        // deterministic content fingerprint preserves idempotency while still
        // detecting two legitimate student/status updates in that second.
        $fingerprint = hash('sha256', json_encode([
            $this->tenantStudentId, strtolower($this->sourceUuid), $this->classId,
            $this->classSectionId, $this->className, $this->sectionName,
            $this->admissionNo, $this->studentName, $this->enrollmentStatus,
            $this->guardianName, $this->guardianEmail, $this->guardianMobile,
            $this->tenantUserStatus, $this->sourceDeletedAt?->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
        ], JSON_THROW_ON_ERROR));

        return $this->sourceUpdatedAt->utc()->format('Y-m-d\\TH:i:s.u\\Z') . '-' . substr($fingerprint, 0, 24);
    }

    /** @return array<string, string|int|null> */
    public function canonical(): array
    {
        return [
            'school_id' => $this->schoolId,
            'tenant_student_id' => $this->tenantStudentId,
            'source_uuid' => strtolower($this->sourceUuid),
            'class_id' => $this->classId,
            'class_section_id' => $this->classSectionId,
            'class_name' => $this->className,
            'section_name' => $this->sectionName,
            'admission_no' => $this->admissionNo,
            'student_name' => $this->studentName,
            'enrollment_status' => $this->enrollmentStatus,
            'guardian_name' => $this->guardianName,
            'guardian_email' => $this->guardianEmail,
            'guardian_mobile' => $this->guardianMobile,
            'tenant_user_status' => $this->tenantUserStatus,
            'source_updated_at' => $this->sourceVersion(),
            'source_deleted_at' => $this->sourceDeletedAt?->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
        ];
    }
}
