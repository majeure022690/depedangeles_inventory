<?php

namespace App\Enums;

enum OfficeType: string
{
    case ElementarySchool = 'Elementary School';
    case HighSchool = 'High School';
    case IntegratedSchool = 'Integrated School';
    case SeniorHighSchool = 'Senior High School';
    case DivisionOffice = 'Division Office';
    case Unit = 'Unit';

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => ['value' => $type->value, 'label' => $type->value], self::cases());
    }
}
