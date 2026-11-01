<?php

namespace App\Enums;

/**
 * One per RPCPPE sheet. Article suggestions follow the division's RPCPPE
 * workbook lists.
 */
enum PropertyCategory: string
{
    case ItEquipment = 'it_equipment';
    case OfficeEquipment = 'office_equipment';
    case FurnitureFixtures = 'furniture_fixtures';
    case SchoolBuilding = 'school_building';
    case OtherStructure = 'other_structure';
    case TleEquipment = 'tle_equipment';
    case ScienceMathEquipment = 'science_math_equipment';
    case SportsMusicEquipment = 'sports_music_equipment';
    case Books = 'books';

    public function label(): string
    {
        return match ($this) {
            self::ItEquipment => 'IT Equipment',
            self::OfficeEquipment => 'Office Equipment',
            self::FurnitureFixtures => 'Furniture and Fixtures',
            self::SchoolBuilding => 'School Buildings',
            self::OtherStructure => 'Other Structures',
            self::TleEquipment => 'TLE Equipment',
            self::ScienceMathEquipment => 'Science and Math Equipment',
            self::SportsMusicEquipment => 'Sports and Music Equipment',
            self::Books => 'Books and Learning Resources',
        };
    }

    /**
     * Items under Equipment::PPE_THRESHOLD go on a separate semi-expendable sheet.
     */
    public function splitsSemiExpendable(): bool
    {
        return in_array($this, [self::ItEquipment, self::OfficeEquipment, self::TleEquipment], true);
    }

    /**
     * @return list<string>
     */
    public function articles(): array
    {
        return match ($this) {
            self::ItEquipment => ['AVR', 'Biometric Scanner', 'CCTV System', 'Desktop Computer', 'External DVD Drive', 'External Hard Drive', 'Interactive Whiteboard', 'Keyboard', 'Laptop Computer', 'Licensed Software', 'Monitor', 'Mouse', 'Multimedia Speaker', 'Netbook', 'Network Hardware (Router, Switch etc.)', 'Pocket WiFi', 'Printer', 'Projector', 'Projector Screen', 'Scanner', 'Television', 'UPS', 'USB Hub', 'Other'],
            self::OfficeEquipment => ['Aircon - Floor Standing', 'Aircon - Split Type', 'Aircon - Window Type', 'Amplifier', 'Camera', 'Duplicator / Risograph', 'Fax Machine', 'Fire Extinguisher', 'Laminator', 'Megaphone', 'Microphone', 'Paper Cutter', 'Photocopier', 'Ring Binder', 'Sound Mixer', 'Sound Speakers', 'Typewriter', 'Video Recorder / Handy Cam', 'Voice Recorder', 'Other'],
            self::FurnitureFixtures => ['2 Seater Desk', '2-Seater Table and Chair Set', '1-Seater Table and Chair Set', 'Blackboard', 'Bookshelf Metal', 'Bookshelf Wood', 'Cabinet Metal', 'Cabinet Plastic', 'Cabinet Wood', 'Computer Chair', 'Computer Table', 'Electric Fan', 'Filing Cabinet (Metal)', 'Filing Cabinet (Wood)', 'Kinder Chair', 'Kinder Table', 'Monoblock Chair', 'Monoblock Table', 'Office Chair', 'Office Table', 'PABX Phone System', 'Refrigerator', 'Sofa Set', 'Student Chair', 'Student Table', 'Tablet Arm Chair (Monoblock)', 'Tablet Arm Chair (Plastic & Steel)', 'Tablet Arm Chair (Wood & Steel)', 'Tablet Arm Chair (Wood)', "Teacher's Chair", "Teacher's Table", "Teacher's Table and Chair Set", 'Vault', 'Whiteboard', 'Other'],
            self::SchoolBuilding => ['1S 1CL', '1S 2CL', '1S 3CL', '1S 4CL', '1S 5CL', '1S 6CL', '1S 9CL', '1S 12CL', '2S 2CL', '2S 4CL', '2S 6CL', '2S 8CL', '2S 9CL', '2S 10CL', '2S 16CL', '2S 24CL', '3S 6CL', '3S 9CL', '4S 12CL', '4S 16CL', '4S 20CL', '4S 24CL', 'Other'],
            self::OtherStructure => ['Comfort Room/s', 'Covered Court', 'Fence', 'Handwashing Area', 'Library', 'Stage', 'Stock Room / Warehouse', 'Other'],
            self::TleEquipment => ['Sewing Machine', 'Stove', 'Oven', 'Refrigerator', 'Cooking Utensils', 'Hand Tools', 'Power Tools', 'Gardening Tools', 'Other'],
            self::ScienceMathEquipment => ['Microscope', 'Science Equipment Package', 'Math Equipment Package', 'Laboratory Glassware', 'Models and Charts', 'Weighing Scale', 'Storage Cabinet', 'Other'],
            self::SportsMusicEquipment => ['Ball', 'Net', 'Sports Uniform', 'Gymnastics Mat', 'Musical Instrument', 'Drum and Lyre Set', 'Sound System', 'Other'],
            self::Books => ['English', 'Filipino', 'Math', 'Science', 'Araling Panlipunan', 'EPP/TLE', 'EsP', 'Music and Arts', 'PE and Health', 'MTB-MLE', 'Kindergarten', 'Other'],
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $category) => ['value' => $category->value, 'label' => $category->label()], self::cases());
    }
}
