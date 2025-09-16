<?php

namespace Database\Seeders;

use App\Models\Section\Section;
use App\Services\CodeGenerator;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SectionAndSubsectionAndCodesSeerder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sections = [
            [
                'business_id' => 1,
                'name' => 'Cafeteria',
                'description' => 'Section for cafeteria items',
                'user_id' => 1,

                'sub' => [
                    [
                        'name' => 'Millenium Cafe',
                        'description' => 'Subsection for Millenium Cafe items',
                        'user_id' => 1,
                    ]
                ]
            ],
            [
                'business_id' => 1,
                'name' => 'Rooms',
                'description' => 'Hotels Rooms',
                'user_id' => 1,

                'sub' => [
                    [
                        'name' => '1020',
                        'description' => 'Deluxe Room',
                        'user_id' => 1,
                    ],
                    [
                        'name' => '1021',
                        'description' => 'Standard Room',
                        'user_id' => 1,
                    ],
                    [
                        'name' => '1022',
                        'description' => 'Suite Room',
                        'user_id' => 1,
                    ]
                ]

            ]
        ];

        DB::transaction(function () use ($sections) {
            $codeGenerator = (new CodeGenerator());
            foreach ($sections as $sectionData) {
                // Extract subsections and remove the `sub` key from the section data
                $subsections = $sectionData['sub'];
                unset($sectionData['sub']);

                $sectionAttributes = ['name' => $sectionData['name']];
                $sectionValues = $sectionData;

                $section = Section::firstOrCreate($sectionAttributes, $sectionValues);

                foreach ($subsections as $subsectionData) {
                    $subsectionAttributes = ['name' => $subsectionData['name']];
                    $subsectionValues = $subsectionData;

                    $subsection = $section->subs()->firstOrCreate($subsectionAttributes, $subsectionValues);

                    $subsection->code()->firstOrCreate([
                        'code' => $codeGenerator->generate(8),
                    ]);
                }

                $section->code()->firstOrCreate([
                    'code' => $codeGenerator->generate(8),
                ]);
            }
        });

    }
}
