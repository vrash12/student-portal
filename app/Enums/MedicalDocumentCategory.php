<?php

namespace App\Enums;

/**
 * Kind of medical document a candidate uploads. Values are also enforced by
 * a CHECK constraint on candidate_medical_documents.category.
 */
enum MedicalDocumentCategory: string
{
    case MedicalCertificate = 'medical_certificate';
    case CheckupFindings = 'checkup_findings';
    case LaboratoryResult = 'laboratory_result';
    case Imaging = 'imaging';
    case Vaccination = 'vaccination';
    case Prescription = 'prescription';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MedicalCertificate => 'Medical Certificate',
            self::CheckupFindings => 'Medical Check-up Findings',
            self::LaboratoryResult => 'Laboratory Result',
            self::Imaging => 'X-ray or Imaging Result',
            self::Vaccination => 'Vaccination Record',
            self::Prescription => 'Prescription',
            self::Other => 'Other Medical Document',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $category): array => ['value' => $category->value, 'label' => $category->label()], self::cases());
    }
}
